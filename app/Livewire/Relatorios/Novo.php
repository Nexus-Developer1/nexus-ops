<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoContrato;
use App\Enums\EstadoIntervencao;
use App\Enums\EstadoRelatorio;
use App\Enums\TipoIntervencao;
use App\Jobs\GerarRelatorioPdf;
use App\Models\Anexo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\FichaMedicao;
use App\Models\Intervencao;
use App\Models\Relatorio;
use App\Services\Agenda\GeradorEventoDeRelatorio;
use App\Services\GeradorRelatorio;
use App\Support\FaixaEquipamentos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

// "Relatório de Intervenção Técnica": criar/retomar. Dois modos de gravação —
// Guardar rascunho (valida só o equipamento, sem PDF) e Finalizar (validação
// completa + gera o PDF). Sem auto-save: só grava quando o utilizador carrega.
#[Layout('components.layouts.app', ['ativo' => 'relatorios', 'titulo' => 'Relatório'])]
class Novo extends Component
{
    use WithFileUploads;

    // Edição/retomar (null = novo).
    public ?int $relatorioId = null;
    public ?int $intervencaoId = null;

    // ---- Dados gerais ----
    // Modo: 'individual' (escolhe o CLIENTE → anexa todos os equipamentos dele) ou
    // 'contrato' (equipamentos vêm do contrato).
    public string $modo = 'individual';
    public ?int $contrato_id = null;
    public string $contratoBusca = '';

    // Modo individual: 3 faixas por nº de equipamentos do cliente (auto/lista/pesquisa), para
    // escolher os equipamentos sem montar centenas/milhares de fichas (→ 500). Os limites e a
    // decisão da faixa vivem em App\Support\FaixaEquipamentos (partilhado com o editor de contratos).

    // Modo individual: escolhe-se o cliente e anexam-se os equipamentos dele.
    public ?int $cliente_id = null;
    public string $clienteBusca = '';        // pesquisa server-side do cliente
    // Faixa do fluxo de equipamentos: '' (sem cliente) | 'auto' | 'lista' | 'pesquisa'.
    public string $faixaEquipamentos = '';
    public string $equipamentoBusca = '';    // pesquisa server-side do equipamento (filtrada ao cliente)

    public ?int $equipamento_id = null;      // 1.º equipamento (principal da intervenção)
    /** @var list<int> Restantes equipamentos cobertos pelo mesmo relatório. */
    public array $equipamentosCobertos = [];
    public string $tipo = 'preventiva';
    public string $data = '';
    public string $hora_inicio = '';
    public string $hora_fim = '';

    // ---- Constatações ----
    public string $resumo = '';

    // ---- Fichas de medição (só modo contrato) ----
    // Uma por equipamento coberto, keyed por equipamento_id. Estrutura em FichaMedicao::estruturaVazia().
    public array $fichas = [];

    // ---- Recomendações ----
    public string $recomendacao = '';
    public string $prioridade = 'Normal';

    // ---- Fotos (novos uploads temporários até gravar) ----
    public array $fotos = [];

    public function mount(?Relatorio $relatorio = null): void
    {
        if ($relatorio && $relatorio->exists) {
            // Editáveis: rascunhos e finalizados. Um relatório JÁ ENVIADO não se edita aqui
            // (documento oficial já entregue ao cliente) → volta para a lista.
            if ($relatorio->estado === EstadoRelatorio::Enviado) {
                $this->redirectRoute('relatorios', navigate: true);

                return;
            }

            $intervencao = $relatorio->intervencao()->with('contrato.cliente', 'equipamento.local.cliente')->firstOrFail();
            $this->relatorioId = $relatorio->id;
            $this->intervencaoId = $intervencao->id;
            $this->equipamento_id = $intervencao->equipamento_id;
            $this->equipamentosCobertos = $intervencao->equipamentosCobertos()->pluck('equipamentos.id')->all();

            // Modo deduzido: se a intervenção tem contrato → "contrato", senão "individual".
            $this->contrato_id = $intervencao->contrato_id;
            $this->modo = $intervencao->contrato_id ? 'contrato' : 'individual';
            $this->contratoBusca = $intervencao->contrato
                ? trim($intervencao->contrato->numero . ' · ' . ($intervencao->contrato->cliente?->nome ?? ''))
                : '';

            // Modo individual: mostra no picker o cliente do equipamento principal. Carrega SÓ os
            // equipamentos já gravados (acima); se o cliente for grande, liga a pesquisa para
            // acrescentar mais — mas nunca anexa tudo (senão editar um cliente grande dava 500).
            if ($this->modo === 'individual') {
                $cliente = $intervencao->equipamento?->local?->cliente;
                $this->cliente_id = $cliente?->id;
                $this->clienteBusca = $cliente?->nome ?? '';

                if ($cliente) {
                    $total = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $cliente->id))->count();
                    $this->faixaEquipamentos = FaixaEquipamentos::para($total);
                }
            }
            $this->tipo = $intervencao->tipo->value;
            $this->data = $intervencao->data_inicio?->format('Y-m-d') ?? '';
            $this->hora_inicio = $intervencao->hora_inicio ? substr($intervencao->hora_inicio, 0, 5) : '';
            $this->hora_fim = $intervencao->hora_fim ? substr($intervencao->hora_fim, 0, 5) : '';
            $this->resumo = $intervencao->trabalho_realizado ?? '';
            $this->recomendacao = $intervencao->observacoes ?? '';

