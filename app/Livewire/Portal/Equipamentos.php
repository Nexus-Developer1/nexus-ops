<?php

namespace App\Livewire\Portal;

use App\Models\Equipamento;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Os equipamentos do cliente autenticado (só leitura). Filtrado pelo global scope.
#[Layout('components.layouts.portal', ['ativo' => 'equipamentos', 'titulo' => 'Equipamentos'])]
class Equipamentos extends Component
{
    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $equipamentos = Equipamento::query()
            ->with('local')
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(fn ($q) => $q->where('numero_serie', 'ilike', $termo)->orWhere('modelo', 'ilike', $termo));
            })
            ->orderBy('id')
            ->paginate(10);

        return view('livewire.portal.equipamentos', ['equipamentos' => $equipamentos]);
    }
}
