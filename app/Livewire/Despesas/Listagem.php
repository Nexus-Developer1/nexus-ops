<?php

namespace App\Livewire\Despesas;

use App\Enums\EstadoDespesa;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Despesa;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Services\Auditor;
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

    #[Session]
    public string $periodo = 'mes'; // mes | tudo

    // Processo de validação: filtrar pelo estado (pendente/aprovada/rejeitada) e pelo
    // colaborador — "só as que estão atribuídas a determinada pessoa".
    #[Session]
    public string $estado = ''; // '' | pendente | aprovada | rejeitada

    #[Session]
    public string $colaborador = ''; // id do utilizador

    public function updatingEstado(): void
    {
        $this->resetPage();
    }

    public function updatingColaborador(): void
    {
        $this->resetPage();
    }

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
            ->when($this->categoria, fn ($q) => $q->where('categoria', $this->categoria))
            ->when(in_array($this->estado, array_column(EstadoDespesa::cases(), 'value'), true),
                fn ($q) => $q->whereHas('registo', fn ($r) => $r->where('estado', $this->estado)))
            ->when(ctype_digit($this->colaborador), fn ($q) => $q->whereHas('registo', fn ($r) => $r->where('criado_por', (int) $this->colaborador)))
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

        // Total por CATEGORIA no período/filtros (combustíveis, refeições, portagens…) —
        // é o único resumo que fica na página. Ordenado do maior para o menor.
        $porCategoria = $this->base()
            ->selectRaw('categoria, sum(valor) as total, count(*) as n')
            ->groupBy('categoria')
            ->orderByDesc('total')
            ->get();

        return view('livewire.despesas.listagem', [
            'registos' => $registos,
            'porCategoria' => $porCategoria,
            'categorias' => Despesa::CATEGORIAS, // categorias fixas (filtro)
            'estados' => EstadoDespesa::cases(),
            // Colaboradores com registos (filtro "atribuídas a determinada pessoa").
            'colaboradores' => User::whereIn('id', RegistoDespesa::query()->whereNotNull('criado_por')->select('criado_por'))->orderBy('nome')->get(['id', 'nome']),
            'pendentes' => RegistoDespesa::where('estado', EstadoDespesa::Pendente->value)->count(),
        ]);
    }
}
