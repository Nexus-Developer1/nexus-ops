<?php

namespace App\Livewire\Painel;

use App\Enums\EstadoIntervencao;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Relatorio;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Painel inicial do técnico (CLAUDE.md §7): a SUA agenda, intervenções e relatórios.
// Todas as queries são filtradas ao técnico autenticado pelo global scope RestritoAoTecnico.
#[Layout('components.layouts.app', ['ativo' => 'painel', 'titulo' => 'Painel'])]
class Tecnico extends Component
{
    public function render()
    {
        return view('livewire.painel.tecnico', [
            'proximasVisitas' => EventoAgenda::query()
                ->where('inicio', '>=', now()->startOfDay())
                ->orderBy('inicio')
                ->limit(8)
                ->get(),
            'emCurso' => Intervencao::query()
                ->where('estado', EstadoIntervencao::EmCurso->value)
                ->with('equipamento.local.cliente')
                ->latest('data_inicio')
                ->get(),
            'relatoriosRecentes' => Relatorio::query()
                ->with('intervencao.equipamento')
                ->orderByDesc('data')
                ->limit(5)
                ->get(),
        ]);
    }
}
