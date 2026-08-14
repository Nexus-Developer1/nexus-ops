<?php

namespace App\Livewire\Despesas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Despesa;
use App\Models\RegistoDespesa;
use App\Services\Auditor;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Despesas'])]
class Listagem extends Component
{
    use ApenasEquipa;
    use WithPagination;

    #[Session]
    public string $pesquisa = '';

    #[Session]
    public string $categoria = '';

    // Período: 'mes' (mês corrente), 'tudo', ou 'AAAA-MM' de um mês específico (fecho mensal).
    #[Session]
    public string $periodo = 'mes';

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

    /** Meses com despesas (para o seletor de fecho mensal), do mais recente ao mais antigo. */
    private function mesesDisponiveis(): Collection
    {
        return Despesa::query()
            ->selectRaw("to_char(data, 'YYYY-MM') as mes")
            ->distinct()
            ->orderByDesc('mes')
            ->pluck('mes');
    }

    // Elimina um REGISTO inteiro (documento + linhas) — soft delete (recuperável).
    public function eliminar(int $registo): void
    {
        $registo = RegistoDespesa::findOrFail($registo);
        $registo->despesas()->delete();
        $registo->delete();
        Auditor::registar('registo_despesas_eliminado', $registo, ['linhas' => $registo->despesas()->withTrashed()->count()]);
        session()->flash('sucesso', 'Registo de despesas eliminado.');
    }

    // Query base com os filtros aplicados (reutilizada para KPIs, totais e listagem).
    // Estas despesas são custos de deslocação/serviço do técnico — NÃO estão ligadas a um
    // cliente. A pesquisa procura na descrição e no NOME DO COLABORADOR (quem fez a despesa),
    // não num cliente inexistente.
    private function base()
    {
        return Despesa::query()
            ->when($this->periodo === 'mes', fn ($q) => $q->whereYear('data', now()->year)->whereMonth('data', now()->month))
            ->when(
                preg_match('/^\d{4}-\d{2}$/', $this->periodo),
                fn ($q) => $q->whereYear('data', (int) substr($this->periodo, 0, 4))
                    ->whereMonth('data', (int) substr($this->periodo, 5, 2)),
            )
            ->when($this->categoria, fn ($q) => $q->where('categoria', $this->categoria))
            ->when($this->pesquisa, function ($q) {
                $termo = '%'.$this->pesquisa.'%';
                $q->where(fn ($q) => $q->where('descricao', 'ilike', $termo)
                    ->orWhere('detalhe', 'ilike', $termo)
                    ->orWhereHas('registo.colaborador', fn ($q) => $q->where('nome', 'ilike', $termo)));
            });
    }

    public function render()
    {
        // A listagem mostra REGISTOS (uma entrada por documento); os filtros aplicam-se às
        // linhas (despesas) — um registo aparece se alguma linha lhe corresponder.
        $registos = RegistoDespesa::query()
            ->with(['colaborador', 'despesas'])
            ->whereHas('despesas', function ($q) {
                $filtrada = $this->base();
                $q->whereIn('id', $filtrada->select('id'));
            })
            ->orderByDesc('id')
            ->paginate(12);

        // KPIs sobre as LINHAS filtradas (cada base() devolve uma query nova).
        $kpis = [
            'total' => (float) $this->base()->sum('valor'),
            'numero' => $this->base()->count(),
        ];

        // Total por CATEGORIA no período/filtros — o detalhe que a contabilidade quer ver
        // num relance (combustíveis, refeições, portagens…). Ordenado do maior para o menor.
        $porCategoria = $this->base()
            ->selectRaw('categoria, sum(valor) as total, count(*) as n')
            ->groupBy('categoria')
            ->orderByDesc('total')
            ->get();

        return view('livewire.despesas.listagem', [
            'registos' => $registos,
            'kpis' => $kpis,
            'porCategoria' => $porCategoria,
            'meses' => $this->mesesDisponiveis(),
            'categorias' => Despesa::CATEGORIAS, // categorias fixas (filtro)
        ]);
    }
}
