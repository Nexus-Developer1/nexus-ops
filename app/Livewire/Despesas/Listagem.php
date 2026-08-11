<?php

namespace App\Livewire\Despesas;

use App\Livewire\Concerns\ApenasEquipa;
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
    public string $periodo = 'mes'; // mes | tudo

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function updatingCategoria(): void
    {
        $this->resetPage();
    }

    public function updatingPeriodo(): void
    {
        $this->resetPage();
    }

    // Elimina um REGISTO inteiro (documento + linhas) — soft delete (recuperável).
    public function eliminar(int $registo): void
    {
        $registo = \App\Models\RegistoDespesa::findOrFail($registo);
        $registo->despesas()->delete();
        $registo->delete();
        session()->flash('sucesso', 'Registo de despesas eliminado.');
    }

    // Query base com os filtros aplicados (reutilizada para KPIs e listagem).
    private function base()
    {
        return Despesa::query()
            ->when($this->periodo === 'mes', fn ($q) => $q->whereYear('data', now()->year)->whereMonth('data', now()->month))
            ->when($this->categoria, fn ($q) => $q->where('categoria', $this->categoria))
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(fn ($q) => $q->where('descricao', 'ilike', $termo)
                    ->orWhereHas('cliente', fn ($q) => $q->where('nome', 'ilike', $termo)));
            });
    }

    public function render()
    {
        // A listagem mostra REGISTOS (uma entrada por documento); os filtros aplicam-se às
        // linhas (despesas) — um registo aparece se alguma linha lhe corresponder.
        $registos = \App\Models\RegistoDespesa::query()
            ->with(['colaborador', 'despesas'])
            ->whereHas('despesas', function ($q) {
                $filtrada = $this->base();
                $q->whereIn('id', $filtrada->select('id'));
            })
            ->orderByDesc('id')
            ->paginate(12);

        // KPIs sobre as LINHAS filtradas (cada base() devolve uma query nova). Os KPIs de
        // faturável/incluído saíram a pedido da equipa, com o filtro respetivo.
        $kpis = [
            'total' => (float) $this->base()->sum('valor'),
            'numero' => $this->base()->count(),
        ];

        return view('livewire.despesas.listagem', [
            'registos' => $registos,
            'kpis' => $kpis,
            'categorias' => Despesa::CATEGORIAS, // categorias fixas (filtro)
        ]);
    }
}
