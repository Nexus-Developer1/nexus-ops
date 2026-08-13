<?php

namespace App\Livewire\Clientes;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Cliente;
use App\Models\Relatorio;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Lista completa (paginada) dos relatórios de um cliente — "Ver todos" do detalhe.
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Relatórios do cliente'])]
class Relatorios extends Component
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
        // Cadeia relatorio -> intervencao -> equipamento -> local -> cliente.
        $relatorios = Relatorio::query()
            ->whereHas('intervencao.equipamento.local', fn ($q) => $q->where('cliente_id', $this->cliente->id))
            ->with('intervencao.equipamento')
            ->when($this->pesquisa, fn ($q) => $q->where('numero', 'ilike', '%'.$this->pesquisa.'%'))
            ->orderByDesc('data')
            ->paginate(20);

        return view('livewire.clientes.relatorios', [
            'relatorios' => $relatorios,
        ]);
    }
}
