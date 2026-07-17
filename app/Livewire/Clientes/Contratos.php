<?php

namespace App\Livewire\Clientes;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Cliente;
use App\Models\Contrato;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Lista completa (paginada) dos contratos de um cliente — "Ver todos" do detalhe.
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Contratos do cliente'])]
class Contratos extends Component
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
        $contratos = Contrato::query()
            ->where('cliente_id', $this->cliente->id)
            ->with('modeloFaturacao')
            ->when($this->pesquisa, fn ($q) => $q->where('numero', 'ilike', '%' . $this->pesquisa . '%'))
            ->orderByDesc('data_inicio')
            ->paginate(20);

        return view('livewire.clientes.contratos', [
            'contratos' => $contratos,
        ]);
    }
}
