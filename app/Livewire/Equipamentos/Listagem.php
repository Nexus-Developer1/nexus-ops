<?php

namespace App\Livewire\Equipamentos;

use App\Enums\TipoEquipamento;
use App\Models\Equipamento;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['ativo' => 'ativos', 'titulo' => 'Ativos'])]
class Listagem extends Component
{
    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    #[Url]
    public string $tipo = '';

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function filtrarTipo(string $tipo): void
    {
        $this->tipo = $tipo;
        $this->resetPage();
    }

    public function render()
    {
        $equipamentos = Equipamento::query()
            ->with('local.cliente')
            ->when($this->tipo, fn ($q) => $q->where('tipo', $this->tipo))
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(function ($q) use ($termo) {
                    $q->where('numero_serie', 'ilike', $termo)
                        ->orWhere('modelo', 'ilike', $termo)
                        ->orWhereHas('local.cliente', fn ($q) => $q->where('nome', 'ilike', $termo));
                });
            })
            ->orderBy('id')
            ->paginate(10);

        return view('livewire.equipamentos.listagem', [
            'equipamentos' => $equipamentos,
            'tipos' => TipoEquipamento::cases(),
        ]);
    }
}
