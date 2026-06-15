<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoIntervencao;
use App\Enums\TipoIntervencao;
use App\Jobs\GerarRelatorioPdf;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Services\GeradorRelatorio;
use Illuminate\Support\Facades\DB;
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

    // ---- Checklist (itens predefinidos, editáveis) ----
    public array $checklist = [];

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

        // Itens de checklist iniciais (preventiva genérica).
        $this->checklist = [
            ['descricao' => 'Inspeção visual', 'concluido' => false, 'observacao' => ''],
            ['descricao' => 'Verificação de funcionamento', 'concluido' => false, 'observacao' => ''],
            ['descricao' => 'Limpeza geral', 'concluido' => false, 'observacao' => ''],
        ];
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

    public function adicionarItem(): void
    {
        $this->checklist[] = ['descricao' => '', 'concluido' => false, 'observacao' => ''];
    }

    public function removerItem(int $i): void
    {
        unset($this->checklist[$i]);
        $this->checklist = array_values($this->checklist);
    }

    // Cria a folha de obra, conclui-a e emite o relatório (PDF em job assíncrono §12).
    public function submeter(GeradorRelatorio $gerador)
    {
        $this->validate();

        $relatorio = DB::transaction(function () {
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

            // Itens de checklist preenchidos (ignora linhas sem descrição).
            foreach (array_values($this->checklist) as $ordem => $item) {
                if (trim($item['descricao'] ?? '') === '') {
                    continue;
                }

                $intervencao->checklistItens()->create([
                    'descricao' => trim($item['descricao']),
                    'concluido' => (bool) ($item['concluido'] ?? false),
                    'observacao' => trim($item['observacao'] ?? '') ?: null,
                    'ordem' => $ordem,
                ]);
            }

            // Fotos: persistidas no object storage só agora (a intervenção já existe).
            foreach ($this->fotos as $foto) {
                $key = $foto->store('anexos/intervencoes/' . $intervencao->id, 's3');

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
