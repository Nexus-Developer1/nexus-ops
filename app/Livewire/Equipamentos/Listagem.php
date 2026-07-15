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

    // Filtro por família do artigo (faminome, vem do PHC) — ex.: ver só UPS, esconder "Peças".
    #[Url]
    public string $familia = '';

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function updatingFamilia(): void
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
            ->when($this->familia, fn ($q) => $q->where('faminome', $this->familia))
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

        // Famílias disponíveis (nomes distintos já presentes) para o dropdown do filtro.
        $familias = Equipamento::query()
            ->whereNotNull('faminome')
            ->distinct()
            ->orderBy('faminome')
            ->pluck('faminome');

        return view('livewire.equipamentos.listagem', [
            'equipamentos' => $equipamentos,
            'tipos' => TipoEquipamento::cases(),
            'familias' => $familias,
        ]);
    }
}
