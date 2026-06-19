<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoIntervencao;
use App\Enums\EstadoRelatorio;
use App\Enums\TipoIntervencao;
use App\Jobs\GerarRelatorioPdf;
use App\Models\Anexo;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Relatorio;
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
    public ?int $equipamento_id = null;
    public string $tipo = 'preventiva';
    public string $data = '';

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

            $intervencao = $relatorio->intervencao()->with('checklistEtapas.itens')->firstOrFail();
            $this->relatorioId = $relatorio->id;
            $this->intervencaoId = $intervencao->id;
            $this->equipamento_id = $intervencao->equipamento_id;
            $this->tipo = $intervencao->tipo->value;
            $this->data = $intervencao->data_inicio?->format('Y-m-d') ?? '';
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
            'fotos.*' => ['image', 'max:8192'], // 8 MB
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
    public function guardarRascunho(GeradorRelatorio $gerador)
    {
        return $this->persistir($gerador, false);
    }

    public function finalizar(GeradorRelatorio $gerador)
    {
        return $this->persistir($gerador, true);
    }

    private function persistir(GeradorRelatorio $gerador, bool $finalizar)
    {
        if ($finalizar) {
            // Validação completa.
            $this->validate();
        } else {
            // Rascunho: o único obrigatório é o equipamento.
            $this->validate([
                'equipamento_id' => ['required', 'integer', 'exists:equipamentos,id'],
                'fotos.*' => ['image', 'max:8192'],
            ]);
        }

        $relatorio = DB::transaction(function () use ($gerador, $finalizar) {
            $dados = [
                'equipamento_id' => $this->equipamento_id,
                'tecnico_id' => auth()->id(),
                'tipo' => $this->tipo,
                'estado' => $finalizar ? EstadoIntervencao::Concluida : EstadoIntervencao::EmCurso,
                'data_inicio' => $this->data ?: null,
                'data_fim' => $finalizar ? now() : null,
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

        return view('livewire.relatorios.novo', [
            'equipamentos' => $equipamentos,
            'tipos' => TipoIntervencao::cases(),
            'anexosExistentes' => $anexosExistentes,
        ]);
    }
}
