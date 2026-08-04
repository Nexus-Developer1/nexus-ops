<?php

namespace App\Livewire\Despesas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Despesa;
use App\Models\FolhaDespesa;
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

    // ---- Folhas mensais por colaborador (espelho da folha impressa) ----

    public string $novaFolhaMes = '';
    public ?int $novaFolhaUserId = null;

    // Abre (ou cria) a folha do colaborador para o mês escolhido — idempotente: se já
    // existir, é reutilizada; uma folha apagada é restaurada (o unique não deixa duplicar).
    public function abrirFolha()
    {
        $this->validate([
            'novaFolhaMes' => ['required', 'date_format:Y-m'],
            'novaFolhaUserId' => ['required', 'integer',
                \Illuminate\Validation\Rule::exists('utilizadores', 'id')->where('ativo', true)->whereNot('papel', \App\Enums\PapelUtilizador::Cliente->value)],
        ]);

        [$ano, $mes] = array_map('intval', explode('-', $this->novaFolhaMes));

        $folha = FolhaDespesa::withTrashed()->firstOrNew([
            'user_id' => $this->novaFolhaUserId, 'ano' => $ano, 'mes' => $mes,
        ]);
        if ($folha->trashed()) {
            $folha->restore();
        }
        $folha->save();

        return redirect()->route('despesas.folha', $folha);
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

        // Folhas mensais mais recentes (colaborador + total já somado).
        $folhas = FolhaDespesa::query()
            ->with('colaborador')
            ->withSum('despesas', 'valor')
            ->orderByDesc('ano')->orderByDesc('mes')->orderBy('id')
            ->limit(24)
            ->get();

        if ($this->novaFolhaMes === '') {
            $this->novaFolhaMes = now()->format('Y-m');
        }
        $this->novaFolhaUserId ??= auth()->id();

        return view('livewire.despesas.listagem', [
            'despesas' => $despesas,
            'kpis' => $kpis,
            'categorias' => FolhaDespesa::COLUNAS, // colunas fixas da folha (filtro)
            'folhas' => $folhas,
            'colaboradores' => \App\Models\User::where('ativo', true)
                ->whereNot('papel', \App\Enums\PapelUtilizador::Cliente->value)
                ->orderBy('nome')->get(['id', 'nome']),
        ]);
    }
}
