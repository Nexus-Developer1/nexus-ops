<?php

namespace App\Livewire\Relatorios;

use App\Livewire\Concerns\ApenasEquipa;
use App\Enums\EstadoContrato;
use App\Enums\EstadoEvento;
use App\Enums\EstadoIntervencao;
use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Enums\TipoIntervencao;
use App\Jobs\GerarRelatorioPdf;
use App\Models\Anexo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\FichaMedicao;
use App\Models\Intervencao;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\Agenda\SincronizadorAgenda;
use App\Services\GeradorRelatorio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

// "Relatório de Intervenção Técnica": criar/retomar. Dois modos de gravação —
// Guardar rascunho (valida só o equipamento, sem PDF) e Finalizar (validação
// completa + gera o PDF). Sem auto-save: só grava quando o utilizador carrega.
#[Layout('components.layouts.app', ['ativo' => 'relatorios', 'titulo' => 'Relatório'])]
class Novo extends Component
{
    use ApenasEquipa;

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

    // Modo individual: 3 faixas por nº de equipamentos do cliente, para escolher os equipamentos
    // sem risco de montar centenas/milhares de fichas (→ 500):
    //   ≤ MAX_ANEXA_AUTO ......... anexa todos direto
    //   ≤ MAX_LISTA_CHECKBOXES ... lista de checkboxes (marca/desmarca em tempo real)
    //   acima .................... pesquisa server-side (anexa um a um) — o caminho que corrigiu o 500
    private const MAX_ANEXA_AUTO = 10;
    private const MAX_LISTA_CHECKBOXES = 50;

    // Modo individual: escolhe-se o cliente e anexam-se os equipamentos dele.
    public ?int $cliente_id = null;
    public string $clienteBusca = '';        // pesquisa server-side do cliente
    public string $serieBusca = '';          // atalho: pesquisa GLOBAL por nº de série → resolve o cliente
    // Faixa do fluxo de equipamentos: '' (sem cliente) | 'auto' | 'lista' | 'pesquisa'.
    public string $faixaEquipamentos = '';
    public string $equipamentoBusca = '';    // pesquisa server-side do equipamento (filtrada ao cliente)

    public ?int $equipamento_id = null;      // 1.º equipamento (principal da intervenção)
    /** @var list<int> Restantes equipamentos cobertos pelo mesmo relatório. */
    public array $equipamentosCobertos = [];
    public string $tipo = 'preventiva';
    public string $data = '';
    public string $data_fim = '';   // término; vazio = mesmo dia do início
    public string $hora_inicio = '';
    public string $hora_fim = '';

    // ---- Técnicos ----
    // Quem REDIGE o relatório não é necessariamente quem fez a intervenção (pode passar a limpo
    // o trabalho de outros) — por isso NADA é pré-selecionado. Escolhem-se os técnicos que
    // estiveram na intervenção, SEM hierarquia: não existe "técnico principal". (Por razões de
    // esquema, intervencoes.tecnico_id guarda o 1.º por ordem alfabética e os restantes vão
    // para o pivot intervencao_tecnicos — detalhe de armazenamento invisível: a UI e o PDF
    // mostram sempre a lista completa, sem distinção.) Lida da BD a cada render.
    /** @var list<int> Ids dos técnicos que fizeram a intervenção. */
    public array $tecnicoIds = [];

    // ---- Constatações ----
    public string $resumo = '';

    // ---- Fichas de medição (ambos os modos) ----
    // Uma por equipamento coberto, keyed por equipamento_id. Estrutura em FichaMedicao::estruturaVazia().
    // Inclui as "Recomendações e próximos passos" + prioridade — POR equipamento (não há campo único).
    public array $fichas = [];