            $this->prioridade = ($intervencao->diagnostico['prioridade'] ?? null) ?: 'Normal';

            return;
        }

        // Novo relatório.
        $this->data = now()->format('Y-m-d');
    }

    public function ehRascunho(): bool
    {
        return true; // este formulário só edita rascunhos / cria novos (que nascem rascunho)
    }

    // Alterna entre relatório de contrato e individual.
    public function definirModo(string $modo): void
    {
        $novo = $modo === 'contrato' ? 'contrato' : 'individual';

        // Clicar no modo onde já estás não mexe na seleção atual.
        if ($novo === $this->modo) {
            return;
        }

        $this->modo = $novo;

        // A seleção de equipamentos é específica do modo: ao trocar, começa do zero
        // (individual = escolher cliente; contrato = escolher contrato).
        $this->equipamento_id = null;
        $this->equipamentosCobertos = [];
        $this->equipamentoBusca = '';
        $this->faixaEquipamentos = '';

        if ($novo === 'individual') {
            // O modo individual não tem contrato.
            $this->contrato_id = null;
            $this->contratoBusca = '';
        } else {
            // O modo contrato não tem cliente escolhido à mão.
            $this->cliente_id = null;
            $this->clienteBusca = '';
        }
    }

    // Modo contrato: ao escolher o contrato, carrega os seus equipamentos (1.º = principal,
    // restantes = cobertos) e liga a intervenção ao contrato. Ficam editáveis.
    public function selecionarContrato(int $id): void
    {
        $contrato = Contrato::with('cliente', 'equipamentos')->find($id);
        if (! $contrato) {
            return;
        }

        $this->modo = 'contrato';
        $this->contrato_id = $contrato->id;
        $this->contratoBusca = trim($contrato->numero . ' · ' . ($contrato->cliente?->nome ?? ''));

        $ids = $contrato->equipamentos->pluck('id')->all();
        $this->equipamento_id = $ids[0] ?? null;
        $this->equipamentosCobertos = array_values(array_slice($ids, 1));
    }

    // Remove um equipamento do relatório (chip). Se for o principal, promove o 1.º coberto.
    public function removerEquipamentoDoRelatorio(int $id): void
    {
        if ($id === $this->equipamento_id) {
            $this->equipamento_id = array_shift($this->equipamentosCobertos) ?: null;

            return;
        }

        $this->removerEquipamentoCoberto($id);
    }

    // Modo individual: escolhe o CLIENTE e decide a faixa pela contagem de equipamentos:
    //   'auto' (≤10)      → anexa todos direto (1.º = principal, resto = cobertos);
    //   'lista' (11-50)   → não anexa nada; mostra lista de checkboxes;
    //   'pesquisa' (>50)  → não anexa nada; mostra pesquisa (nunca renderiza centenas de fichas → 500).
    public function selecionarCliente(int $id): void
    {
        $cliente = Cliente::find($id);
        if (! $cliente) {
            return;
        }

        $this->modo = 'individual';
        $this->cliente_id = $cliente->id;
        $this->clienteBusca = $cliente->nome ?? '';
        $this->equipamentoBusca = '';

        $total = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $cliente->id))->count();
        $this->faixaEquipamentos = FaixaEquipamentos::para($total);

        if ($this->faixaEquipamentos !== 'auto') {
            // 'lista' e 'pesquisa' → o técnico escolhe; não se anexa nada automaticamente.
            $this->equipamento_id = null;
            $this->equipamentosCobertos = [];

            return;
        }

        // 'auto' (≤10) → anexa todos, ordenados por nº de série.
        $ids = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $cliente->id))
            ->orderBy('numero_serie')
            ->pluck('id')
            ->all();

        $this->equipamento_id = $ids[0] ?? null;
        $this->equipamentosCobertos = array_values(array_slice($ids, 1));
    }

    // Faixa 'pesquisa' (>50): anexa um equipamento escolhido na pesquisa (1.º = principal).
    public function adicionarEquipamento(int $id): void
    {
        if (! $this->anexarEquipamentoDoCliente($id)) {
            return;
        }

        $this->equipamentoBusca = ''; // limpa para a próxima pesquisa
    }

    // Faixa 'lista' (11-50): marca/desmarca um equipamento (checkbox) em tempo real.
    // Marcar anexa (1.º = principal); desmarcar remove (se principal, promove o próximo).
    public function alternarEquipamento(int $id): void
    {
        $anexado = $id === $this->equipamento_id || in_array($id, $this->equipamentosCobertos, true);

        if ($anexado) {
            $this->removerEquipamentoDoRelatorio($id);

            return;
        }

        $this->anexarEquipamentoDoCliente($id);
    }

    // Faixa 'lista': anexa TODOS os equipamentos do cliente. Query limitada a MAX_LISTA_CHECKBOXES
    // (50) — garante que nunca se anexam >50 (logo nunca se montam >50 fichas), mesmo que os dados
    // mudem entretanto. Só faz sentido nesta faixa (onde o total já é ≤50).
    public function selecionarTodosEquipamentos(): void
    {
        if ($this->cliente_id === null) {
            return;
        }

        $ids = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
            ->orderBy('numero_serie')
            ->limit(FaixaEquipamentos::MAX_LISTA_CHECKBOXES)
            ->pluck('id')
            ->all();

        $this->equipamento_id = $ids[0] ?? null;
        $this->equipamentosCobertos = array_values(array_slice($ids, 1));
    }

    // Faixa 'lista': limpa a seleção (para o "Selecionar todos" alternar com "Limpar seleção").
    public function limparEquipamentos(): void
    {
        $this->equipamento_id = null;
        $this->equipamentosCobertos = [];
    }

    // Anexa um equipamento ao relatório, garantindo que é DAQUELE cliente (não de outro).
    // Devolve true se anexou/já estava, false se rejeitou. 1.º vira principal, seguintes cobertos.
    private function anexarEquipamentoDoCliente(int $id): bool
    {
        if ($this->cliente_id === null) {
            return false;
        }

        $doCliente = Equipamento::whereKey($id)
            ->whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
            ->exists();

        if (! $doCliente) {
            return false;
        }

        if ($this->equipamento_id === null) {
            $this->equipamento_id = $id;
        } elseif ($id !== $this->equipamento_id && ! in_array($id, $this->equipamentosCobertos, true)) {
            $this->equipamentosCobertos[] = $id;
        }

        return true;
    }

    public function removerEquipamentoCoberto(int $id): void
    {
        $this->equipamentosCobertos = array_values(array_filter($this->equipamentosCobertos, fn ($e) => $e !== $id));
    }

    // Dobra de acentos para pesquisa (igual aos outros comboboxes server-side).
    private function normalizarBusca(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'];
        $para = ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'];

        return str_replace($de, $para, $valor);
    }

    // Pesquisa server-side de clientes (nome sem acentos / NIF), limitada. Vazia quando não há
    // texto — nunca carrega os milhares de clientes de uma vez.
    private function clientesFiltrados(string $busca): Collection
    {
        if (trim($busca) === '') {
            return collect();
        }

        $termo = '%' . $busca . '%';
        $norm = '%' . $this->normalizarBusca($busca) . '%';
        $semAcentos = "translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

        return Cliente::query()
            ->where(function ($q) use ($termo, $norm, $semAcentos) {
                $q->whereRaw($semAcentos . ' like ?', [$norm])
                    ->orWhere('nif', 'ilike', $termo);
            })
            ->orderBy('nome')
            ->limit(20)
            ->get(['id', 'nome', 'nif']);
    }

    // Pesquisa server-side de equipamentos DE UM cliente (nº série / modelo sem acentos), limitada.
    // É o picker antigo de equipamento, agora com whereHas do cliente em vez dos ~17k globais.
    // Vazia quando não há texto — nunca carrega os (potencialmente milhares) equipamentos do cliente.
    private function equipamentosDoClienteFiltrados(string $busca): Collection
    {
        if ($this->cliente_id === null || trim($busca) === '') {
            return collect();
        }

        $termo = '%' . $busca . '%';
        $norm = '%' . $this->normalizarBusca($busca) . '%';
        $semAcentos = "translate(lower(coalesce(modelo, '')), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

        return Equipamento::query()
            ->whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
            ->where(function ($q) use ($termo, $norm, $semAcentos) {
                $q->where('numero_serie', 'ilike', $termo)
                    ->orWhereRaw($semAcentos . ' like ?', [$norm]);
            })
            ->orderBy('numero_serie')
            ->limit(20)
            ->get(['id', 'numero_serie', 'fabricante', 'modelo']);
    }

    // Faixa 'lista' (11-50): lista dos equipamentos do cliente para os checkboxes. Limitada a
    // MAX_LISTA_CHECKBOXES (50) — nesta faixa o total já é ≤50, mas o limite garante que nunca se
    // renderiza mais que 50 linhas. Vazia nas outras faixas (nunca carrega listas de centenas).
    private function equipamentosDoClienteLista(): Collection
    {
        if ($this->faixaEquipamentos !== 'lista' || $this->cliente_id === null) {
            return collect();
        }

        return Equipamento::query()
            ->whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
            ->orderBy('numero_serie')
            ->limit(FaixaEquipamentos::MAX_LISTA_CHECKBOXES)
            ->get(['id', 'numero_serie', 'fabricante', 'modelo']);
    }

    // Validação COMPLETA (finalizar).
    protected function rules(): array
    {
        return [
            'equipamento_id' => ['required', 'integer', 'exists:equipamentos,id'],
            'tipo' => ['required', 'in:preventiva,corretiva,instalacao'],
            'data' => ['required', 'date'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fim' => ['nullable', 'date_format:H:i', 'after_or_equal:hora_inicio'],
            'fotos.*' => ['image', 'max:8192'], // 8 MB
        ] + $this->regrasContrato();
    }

    // Regras das horas reutilizadas no rascunho (sempre opcionais, mas coerentes).
    protected function regrasHoras(): array
    {
        return [
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fim' => ['nullable', 'date_format:H:i', 'after_or_equal:hora_inicio'],
        ];
    }

    // No modo contrato, o contrato é obrigatório; no individual, fica nulo.
    protected function regrasContrato(): array
    {
        return [
            'contrato_id' => [$this->modo === 'contrato' ? 'required' : 'nullable', 'integer', 'exists:contratos,id'],
        ];
    }

    // ---- Fotos já guardadas (em edição) ----
    public function removerAnexoExistente(int $id): void
    {
        if (! $this->intervencaoId) {
            return;
        }

        $anexo = Anexo::where('anexavel_type', Intervencao::class)
            ->where('anexavel_id', $this->intervencaoId)
            ->find($id);

        if ($anexo) {
            Storage::disk()->delete($anexo->storage_key);
            $anexo->delete();
        }
    }

    // ---- Gravação ----
    public function guardarRascunho(GeradorRelatorio $gerador, GeradorEventoDeRelatorio $geradorEvento)
    {
        return $this->persistir($gerador, $geradorEvento, false);
    }

    public function finalizar(GeradorRelatorio $gerador, GeradorEventoDeRelatorio $geradorEvento)
    {
        return $this->persistir($gerador, $geradorEvento, true);
    }

    private function persistir(GeradorRelatorio $gerador, GeradorEventoDeRelatorio $geradorEvento, bool $finalizar)
    {
        if ($finalizar) {
            // Validação completa.
            $this->validate();
        } else {
            // Rascunho: o único obrigatório é o equipamento (e o contrato, se for modo contrato).
            $this->validate([
                'equipamento_id' => ['required', 'integer', 'exists:equipamentos,id'],
                'fotos.*' => ['image', 'max:8192'],
            ] + $this->regrasHoras() + $this->regrasContrato());
        }

        $relatorio = DB::transaction(function () use ($gerador, $geradorEvento, $finalizar) {
            $dados = [
                'equipamento_id' => $this->equipamento_id,
                'contrato_id' => $this->modo === 'contrato' ? $this->contrato_id : null,
                'tecnico_id' => auth()->id(),
                'tipo' => $this->tipo,
                'estado' => $finalizar ? EstadoIntervencao::Concluida : EstadoIntervencao::EmCurso,
                'data_inicio' => $this->data ?: null,
                'data_fim' => $finalizar ? now() : null,
                'hora_inicio' => $this->hora_inicio ?: null,
                'hora_fim' => $this->hora_fim ?: null,
                'trabalho_realizado' => $this->resumo ?: null,
                'observacoes' => $this->recomendacao ?: null,
            ];

            if ($this->intervencaoId) {
                $intervencao = Intervencao::findOrFail($this->intervencaoId);
                // O diagnóstico técnico passou para a ficha de medições; aqui só se atualiza a
                // prioridade da recomendação. NÃO destrói o diagnóstico legado (estado_geral,
                // carga, tensões, anomalias) de relatórios antigos — só se acrescenta a prioridade.
                $dados['diagnostico'] = array_filter(array_merge(
                    $intervencao->diagnostico ?? [],
                    ['prioridade' => $this->prioridade ?: null],
                ));
                $intervencao->update($dados);
            } else {
                $dados['diagnostico'] = array_filter(['prioridade' => $this->prioridade ?: null]);
                $intervencao = Intervencao::create($dados);
                $this->intervencaoId = $intervencao->id;
            }

            // Equipamentos adicionais cobertos (exclui o principal, para não duplicar).
            $intervencao->equipamentosCobertos()->sync(
                array_values(array_diff($this->equipamentosCobertos, [$this->equipamento_id])),
            );

            // O trabalho passou a registar-se em fichas de medição por equipamento (ambos os
            // modos). Relatórios novos nascem só com fichas; NÃO se cria checklist para eles.
            // A checklist antiga de relatórios LEGADOS NUNCA é apagada aqui — fica preservada na
            // BD (histórico de manutenção). No PDF, o fallback mostra-a só quando não há fichas.
            $this->persistirFichas($intervencao);

            // Fotos novas (anexa às existentes).
            foreach ($this->fotos as $foto) {
                $key = $foto->store('anexos/intervencoes/' . $intervencao->id);
                $intervencao->anexos()->create([
                    'nome_ficheiro' => $foto->getClientOriginalName(),
                    'storage_key' => $key,
                    'mime' => $foto->getMimeType(),
                    'tamanho' => $foto->getSize(),
                    'criado_por' => auth()->id(),
                ]);
            }
            $this->fotos = [];

            // Relatório: garante o rascunho-base (ponto único, à prova de corrida) e ajusta.
            $relatorio = $intervencao->garantirRascunho();
            $relatorio->estado = $finalizar ? EstadoRelatorio::Finalizado : EstadoRelatorio::Rascunho;
            if ($finalizar && blank($relatorio->numero)) {
                // Atribui o número (MAX+1) e grava com retry à prova de corrida.
                $gerador->atribuirNumeroEGravar($relatorio);
            } else {
                $relatorio->save();
            }
            $this->relatorioId = $relatorio->id;

            // Camada 3: data de intervenção futura → garante o evento de agenda ligado
            // (cria ou move). Direto no model, por isso NÃO dispara a camada 2 (anti-loop).
            $geradorEvento->gerar($intervencao);

            return $relatorio;
        });

        if ($finalizar) {
            GerarRelatorioPdf::dispatch($relatorio);
            session()->flash('sucesso', "Relatório {$relatorio->numero} finalizado. O PDF está a ser gerado.");
        } else {
            session()->flash('sucesso', 'Rascunho guardado.');
        }

        return redirect()->route('relatorios');
    }

    // Grava (upsert) uma ficha de medições por equipamento coberto e remove as órfãs.
    private function persistirFichas(Intervencao $intervencao): void
    {
        $ids = array_values(array_unique(array_filter(
            array_merge([$this->equipamento_id], $this->equipamentosCobertos)
        )));

        foreach ($ids as $equipId) {
            $dados = $this->fichas[$equipId] ?? FichaMedicao::estruturaVazia();
            $attrs = FichaMedicao::atributosDeFormulario($dados);

            // Só persiste fichas com medições. Uma ficha vazia (só a identificação
            // auto-preenchida) não cria registo; se já existia e foi esvaziada, remove-se.
            if (! FichaMedicao::temConteudo($attrs)) {
                $intervencao->fichasMedicao()->where('equipamento_id', $equipId)->delete();

                continue;
            }

            FichaMedicao::updateOrCreate(
                ['intervencao_id' => $intervencao->id, 'equipamento_id' => $equipId],
                $attrs + ['tipo_equipamento' => 'ups'],
            );
        }

        // Fichas de equipamentos que deixaram de estar cobertos (órfãs).
        $intervencao->fichasMedicao()
            ->when($ids !== [], fn ($q) => $q->whereNotIn('equipamento_id', $ids))
            ->delete();
    }

    // Garante uma ficha de medições (pré-preenchida) para cada equipamento coberto, sem
    // sobrepor dados já introduzidos. Aplica-se a AMBOS os modos (contrato e individual).
    private function sincronizarFichas(): void
    {
        $ids = array_values(array_unique(array_filter(
            array_merge([$this->equipamento_id], $this->equipamentosCobertos)
        )));

        // Descarta fichas de equipamentos que já não fazem parte do relatório.
        $this->fichas = array_intersect_key($this->fichas, array_flip($ids));

        $emFalta = array_values(array_diff($ids, array_keys($this->fichas)));
        if ($emFalta === []) {
            return;
        }

        $equipamentos = Equipamento::whereIn('id', $emFalta)->get()->keyBy('id');

        // Ao editar, recupera as fichas já persistidas para não perder valores.
        $persistidas = $this->intervencaoId
            ? FichaMedicao::where('intervencao_id', $this->intervencaoId)
                ->whereIn('equipamento_id', $emFalta)->get()->keyBy('equipamento_id')
            : collect();

        foreach ($emFalta as $id) {
            $this->fichas[$id] = $persistidas->has($id)
                ? $persistidas->get($id)->paraFormulario()
                : FichaMedicao::estruturaVazia($equipamentos->get($id));
        }
    }

    public function render()
    {
        $this->sincronizarFichas();

        $anexosExistentes = $this->intervencaoId
            ? Intervencao::find($this->intervencaoId)?->anexos()->get() ?? collect()
            : collect();

        // Modelos dos equipamentos cobertos selecionados (para mostrar os "chips").
        $cobertosSelecionados = $this->equipamentosCobertos
            ? Equipamento::with('local')->whereIn('id', $this->equipamentosCobertos)->get()
            : collect();

        // Equipamento principal (para o chip "principal" no modo contrato).
        $equipamentoPrincipal = $this->equipamento_id
            ? Equipamento::with('local')->find($this->equipamento_id)
            : null;

        // Contratos para o picker (modo contrato) — filtragem é client-side (são poucos).
        // Exclui rascunhos: ainda não estão em vigor, não pode haver intervenções ao seu abrigo.
        $contratos = Contrato::query()
            ->where('estado', '!=', EstadoContrato::Rascunho->value)
            ->with('cliente')
            ->orderByDesc('data_inicio')
            ->get();

        // Ids anexados (principal + cobertos) — para marcar os checkboxes da faixa 'lista'.
        $anexadosIds = array_values(array_filter(array_merge([$this->equipamento_id], $this->equipamentosCobertos)));

        return view('livewire.relatorios.novo', [
            'clientesFiltrados' => $this->clientesFiltrados($this->clienteBusca),
            'equipamentosClienteFiltrados' => $this->equipamentosDoClienteFiltrados($this->equipamentoBusca),
            'equipamentosClienteLista' => $this->equipamentosDoClienteLista(),
            'anexadosIds' => $anexadosIds,
            'tipos' => TipoIntervencao::cases(),
            'anexosExistentes' => $anexosExistentes,
            'cobertosSelecionados' => $cobertosSelecionados,
            'equipamentoPrincipal' => $equipamentoPrincipal,
            'contratos' => $contratos,
        ]);
    }
}
