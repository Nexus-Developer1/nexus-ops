<?php

namespace App\Livewire\Contratos;

use App\Enums\EstadoContrato;
use App\Models\Contrato;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['ativo' => 'contratos', 'titulo' => 'Contratos'])]
class Listagem extends Component
{
    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    #[Url]
    public string $estado = '';

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function filtrarEstado(string $estado): void
    {
        $this->estado = $estado;
        $this->resetPage();
    }

    // Soft delete (marca deleted_at) — recuperável; nunca DELETE físico nem detach.
    // As associações em contrato_equipamentos mantêm-se (o cascade só dispara em
    // DELETE físico) e os equipamentos continuam a existir.
    public function eliminar(int $contrato): void
    {
        $contrato = Contrato::findOrFail($contrato);
        $numero = $contrato->numero;

        $contrato->delete();

        session()->flash('sucesso', "Contrato {$numero} eliminado.");
    }

    public function render()
    {
        $contratos = Contrato::query()
            ->with('cliente', 'modeloFaturacao')
            ->withCount('equipamentos')
            ->when($this->estado === 'a_expirar', fn ($q) => $q->aExpirar())
            ->when($this->estado && $this->estado !== 'a_expirar', fn ($q) => $q->where('estado', $this->estado))
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(function ($q) use ($termo) {
                    $q->where('numero', 'ilike', $termo)
                        ->orWhereHas('cliente', fn ($q) => $q->where('nome', 'ilike', $termo));
                });
            })
            ->orderByDesc('data_inicio')
            ->paginate(10);

        // Contagem de contratos a expirar (para o banner de aviso).
        $aExpirar = Contrato::aExpirar()->count();

        return view('livewire.contratos.listagem', [
            'contratos' => $contratos,
            'estados' => EstadoContrato::cases(),
            'aExpirar' => $aExpirar,
        ]);
    }
}
