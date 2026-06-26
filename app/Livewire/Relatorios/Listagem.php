<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoRelatorio;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Models\Relatorio;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['ativo' => 'relatorios', 'titulo' => 'Relatórios'])]
class Listagem extends Component
{
    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    #[Url]
    public string $estado = '';

    // Tipo de relatório: '' (todos) | 'contrato' | 'individual'. Distingue-se pelo
    // intervencao.contrato_id (preenchido = de contrato; null = individual).
    #[Url]
    public string $tipo = '';

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function filtrarEstado(string $estado): void
    {
        $this->estado = $estado;
        $this->resetPage();
    }

    public function filtrarTipo(string $tipo): void
    {
        $this->tipo = $tipo;
        $this->resetPage();
    }

    // Despacha o envio do relatório ao cliente (job assíncrono — CLAUDE.md §12).
    public function enviar(int $relatorio): void
    {
        $relatorio = Relatorio::with('intervencao.equipamento.local.cliente')->findOrFail($relatorio);

        $email = $relatorio->intervencao->equipamento->local->cliente->email;

        if (blank($email)) {
            session()->flash('erro', "O cliente do relatório {$relatorio->numero} não tem email definido.");

            return;
        }

        EnviarRelatorioPorEmail::dispatch($relatorio);

        session()->flash('sucesso', "Relatório {$relatorio->numero} em envio para {$email}.");
    }

    // Soft delete (marca deleted_at) — recuperável; nunca DELETE físico nem apaga o PDF.
    public function eliminar(int $relatorio): void
    {
        $relatorio = Relatorio::findOrFail($relatorio);
        $rotulo = $relatorio->numero ?? 'rascunho';

        $relatorio->delete();

        session()->flash('sucesso', "Relatório {$rotulo} eliminado.");
    }

    public function render()
    {
        $relatorios = Relatorio::query()
            ->with('intervencao.equipamento.local.cliente', 'intervencao.tecnico')
            ->when($this->estado, fn ($q) => $q->where('estado', $this->estado))
            ->when($this->tipo === 'contrato', fn ($q) => $q->whereHas('intervencao', fn ($q) => $q->whereNotNull('contrato_id')))
            ->when($this->tipo === 'individual', fn ($q) => $q->whereHas('intervencao', fn ($q) => $q->whereNull('contrato_id')))
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(function ($q) use ($termo) {
                    $q->where('numero', 'ilike', $termo)
                        ->orWhereHas('intervencao.equipamento.local.cliente', fn ($q) => $q->where('nome', 'ilike', $termo))
                        ->orWhereHas('intervencao.tecnico', fn ($q) => $q->where('nome', 'ilike', $termo));
                });
            })
            ->orderByDesc('data')
            ->paginate(10);

        return view('livewire.relatorios.listagem', [
            'relatorios' => $relatorios,
            'estados' => EstadoRelatorio::cases(),
        ]);
    }
}
