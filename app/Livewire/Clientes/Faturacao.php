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
                $termo = '%'.$this->pesquisa.'%';
                $q->where(function ($q) use ($termo) {
                    $q->where('design', 'ilike', $termo)
                        ->orWhere('ref', 'ilike', $termo)
                        ->orWhere('series', 'ilike', $termo);
                });
            })
            ->orderByDesc('data')
            ->paginate(25);

        return view('livewire.clientes.faturacao', [
            'linhas' => $linhas,
        ]);
    }
}
