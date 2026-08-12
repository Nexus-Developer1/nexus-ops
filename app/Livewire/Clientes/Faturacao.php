<?php

namespace App\Livewire\Clientes;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Cliente;
use App\Models\LinhaFatura;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Lista completa (paginada) das linhas de faturação de um cliente — "Ver todas" do
// detalhe. Ligação: linhas_fatura.cliente_no = clientes.id_erp (nº de cliente do PHC).
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Faturação do cliente'])]
class Faturacao extends Component
{
    use ApenasEquipa;

    use WithPagination;

    public Cliente $cliente;

    #[Url]
    public string $pesquisa = '';

    public function mount(Cliente $cliente): void
    {
        $this->cliente = $cliente;
    }

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $linhas = LinhaFatura::query()
            ->where('cliente_no', $this->cliente->id_erp)
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(function ($q) use ($termo) {
                    $q->where('design', 'ilike', $termo)
                        ->orWhere('ref', 'ilike', $termo)
                        ->orWhere('series', 'ilike', $termo);
                });
            })
            ->orderByDesc('data')
            ->paginate(25);

        // Valores só para ADMIN (12/08): total faturado nos últimos 12 meses, por DOCUMENTO
        // (o total vem denormalizado em cada linha — agrupa por documento e soma um total
        // por documento), excluindo anuladas. Técnicos veem o histórico sem €.
        $ehAdmin = (bool) auth()->user()?->ehAdmin();
        $totais = null;
        if ($ehAdmin) {
            $docs = LinhaFatura::query()
                ->where('cliente_no', $this->cliente->id_erp)
                ->where('anulada', false)
                ->whereNotNull('total_documento_iva')
                ->whereDate('data', '>=', now()->subMonths(12))
                ->selectRaw('nmdoc, fno, data, max(total_documento_iva) as total')
                ->groupBy('nmdoc', 'fno', 'data');

            $totais = [
                'ano' => (float) \Illuminate\Support\Facades\DB::query()->fromSub($docs, 'docs')->sum('total'),
                'docs' => \Illuminate\Support\Facades\DB::query()->fromSub($docs, 'docs')->count(),
            ];
        }

        return view('livewire.clientes.faturacao', [
            'linhas' => $linhas,
            'ehAdmin' => $ehAdmin,
            'totais' => $totais,
        ]);
    }
}
