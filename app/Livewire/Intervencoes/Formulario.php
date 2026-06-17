<?php

namespace App\Livewire\Intervencoes;

use App\Enums\EstadoIntervencao;
use App\Jobs\GerarRelatorioPdf;
use App\Models\Intervencao;
use App\Services\GeradorRelatorio;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app', ['ativo' => 'relatorios'])]
class Formulario extends Component
{
    use WithFileUploads;

    public Intervencao $intervencao;

    // Campos da intervenção
    public string $descricao_problema = '';
    public string $trabalho_realizado = '';
    public string $observacoes = '';

    // Diagnóstico
    public string $estado_geral = '';
    public string $carga = '';
    public string $tensao_entrada = '';
    public string $tensao_saida = '';
    public string $anomalias = '';

    // Checklist
    public string $novoItem = '';

    // Fotos (uploads temporários)
    public array $fotos = [];

    public bool $guardado = false;

    public function mount(Intervencao $intervencao): void
    {
        $this->intervencao = $intervencao->load('equipamento.local.cliente', 'tecnico', 'checklistItens', 'anexos');

        $this->descricao_problema = $intervencao->descricao_problema ?? '';
        $this->trabalho_realizado = $intervencao->trabalho_realizado ?? '';
        $this->observacoes = $intervencao->observacoes ?? '';

        $d = $intervencao->diagnostico ?? [];
        $this->estado_geral = $d['estado_geral'] ?? '';
        $this->carga = $d['carga'] ?? '';
        $this->tensao_entrada = $d['tensao_entrada'] ?? '';
        $this->tensao_saida = $d['tensao_saida'] ?? '';
        $this->anomalias = $d['anomalias'] ?? '';
    }

    // Auto-save: persiste sempre que um campo de texto/diagnóstico muda.
    public function updated(string $prop): void
    {
        $campos = ['descricao_problema', 'trabalho_realizado', 'observacoes', 'estado_geral', 'carga', 'tensao_entrada', 'tensao_saida', 'anomalias'];

        if (in_array($prop, $campos, true)) {
            $this->guardar();
        }
    }

    public function guardar(): void
    {
        $this->intervencao->update([
            'descricao_problema' => $this->descricao_problema ?: null,
            'trabalho_realizado' => $this->trabalho_realizado ?: null,
            'observacoes' => $this->observacoes ?: null,
            'diagnostico' => array_filter([
                'estado_geral' => $this->estado_geral ?: null,
                'carga' => $this->carga ?: null,
                'tensao_entrada' => $this->tensao_entrada ?: null,
                'tensao_saida' => $this->tensao_saida ?: null,
                'anomalias' => $this->anomalias ?: null,
            ]),
        ]);

        $this->guardado = true;
    }

    // ---- Checklist ----
    public function adicionarItem(): void
    {
        if (trim($this->novoItem) === '') {
            return;
        }

        $this->intervencao->checklistItens()->create([
            'descricao' => trim($this->novoItem),
            'ordem' => $this->intervencao->checklistItens()->count(),
        ]);

        $this->novoItem = '';
        $this->intervencao->load('checklistItens');
    }

    public function alternarItem(int $id): void
    {
        $item = $this->intervencao->checklistItens()->find($id);
        $item?->update(['concluido' => ! $item->concluido]);
        $this->intervencao->load('checklistItens');
    }

    public function removerItem(int $id): void
    {
        $this->intervencao->checklistItens()->whereKey($id)->delete();
        $this->intervencao->load('checklistItens');
    }

    // ---- Fotos ----
    public function updatedFotos(): void
    {
        $this->validate(['fotos.*' => 'image|max:8192']); // 8 MB

        foreach ($this->fotos as $foto) {
            $key = $foto->store('anexos/intervencoes/' . $this->intervencao->id);

            $this->intervencao->anexos()->create([
                'nome_ficheiro' => $foto->getClientOriginalName(),
                'storage_key' => $key,
                'mime' => $foto->getMimeType(),
                'tamanho' => $foto->getSize(),
                'criado_por' => auth()->id(),
            ]);
        }

        $this->fotos = [];
        $this->intervencao->load('anexos');
    }

    public function removerFoto(int $id): void
    {
        $anexo = $this->intervencao->anexos()->find($id);

        if ($anexo) {
            Storage::disk()->delete($anexo->storage_key);
            $anexo->delete();
        }

        $this->intervencao->load('anexos');
    }

    // ---- Finalizar ----
    public function finalizar(GeradorRelatorio $gerador)
    {
        $this->guardar();

        $this->intervencao->update([
            'estado' => EstadoIntervencao::Concluida,
            'data_fim' => $this->intervencao->data_fim ?? now(),
        ]);

        // Fecha o evento de agenda que originou a intervenção (regra de ouro §6).
        // Ignora global scopes — é uma transição de sistema, não uma navegação.
        if ($this->intervencao->evento_agenda_id) {
            \App\Models\EventoAgenda::withoutGlobalScopes()
                ->whereKey($this->intervencao->evento_agenda_id)
                ->update(['estado' => \App\Enums\EstadoEvento::Concluido->value]);
        }

        $relatorio = $gerador->criarParaIntervencao($this->intervencao);
        GerarRelatorioPdf::dispatch($relatorio);

        session()->flash('sucesso', "Relatório {$relatorio->numero} finalizado. O PDF está a ser gerado.");

        return redirect()->route('relatorios');
    }

    public function render()
    {
        return view('livewire.intervencoes.formulario');
    }
}
