<?php

namespace App\Livewire\Portal;

use App\Models\Relatorio;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

// Os relatórios do cliente autenticado (só leitura + download do PDF).
#[Layout('components.layouts.portal', ['ativo' => 'relatorios', 'titulo' => 'Relatórios'])]
class Relatorios extends Component
{
    use WithPagination;

    public function render()
    {
        $relatorios = Relatorio::query()
            ->with('intervencao.equipamento.local')
            ->orderByDesc('data')
            ->paginate(10);

        return view('livewire.portal.relatorios', ['relatorios' => $relatorios]);
    }
}
