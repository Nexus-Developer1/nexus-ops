<?php

namespace App\Livewire\Clientes;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Cliente;
use App\Models\Equipamento;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Lista completa (paginada) dos equipamentos de um cliente — "Ver todos" do detalhe.
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Equipamentos do cliente'])]
class Equipamentos extends Component
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
        $equipamentos = Equipamento::query()
            ->whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente->id))
            ->with('local')
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(function ($q) use ($termo) {
                    $q->where('numero_serie', 'ilike', $termo)
                        ->orWhere('modelo', 'ilike', $termo)
                        ->orWhere('fabricante', 'ilike', $termo);
                });
            })
            ->orderBy('id')
            ->paginate(20);

        return view('livewire.clientes.equipamentos', [
            'equipamentos' => $equipamentos,
        ]);
    }
}
