<?php

namespace App\Livewire\Despesas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\CategoriaDespesa;
use App\Models\Despesa;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Despesas'])]
class Listagem extends Component
{
    use ApenasEquipa;

    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    #[Url]
    public string $categoria = '';

    #[Url]
    public string $faturavel = ''; // '' | sim | nao

    #[Url]
    public string $periodo = 'mes'; // mes | tudo

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function updatingCategoria(): void
    {
        $this->resetPage();
    }

    public function updatingFaturavel(): void
    {
        $this->resetPage();
    }

    public function updatingPeriodo(): void
    {
        $this->resetPage();
    }

    public function eliminar(int $despesa): void
    {
        Despesa::findOrFail($despesa)->delete(); // soft delete (recuperável)
        session()->flash('sucesso', 'Despesa eliminada.');
    }

    // Query base com os filtros aplicados (reutilizada para KPIs e listagem).
    private function base()
    {
        return Despesa::query()
            ->when($this->periodo === 'mes', fn ($q) => $q->whereYear('data', now()->year)->whereMonth('data', now()->month))
            ->when($this->categoria, fn ($q) => $q->where('categoria', $this->categoria))
            ->when($this->faturavel === 'sim', fn ($q) => $q->where('faturavel', true))
            ->when($this->faturavel === 'nao', fn ($q) => $q->where('faturavel', false))
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(fn ($q) => $q->where('descricao', 'ilike', $termo)
                    ->orWhereHas('cliente', fn ($q) => $q->where('nome', 'ilike', $termo)));
            });
    }

    public function render()
    {
        $despesas = $this->base()
            ->with('cliente', 'equipamento')
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->paginate(12);

        // KPIs sobre o conjunto filtrado (cada base() devolve uma query nova).
        $kpis = [
            'total' => (float) $this->base()->sum('valor'),
            'faturavel' => (float) $this->base()->where('faturavel', true)->sum('valor'),
            'incluido' => (float) $this->base()->where('faturavel', false)->sum('valor'),
            'numero' => $this->base()->count(),
        ];

        return view('livewire.despesas.listagem', [
            'despesas' => $despesas,
            'kpis' => $kpis,
            'categorias' => CategoriaDespesa::orderBy('nome')->get(),
        ]);
    }
}
