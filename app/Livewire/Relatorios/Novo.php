<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoIntervencao;
use App\Enums\TipoIntervencao;
use App\Jobs\GerarRelatorioPdf;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Services\GeradorRelatorio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

// "Novo Relatório": formulário de criação. Cria a intervenção (folha de obra),
// conclui-a e emite o relatório — cadeia do CLAUDE.md §6 (intervenção -> relatório).
#[Layout('components.layouts.app', ['ativo' => 'relatorios', 'titulo' => 'Novo relatório'])]
class Novo extends Component
{
    use WithFileUploads;

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

    // ---- Fotos (uploads temporários até submeter) ----
    public array $fotos = [];

    public function mount(): void
    {
        $this->data = now()->format('Y-m-d');

        // Etapa inicial com itens predefinidos (preventiva genérica).
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

    private function novoUid(): string
    {
        return (string) Str::uuid();
    }

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
    // Recebe do SortableJS a nova sequência: [['etapa'=>uid, 'itens'=>[itemUid,...]], ...].
    // Reconstrói $etapas por uid, preservando os valores (título, descrição, etc.) e
    // suportando mover itens entre etapas.
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

        // Salvaguarda: só aplica se o payload cobrir todas as etapas e itens (sem perdas).
        $totalItensDepois = array_sum(array_map(fn ($e) => count($e['itens']), $novas));
        if (count($novas) === count($this->etapas) && $totalItensDepois === count($itensPorUid)) {
            $this->etapas = $novas;
        }
    }

    // Cria a folha de obra, conclui-a e emite o relatório (PDF em job assíncrono §12).
    public function submeter(GeradorRelatorio $gerador)
    {
        $this->validate();

        $relatorio = DB::transaction(function () use ($gerador) {
            $intervencao = Intervencao::create([
                'equipamento_id' => $this->equipamento_id,
                'tecnico_id' => auth()->id(),
                'tipo' => $this->tipo,
                'estado' => EstadoIntervencao::Concluida,
                'data_inicio' => $this->data,
                'data_fim' => now(),
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
            ]);

            // Etapas + itens, com a ordem preservada. Ignora etapas sem título nem itens.
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

            // Fotos: persistidas no object storage só agora (a intervenção já existe).
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

            return $gerador->criarParaIntervencao($intervencao);
        });

        GerarRelatorioPdf::dispatch($relatorio);

        session()->flash('sucesso', "Relatório {$relatorio->numero} criado. O PDF está a ser gerado.");

        return redirect()->route('relatorios');
    }

    public function render()
    {
        // Equipamentos disponíveis para o utilizador (global scopes aplicam isolamento).
        $equipamentos = Equipamento::with('local.cliente')
            ->orderBy('numero_serie')
            ->get();

        return view('livewire.relatorios.novo', [
            'equipamentos' => $equipamentos,
            'tipos' => TipoIntervencao::cases(),
        ]);
    }
}
