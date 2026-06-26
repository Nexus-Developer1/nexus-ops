<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoContrato;
use App\Enums\EstadoIntervencao;
use App\Enums\EstadoRelatorio;
use App\Enums\TipoIntervencao;
use App\Jobs\GerarRelatorioPdf;
use App\Models\Anexo;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Relatorio;
use App\Services\Agenda\GeradorEventoDeRelatorio;
use App\Services\GeradorRelatorio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    // Modo: 'individual' (escolhe equipamento à mão) ou 'contrato' (equipamentos vêm do contrato).
    public string $modo = 'individual';
    public ?int $contrato_id = null;
    public string $contratoBusca = '';

    public ?int $equipamento_id = null;
    /** @var list<int> Equipamentos adicionais cobertos (além do principal). */
    public array $equipamentosCobertos = [];
    public string $tipo = 'preventiva';
    public string $data = '';
    public string $hora_inicio = '';
    public string $hora_fim = '';

    // ---- Constatações ----
    public string $resumo = '';

    // ---- Checklist em etapas ----
    // Estrutura: [['uid','titulo','itens'=>[['uid','descricao','concluido','observacao'],...]], ...]
    public array $etapas = [];

    // ---- Recomendações ----
    public string $recomendacao = '';
    public string $prioridade = 'Normal';

    // ---- Diagnóstico ----
    public string $estado_geral = '';
    public string $carga = '';
    public string $tensao_entrada = '';
    public string $tensao_saida = '';
    public string $anomalias = '';

    // ---- Fotos (novos uploads temporários até gravar) ----
    public array $fotos = [];

    public function mount(?Relatorio $relatorio = null): void
    {
        if ($relatorio && $relatorio->exists) {
            // Só rascunhos são editáveis aqui; finalizados vão para a lista.
            if ($relatorio->estado !== EstadoRelatorio::Rascunho) {
                $this->redirectRoute('relatorios', navigate: true);

                return;
            }

            $intervencao = $relatorio->intervencao()->with('checklistEtapas.itens', 'contrato.cliente')->firstOrFail();
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
            $this->tipo = $intervencao->tipo->value;
            $this->data = $intervencao->data_inicio?->format('Y-m-d') ?? '';
            $this->hora_inicio = $intervencao->hora_inicio ? substr($intervencao->hora_inicio, 0, 5) : '';
            $this->hora_fim = $intervencao->hora_fim ? substr($intervencao->hora_fim, 0, 5) : '';
            $this->resumo = $intervencao->trabalho_realizado ?? '';
            $this->recomendacao = $intervencao->observacoes ?? '';

            $d = $intervencao->diagnostico ?? [];
            $this->estado_geral = $d['estado_geral'] ?? '';
            $this->carga = $d['carga'] ?? '';
            $this->tensao_entrada = $d['tensao_entrada'] ?? '';
            $this->tensao_saida = $d['tensao_saida'] ?? '';
            $this->anomalias = $d['anomalias'] ?? '';
            $this->prioridade = $d['prioridade'] ?? 'Normal';

            $this->etapas = $intervencao->checklistEtapas->map(fn ($et) => [
                'uid' => $this->novoUid(),
                'titulo' => $et->titulo,
                'itens' => $et->itens->map(fn ($it) => [
                    'uid' => $this->novoUid(),
                    'descricao' => $it->descricao,
                    'concluido' => (bool) $it->concluido,
                    'observacao' => $it->observacao ?? '',
                ])->all(),
            ])->all();

            return;
        }

        // Novo relatório.
        $this->data = now()->format('Y-m-d');
        $this->etapas = [
            [
                'uid' => $this->novoUid(),
                'titulo' => 'Inspeção',
                'itens' => [
                    ['uid' => $this->novoUid(), 'descricao' => 'Inspeção visual', 'concluido' => false, 'observacao' => ''],
                    ['uid' => $this->novoUid(), 'descricao' => 'Verificação de funcionamento', 'concluido' => false, 'observacao' => ''],
                    ['uid' => $this->novoUid(), 'descricao' => 'Limpeza geral', 'concluido' => false, 'observacao' => ''],
                ],
            ],
        ];
    }

    public function ehRascunho(): bool
    {
        return true; // este formulário só edita rascunhos / cria novos (que nascem rascunho)
    }

    // Alterna entre relatório de contrato e individual.
    public function definirModo(string $modo): void
    {
        $this->modo = $modo === 'contrato' ? 'contrato' : 'individual';

        if ($this->modo === 'individual') {
            $this->contrato_id = null;
            $this->contratoBusca = '';
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

    // Acrescenta um equipamento adicional coberto (nunca o principal nem repetido).
    public function adicionarEquipamentoCoberto(int $id): void
    {
        if ($id === $this->equipamento_id || in_array($id, $this->equipamentosCobertos, true)) {
            return;
        }
        if (Equipamento::whereKey($id)->exists()) {
            $this->equipamentosCobertos[] = $id;
        }
    }

    public function removerEquipamentoCoberto(int $id): void
    {
        $this->equipamentosCobertos = array_values(array_filter($this->equipamentosCobertos, fn ($e) => $e !== $id));
    }

    private function novoUid(): string
    {
        return (string) Str::uuid();
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

    // ---- Etapas ----
    public function adicionarEtapa(): void
    {
        $this->etapas[] = ['uid' => $this->novoUid(), 'titulo' => '', 'itens' => []];
    }

    public function removerEtapa(string $uid): void
    {
        $this->etapas = array_values(array_filter($this->etapas, fn ($e) => $e['uid'] !== $uid));
    }

    // ---- Itens ----
    public function adicionarItem(string $etapaUid): void
    {
        foreach ($this->etapas as $i => $etapa) {
            if ($etapa['uid'] === $etapaUid) {
                $this->etapas[$i]['itens'][] = ['uid' => $this->novoUid(), 'descricao' => '', 'concluido' => false, 'observacao' => ''];

                return;
            }
        }
    }

    public function removerItem(string $etapaUid, string $itemUid): void
    {
        foreach ($this->etapas as $i => $etapa) {
            if ($etapa['uid'] === $etapaUid) {
                $this->etapas[$i]['itens'] = array_values(array_filter($etapa['itens'], fn ($it) => $it['uid'] !== $itemUid));

                return;
            }
        }
    }

    // ---- Reordenação (drag-and-drop) ----
    public function reordenar(array $estrutura): void
    {
        $etapasPorUid = [];
        $itensPorUid = [];
        foreach ($this->etapas as $etapa) {
            $etapasPorUid[$etapa['uid']] = $etapa;
            foreach ($etapa['itens'] as $item) {
                $itensPorUid[$item['uid']] = $item;
            }
        }

        $novas = [];
        foreach ($estrutura as $entrada) {
            $etapaUid = $entrada['etapa'] ?? null;
            if (! $etapaUid || ! isset($etapasPorUid[$etapaUid])) {
                continue;
            }

            $itens = [];
            foreach (($entrada['itens'] ?? []) as $itemUid) {
                if (isset($itensPorUid[$itemUid])) {
                    $itens[] = $itensPorUid[$itemUid];
                }
            }

            $novas[] = [
                'uid' => $etapaUid,
                'titulo' => $etapasPorUid[$etapaUid]['titulo'],
                'itens' => $itens,
            ];
        }

        $totalItensDepois = array_sum(array_map(fn ($e) => count($e['itens']), $novas));
        if (count($novas) === count($this->etapas) && $totalItensDepois === count($itensPorUid)) {
            $this->etapas = $novas;
        }
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
                'diagnostico' => array_filter([
                    'estado_geral' => $this->estado_geral ?: null,
                    'carga' => $this->carga ?: null,
                    'tensao_entrada' => $this->tensao_entrada ?: null,
                    'tensao_saida' => $this->tensao_saida ?: null,
                    'anomalias' => $this->anomalias ?: null,
                    'prioridade' => $this->prioridade ?: null,
                ]),
            ];

            if ($this->intervencaoId) {
                $intervencao = Intervencao::findOrFail($this->intervencaoId);
                $intervencao->update($dados);
            } else {
                $intervencao = Intervencao::create($dados);
                $this->intervencaoId = $intervencao->id;
            }

            // Equipamentos adicionais cobertos (exclui o principal, para não duplicar).
            $intervencao->equipamentosCobertos()->sync(
                array_values(array_diff($this->equipamentosCobertos, [$this->equipamento_id])),
            );

            // Etapas + itens: substitui o conjunto, com a ordem.
            $intervencao->checklistEtapas()->delete();
            foreach (array_values($this->etapas) as $ordemEtapa => $etapa) {
                $titulo = trim($etapa['titulo'] ?? '');
                $itens = array_values(array_filter(
                    $etapa['itens'] ?? [],
                    fn ($it) => trim($it['descricao'] ?? '') !== '',
                ));

                if ($titulo === '' && count($itens) === 0) {
                    continue;
                }

                $etapaModel = $intervencao->checklistEtapas()->create([
                    'titulo' => $titulo !== '' ? $titulo : 'Sem título',
                    'ordem' => $ordemEtapa,
                ]);

                foreach ($itens as $ordemItem => $it) {
                    $etapaModel->itens()->create([
                        'intervencao_id' => $intervencao->id,
                        'descricao' => trim($it['descricao']),
                        'concluido' => (bool) ($it['concluido'] ?? false),
                        'observacao' => trim($it['observacao'] ?? '') ?: null,
                        'ordem' => $ordemItem,
                    ]);
                }
            }

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

            // Relatório: cria/atualiza. O número só é atribuído ao finalizar.
            $relatorio = Relatorio::firstOrNew(['intervencao_id' => $intervencao->id]);
            if (! $relatorio->exists) {
                $relatorio->data = now();
            }
            $relatorio->estado = $finalizar ? EstadoRelatorio::Finalizado : EstadoRelatorio::Rascunho;
            if ($finalizar && blank($relatorio->numero)) {
                $relatorio->numero = $gerador->proximoNumero();
            }
            $relatorio->save();
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

    public function render()
    {
        $equipamentos = Equipamento::with('local.cliente')
            ->orderBy('numero_serie')
            ->get();

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

        return view('livewire.relatorios.novo', [
            'equipamentos' => $equipamentos,
            'tipos' => TipoIntervencao::cases(),
            'anexosExistentes' => $anexosExistentes,
            'cobertosSelecionados' => $cobertosSelecionados,
            'equipamentoPrincipal' => $equipamentoPrincipal,
            'contratos' => $contratos,
        ]);
    }
}
