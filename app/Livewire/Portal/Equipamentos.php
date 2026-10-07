<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\ApenasCliente;
use App\Livewire\Concerns\LembraPagina;
use App\Models\Equipamento;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Os equipamentos do cliente autenticado (só leitura). Filtrado pelo global scope.
#[Layout('components.layouts.portal', ['ativo' => 'equipamentos', 'titulo' => 'Equipamentos'])]
class Equipamentos extends Component
{
    use ApenasCliente;
    use LembraPagina;
    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $consulta = Equipamento::query()
            ->with('local')
            ->when($this->pesquisa, function ($q) {
                $termo = '%'.$this->pesquisa.'%';
                $q->where(fn ($q) => $q->where('numero_serie', 'ilike', $termo)->orWhere('modelo', 'ilike', $termo));
            })
            ->orderBy('id');
        $equipamentos = $this->paginarLembrando($consulta, 10);

        return view('livewire.portal.equipamentos', ['equipamentos' => $equipamentos]);
    }
}