    // ---- Fotos POR EQUIPAMENTO (novos uploads temporários até gravar) ----
    // As fotos pertencem a um equipamento (aparecem na ficha dele e junto das medições no PDF).
    // Ambos os arrays são keyed pelo id do equipamento: [equipId => [ficheiros]].
    // $fotos = binding do seletor de cada ficha; o Livewire SUBSTITUI-o a cada seleção, por isso
    // acumulamos em $fotosNovas (via updatedFotos): cada seleção ACRESCENTA às já escolhidas.
    public array $fotos = [];
    /** @var array<int, array<int, mixed>> [equipId => fotos por gravar] — acumuladas entre seleções. */
    public array $fotosNovas = [];

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
                    $this->faixaEquipamentos = $this->faixaPara($this->equipamentosCandidatos()->count());
                }
            }

            // Modo contrato: faixa pela contagem de equipamentos DO CONTRATO. Carrega SÓ os cobertos
            // já gravados (acima); se o contrato for grande, mostra a pesquisa para acrescentar mais
            // — nunca monta as fichas todas (editar um contrato de cobertura ampla não rebenta).
            if ($this->modo === 'contrato') {
                $this->faixaEquipamentos = $this->faixaPara($this->equipamentosCandidatos()->count());
            }
            $this->tipo = $intervencao->tipo->value;
            $this->data = $intervencao->data_inicio?->format('Y-m-d') ?? '';
            // Término: só pré-preenche quando difere do início (mesmo dia = campo vazio).
            $dataFim = $intervencao->data_fim?->format('Y-m-d');
            $this->data_fim = ($dataFim && $dataFim !== $this->data) ? $dataFim : '';
            $this->hora_inicio = $intervencao->hora_inicio ? substr($intervencao->hora_inicio, 0, 5) : '';
            $this->hora_fim = $intervencao->hora_fim ? substr($intervencao->hora_fim, 0, 5) : '';
            $this->resumo = $intervencao->trabalho_realizado ?? '';

            // Recomendações e prioridade são agora por equipamento (na ficha) — carregadas por
            // sincronizarFichas() a partir das fichas persistidas. Ver persistirFichas().

            // Técnicos já guardados (principal + colaboradores), filtrados a técnicos ativos —
            // ids fora disso (ex.: conta antiga desativada) não passariam na validação ao gravar.
            $ids = array_filter(array_merge(
                [$intervencao->tecnico_id],
                $intervencao->tecnicos()->pluck('utilizadores.id')->all(),
            ));
            $this->tecnicoIds = User::whereIn('id', $ids)
                ->where('papel', PapelUtilizador::Tecnico)
                ->where('ativo', true)
                ->pluck('id')->all();

            return;
        }

        // Novo relatório: SEM técnico pré-selecionado — quem redige pode não ter feito a intervenção.
        $this->data = now()->format('Y-m-d');
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

    // Modo contrato: ao escolher o contrato, decide a faixa pelo nº de equipamentos DO CONTRATO
    // (mesma lógica do individual, mas a fonte é o contrato). Contratos pequenos (≤10) anexam todos
    // como hoje; grandes (>10) NÃO anexam nada — o técnico escolhe os que vai medir nesta visita
    // (senão montaria centenas de fichas → 500 por memory_limit).
    public function selecionarContrato(int $id): void
    {
        $contrato = Contrato::with('cliente')->find($id);
        if (! $contrato) {
            return;
        }

        $this->modo = 'contrato';
        $this->contrato_id = $contrato->id;
        $this->contratoBusca = trim($contrato->numero . ' · ' . ($contrato->cliente?->nome ?? ''));
        $this->equipamentoBusca = '';

        $this->faixaEquipamentos = $this->faixaPara($this->equipamentosCandidatos()->count());

        if ($this->faixaEquipamentos !== 'auto') {
            // >10 equipamentos no contrato → o técnico escolhe (lista/pesquisa); não anexa nada.
            $this->equipamento_id = null;
            $this->equipamentosCobertos = [];

            return;
        }

        // ≤10 → anexa todos os do contrato, ordenados por nº de série (1.º = principal).
        $ids = $this->equipamentosCandidatos()->orderBy('numero_serie')->pluck('id')->all();
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

    // Query base dos equipamentos CANDIDATOS a serem escolhidos, consciente do modo:
    //   - contrato   → equipamentos do CONTRATO (pivot contrato_equipamentos);
    //   - individual → equipamentos do CLIENTE (via local) — exatamente o de hoje.
    // Todas as faixas (lista/pesquisa/selecionar-todos/guard) bebem daqui, por isso a mesma
    // mecânica serve os dois modos — só muda a fonte. Com id em falta, a query não devolve nada.
    private function equipamentosCandidatos(): Builder
    {
        if ($this->modo === 'contrato') {
            return Equipamento::whereHas('contratos', fn ($q) => $q->where('contratos.id', $this->contrato_id ?? 0));
        }

        return Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id ?? 0));
    }

    // Faixa do fluxo de equipamentos conforme o total (do cliente ou do contrato).
    private function faixaPara(int $total): string
    {
        if ($total <= self::MAX_ANEXA_AUTO) {
            return 'auto';
        }
        if ($total <= self::MAX_LISTA_CHECKBOXES) {
            return 'lista';
        }

        return 'pesquisa';
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

        $this->faixaEquipamentos = $this->faixaPara($this->equipamentosCandidatos()->count());

        if ($this->faixaEquipamentos !== 'auto') {
            // 'lista' e 'pesquisa' → o técnico escolhe; não se anexa nada automaticamente.
            $this->equipamento_id = null;
            $this->equipamentosCobertos = [];

            return;
        }

        // 'auto' (≤10) → anexa todos, ordenados por nº de série.
        $ids = $this->equipamentosCandidatos()->orderBy('numero_serie')->pluck('id')->all();

        $this->equipamento_id = $ids[0] ?? null;
        $this->equipamentosCobertos = array_values(array_slice($ids, 1));
    }

    // Atalho do modo individual: escolher o equipamento pelo nº de série resolve o CLIENTE
    // automaticamente (equipamento → local → cliente) e fixa esse equipamento como principal.
    // Mantém a coerência com selecionarCliente: cliente pequeno ('auto') anexa todos (com o
    // pesquisado como principal); cliente grande fica só com o principal (acrescenta-se o resto
    // na lista/pesquisa) — nunca monta centenas de fichas.
    public function selecionarPorSerie(int $equipamentoId): void
    {
        $equipamento = Equipamento::with('local.cliente')->find($equipamentoId);
        $cliente = $equipamento?->local?->cliente;
        if (! $cliente) {
            return;
        }

        $this->modo = 'individual';
        $this->cliente_id = $cliente->id;
        $this->clienteBusca = $cliente->nome ?? '';
        $this->serieBusca = '';
        $this->equipamentoBusca = '';

        $this->faixaEquipamentos = $this->faixaPara($this->equipamentosCandidatos()->count());

        if ($this->faixaEquipamentos === 'auto') {
            $ids = $this->equipamentosCandidatos()->orderBy('numero_serie')->pluck('id')->all();
            $this->equipamento_id = $equipamento->id;
            $this->equipamentosCobertos = array_values(array_diff($ids, [$equipamento->id]));

            return;
        }

        // 'lista'/'pesquisa' (cliente grande): só o pesquisado como principal.
        $this->equipamento_id = $equipamento->id;
        $this->equipamentosCobertos = [];
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

    // Faixa 'lista': anexa TODOS os candidatos (do cliente ou do contrato). Query limitada a
    // MAX_LISTA_CHECKBOXES (50) — garante que nunca se anexam >50 (logo nunca se montam >50 fichas),
    // mesmo que os dados mudem. Só faz sentido nesta faixa (onde o total já é ≤50).
    public function selecionarTodosEquipamentos(): void
    {
        $ids = $this->equipamentosCandidatos()
            ->orderBy('numero_serie')
            ->limit(self::MAX_LISTA_CHECKBOXES)
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

    // Anexa um equipamento ao relatório, garantindo que é um CANDIDATO válido (do cliente ou do
    // contrato, conforme o modo). Devolve true se anexou/já estava, false se rejeitou.
    // 1.º vira principal, seguintes cobertos.
    private function anexarEquipamentoDoCliente(int $id): bool
    {
        $valido = $this->equipamentosCandidatos()->whereKey($id)->exists();

        if (! $valido) {
            return false;
        }

        if ($this->equipamento_id === null) {
            $this->equipamento_id = $id;
        } elseif ($id !== $this->equipamento_id && ! in_array($id, $this->equipamentosCobertos, true)) {
            $this->equipamentosCobertos[] = $id;
        }

        return true;
    }

    // Só uso interno (removerEquipamentoDoRelatorio) — sem chamador nas views, não precisa
    // de ser invocável pelo browser.
    private function removerEquipamentoCoberto(int $id): void
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

    // Faixa 'pesquisa': pesquisa server-side dos CANDIDATOS (nº série / modelo sem acentos),
    // filtrada ao cliente OU ao contrato conforme o modo. Vazia sem texto — nunca carrega os
    // (potencialmente milhares) equipamentos de uma vez.
    private function equipamentosFiltrados(string $busca): Collection
    {
        if (trim($busca) === '') {
            return collect();
        }

        $termo = '%' . $busca . '%';
        $norm = '%' . $this->normalizarBusca($busca) . '%';
        $semAcentos = "translate(lower(coalesce(modelo, '')), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

        return $this->equipamentosCandidatos()
            ->where(function ($q) use ($termo, $norm, $semAcentos) {
                $q->where('numero_serie', 'ilike', $termo)
                    ->orWhereRaw($semAcentos . ' like ?', [$norm]);
            })
            ->orderBy('numero_serie')
            ->limit(20)
            ->get(['id', 'numero_serie', 'fabricante', 'modelo']);
    }

    // Atalho (modo individual): pesquisa GLOBAL por nº de série — de TODOS os clientes, não
    // filtrada. Traz o cliente (via local) para se mostrar na lista e resolver ao escolher.
    // Vazia sem texto; limitada. (Admin/técnico veem tudo; o global scope só filtra clientes.)
    private function equipamentosPorSerie(string $busca): Collection
    {
        if (trim($busca) === '') {
            return collect();
        }

        return Equipamento::query()
            ->where('numero_serie', 'ilike', '%' . trim($busca) . '%')
            ->whereHas('local.cliente')
            ->with('local.cliente:id,nome')
            ->orderBy('numero_serie')
            ->limit(20)
            ->get(['id', 'numero_serie', 'fabricante', 'modelo', 'local_id']);
    }

    // Faixa 'lista' (11-50): lista dos candidatos para os checkboxes (cliente ou contrato).
    // Limitada a MAX_LISTA_CHECKBOXES (50); vazia nas outras faixas (nunca carrega centenas).
    private function equipamentosLista(): Collection
    {
        if ($this->faixaEquipamentos !== 'lista') {
            return collect();
        }

        return $this->equipamentosCandidatos()
            ->orderBy('numero_serie')
            ->limit(self::MAX_LISTA_CHECKBOXES)
            ->get(['id', 'numero_serie', 'fabricante', 'modelo']);
    }

    // Nomes amigáveis nas mensagens de validação.
    protected function validationAttributes(): array
    {
        return [
            'tecnicoIds' => 'técnicos',
            'tecnicoIds.*' => 'técnico',
        ];
    }

    // Validação COMPLETA (finalizar).
    protected function rules(): array
    {
        return [
            'equipamento_id' => ['required', 'integer', 'exists:equipamentos,id'],
            'tipo' => ['required', 'in:preventiva,corretiva,instalacao'],
            'data' => ['required', 'date'],
            'fotosNovas.*.*' => ['image', 'max:8192'], // 8 MB
            // Finalizar exige saber quem fez a intervenção (o PDF identifica os técnicos).
            'tecnicoIds' => ['required', 'array', 'min:1'],
        ] + $this->regrasHoras() + $this->regrasContrato() + $this->regrasTecnicos() + $this->regrasCobertos();
    }

    // Equipamentos cobertos: prop pública (manipulável pelo browser) — cada id tem de existir.
    // Sem isto, ids inexistentes rebentavam na FK (500) e ids arbitrários entravam no sync.
    protected function regrasCobertos(): array
    {
        return [
            'equipamentosCobertos' => ['array', 'max:500'],
            'equipamentosCobertos.*' => ['integer', 'exists:equipamentos,id'],
        ];
    }

    // Regras das horas reutilizadas no rascunho (sempre opcionais, mas coerentes).
    protected function regrasHoras(): array
    {
        return [
            'data_fim' => ['nullable', 'date', 'after_or_equal:data'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            // Num término noutro dia, a hora de fim pode ser "menor" que a de início
            // (ex.: 22:00 → 06:00 do dia seguinte) — a regra só se aplica no mesmo dia.
            'hora_fim' => $this->data_fim !== '' && $this->data_fim !== $this->data
                ? ['nullable', 'date_format:H:i']
                : ['nullable', 'date_format:H:i', 'after_or_equal:hora_inicio'],
        ];
    }

    // No modo contrato, o contrato é obrigatório; no individual, fica nulo.
    protected function regrasContrato(): array
    {
        return [
            'contrato_id' => [$this->modo === 'contrato' ? 'required' : 'nullable', 'integer', 'exists:contratos,id'],
        ];
    }

    // Técnicos: cada um tem de ser um técnico ativo (não confia no cliente). Em rascunho a
    // lista pode ficar vazia; ao finalizar, rules() exige pelo menos um.
    protected function regrasTecnicos(): array
    {
        return [
            'tecnicoIds' => ['array'],
            'tecnicoIds.*' => [
                'integer',
                Rule::exists('utilizadores', 'id')
                    ->where('papel', PapelUtilizador::Tecnico->value)
                    ->where('ativo', true),
            ],
        ];
    }

    // ---- Fotos novas (por gravar), por equipamento ----
    // O seletor de cada ficha é wire:model="fotos.{equipId}"; ao mudar, o Livewire chama este hook
    // com $key = equipId. Validamos as novas e acumulamos em $fotosNovas[equipId], limpando o input
    // desse equipamento para a próxima ronda (cada seleção ACRESCENTA, não substitui).
    public function updatedFotos($value, $key): void
    {
        $equipId = (int) $key;
        $this->validate(["fotos.$key.*" => ['image', 'max:8192']]);

        $this->fotosNovas[$equipId] = array_merge($this->fotosNovas[$equipId] ?? [], $this->fotos[$key] ?? []);
        $this->fotos[$key] = [];
    }

    // Remove uma foto ainda NÃO gravada (da pré-visualização) de um equipamento, pelo índice.
    public function removerFotoNova(int $equipId, int $indice): void
    {
        unset($this->fotosNovas[$equipId][$indice]);
        $this->fotosNovas[$equipId] = array_values($this->fotosNovas[$equipId] ?? []);
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

    // Data/hora de término da intervenção a gravar: a data de término do formulário + hora de
    // fim quando preenchida. Término vazio: no rascunho fica null; ao FINALIZAR assume o mesmo
    // dia do início — intervenção concluída tem sempre término (a métrica de SLA conta por ele).
    private function dataFimReal(bool $finalizar): ?Carbon
    {
        $dia = $this->data_fim ?: ($finalizar ? $this->data : null);
        if (! $dia) {
            return null;
        }

        return Carbon::parse($dia)->setTimeFromTimeString($this->hora_fim ?: '00:00');
    }

    // Validação da gravação: completa ao finalizar; no rascunho só o essencial
    // (equipamento; contrato se for modo contrato).
    private function validarPara(bool $finalizar): void
    {
        if ($finalizar) {
            $this->validate();

            return;
        }

        $this->validate([
            'equipamento_id' => ['required', 'integer', 'exists:equipamentos,id'],
            'fotosNovas.*.*' => ['image', 'max:8192'],
        ] + $this->regrasHoras() + $this->regrasContrato() + $this->regrasTecnicos() + $this->regrasCobertos());
    }

    // ---- Gravação ----
    public function guardarRascunho(GeradorRelatorio $gerador, SincronizadorAgenda $sincronizador)
    {
        return $this->persistir($gerador, $sincronizador, false);
    }

    public function finalizar(GeradorRelatorio $gerador, SincronizadorAgenda $sincronizador)
    {
        return $this->persistir($gerador, $sincronizador, true);
    }

    private function persistir(GeradorRelatorio $gerador, SincronizadorAgenda $sincronizador, bool $finalizar)
    {
        // A guarda do mount ("relatório ENVIADO não se edita") repetida aqui: intervencaoId é
        // prop pública, manipulável pelo browser — sem esta re-verificação, uma chamada forjada
        // reescrevia (e ao finalizar, regenerava o PDF de) um documento oficial já entregue.
        if ($this->intervencaoId) {
            abort_if(Relatorio::where('intervencao_id', $this->intervencaoId)
                ->where('estado', EstadoRelatorio::Enviado)
                ->exists(), 403);
        }

        // Antes de gravar: relatório acabado de começar (URL ainda /relatorios/novo)?
        $eraNovo = $this->relatorioId === null;

        try {
            $this->validarPara($finalizar);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Os campos validados vivem todos no separador "Dados Gerais"; se o utilizador
            // estiver noutro separador (ficha de um equipamento), o erro ficava invisível e
            // o botão parecia morto. Avisa o frontend para saltar para o separador certo.
            $this->dispatch('validacao-falhou');

            throw $e;
        }

        $relatorio = DB::transaction(function () use ($gerador, $sincronizador, $finalizar) {
            $dados = [
                'equipamento_id' => $this->equipamento_id,
                'contrato_id' => $this->modo === 'contrato' ? $this->contrato_id : null,
                'tipo' => $this->tipo,
                'estado' => $finalizar ? EstadoIntervencao::Concluida : EstadoIntervencao::EmCurso,
                'data_inicio' => $this->data ?: null,
                // Término REAL da intervenção (escrito no formulário; vazio = mesmo dia do
                // início), com a hora de fim quando existe — alimenta o PDF e a métrica de
                // SLA. Antes gravava now() ao finalizar: o instante em que se REDIGIU o
                // relatório, não o fim do trabalho (podia ser dias depois).
                'data_fim' => $this->dataFimReal($finalizar),
                'hora_inicio' => $this->hora_inicio ?: null,
                'hora_fim' => $this->hora_fim ?: null,
                'trabalho_realizado' => $this->resumo ?: null,
                // observacoes/diagnostico['prioridade'] eram a recomendação ÚNICA do relatório;
                // agora a recomendação + prioridade são POR equipamento (na ficha). Não se tocam
                // aqui: em relatórios legados ficam preservados (fallback "Observações gerais" no PDF).
            ];

            // Técnicos selecionados, re-filtrados a papel=técnico + ativo (defesa em profundidade,
            // para além da validação). Sem hierarquia entre eles — a ordem alfabética serve só
            // para arrumar de forma determinística: 1.º em tecnico_id, restantes no pivot.
            $tecnicosEscolhidos = $this->tecnicoIds === []
                ? []
                : User::whereIn('id', array_map('intval', $this->tecnicoIds))
                    ->where('papel', PapelUtilizador::Tecnico)
                    ->where('ativo', true)
                    ->orderBy('nome')
                    ->pluck('id')->all();
            $dados['tecnico_id'] = $tecnicosEscolhidos[0] ?? null;

            if ($this->intervencaoId) {
                $intervencao = Intervencao::findOrFail($this->intervencaoId);
                $intervencao->update($dados);
            } else {
                $intervencao = Intervencao::create($dados);
                $this->intervencaoId = $intervencao->id;
            }

            // Restantes técnicos no pivot (o 1.º já está em tecnico_id).
            $intervencao->tecnicos()->sync(array_slice($tecnicosEscolhidos, 1));

            // Equipamentos adicionais cobertos (exclui o principal, para não duplicar).
            $intervencao->equipamentosCobertos()->sync(
                array_values(array_diff($this->equipamentosCobertos, [$this->equipamento_id])),
            );

            // O trabalho passou a registar-se em fichas de medição por equipamento (ambos os
            // modos). Relatórios novos nascem só com fichas; NÃO se cria checklist para eles.
            // A checklist antiga de relatórios LEGADOS NUNCA é apagada aqui — fica preservada na
            // BD (histórico de manutenção). No PDF, o fallback mostra-a só quando não há fichas.
            $this->persistirFichas($intervencao);

            // Fotos novas por equipamento (anexa às existentes). $fotosNovas é [equipId => fotos];
            // cada anexo guarda o equipamento_id → aparece na ficha desse equipamento e no PDF.
            $idsValidos = array_filter(array_merge([$this->equipamento_id], $this->equipamentosCobertos));
            foreach ($this->fotosNovas as $equipId => $fotosDoEquipamento) {
                // Só grava fotos de equipamentos que fazem parte do relatório (ignora resíduos).
                $equipId = in_array((int) $equipId, array_map('intval', $idsValidos), true) ? (int) $equipId : null;

                foreach ($fotosDoEquipamento as $foto) {
                    $storageKey = $foto->store('anexos/intervencoes/' . $intervencao->id);
                    $intervencao->anexos()->create([
                        'equipamento_id' => $equipId,
                        'nome_ficheiro' => $foto->getClientOriginalName(),
                        'storage_key' => $storageKey,
                        'mime' => $foto->getMimeType(),
                        'tamanho' => $foto->getSize(),
                        'criado_por' => auth()->id(),
                    ]);
                }
            }
            $this->fotos = [];
            $this->fotosNovas = [];

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

            // Camada 3 (relatórios → agenda) via ponto único: data futura → garante o evento
            // ligado (cria ou move). As guardas anti-loop vivem no SincronizadorAgenda.
            $sincronizador->intervencaoGravada($intervencao);

            // Visita executada (intervenção concluída) → fecha o evento de agenda ligado.
            // Antes só o ENVIO por email fechava o evento; um relatório entregue em mão ou
            // via portal deixava a visita "planeada" para sempre (e as métricas de gestão
            // planeadas vs. realizadas contavam mal). O envio continua a fechar (idempotente).
            // Depois do gerar() acima, que em edições repõe estado Planeado.
            if ($finalizar && $intervencao->refresh()->evento_agenda_id) {
                EventoAgenda::withoutGlobalScopes()
                    ->whereKey($intervencao->evento_agenda_id)
                    ->update(['estado' => EstadoEvento::Concluido->value]);
            }

            return $relatorio;
        });

        if ($finalizar) {
            GerarRelatorioPdf::dispatch($relatorio);
            session()->flash('sucesso', "Relatório {$relatorio->numero} finalizado. O PDF está a ser gerado.");

            return redirect()->route('relatorios');
        }

        // Guardar rascunho é um save de PREVENÇÃO: fica-se na página, a meio do trabalho.
        // 1.º rascunho (veio de /relatorios/novo): muda a URL para a edição do próprio
        // rascunho — um F5 retoma-o em vez de abrir um formulário vazio. Tudo o que estava
        // no formulário acabou de ser gravado, por isso o remount não perde nada.
        if ($eraNovo) {
            session()->flash('sucesso', 'Rascunho guardado.');

            return $this->redirectRoute('relatorios.editar', $relatorio, navigate: true);
        }

        // Já em edição: não navega — confirma com um toast e deixa continuar onde estava.
        $this->dispatch('rascunho-guardado');

        return null;
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

        // Técnicos disponíveis (lidos a cada render → refletem quem for entrando).
        $tecnicos = User::query()
            ->where('papel', PapelUtilizador::Tecnico)
            ->where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome']);

        return view('livewire.relatorios.novo', [
            'clientesFiltrados' => $this->clientesFiltrados($this->clienteBusca),
            'equipamentosPorSerie' => $this->equipamentosPorSerie($this->serieBusca),
            'equipamentosFiltrados' => $this->equipamentosFiltrados($this->equipamentoBusca),
            'equipamentosLista' => $this->equipamentosLista(),
            'anexadosIds' => $anexadosIds,
            'tipos' => TipoIntervencao::cases(),
            'anexosExistentes' => $anexosExistentes,
            'cobertosSelecionados' => $cobertosSelecionados,
            'equipamentoPrincipal' => $equipamentoPrincipal,
            'contratos' => $contratos,
            'tecnicos' => $tecnicos,
        ]);
    }
}
