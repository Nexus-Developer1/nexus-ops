<?php

namespace App\Livewire\Clientes;

use App\Models\Cliente;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Consulta de clientes (só leitura). Os clientes vêm do ERP por sync e são
// read-only na aplicação (CLAUDE.md §2). Sem criar/editar/apagar.
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Clientes'])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    // Cliente selecionado para a ficha (null = nenhum aberto).
    public ?int $clienteId = null;

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function selecionar(int $id): void
    {
        $this->clienteId = $id;
    }

    public function fechar(): void
    {
        $this->clienteId = null;
    }

    public function render()
    {
        $clientes = Cliente::query()
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                // Pesquisa parcial e case-insensitive por nome, NIF e email.
                $q->where(function ($q) use ($termo) {
                    $q->where('nome', 'ilike', $termo)
                        ->orWhere('nif', 'ilike', $termo)
                        ->orWhere('email', 'ilike', $termo);
                });
            })
            ->orderBy('nome')
            ->paginate(15);

        return view('livewire.clientes.index', [
            'clientes' => $clientes,
            'cliente' => $this->clienteId ? Cliente::find($this->clienteId) : null,
        ]);
    }
}
