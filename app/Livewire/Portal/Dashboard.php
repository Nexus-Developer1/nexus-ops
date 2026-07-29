<?php

namespace App\Livewire\Portal;

use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Relatorio;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Painel inicial do portal de cliente. Todas as queries são filtradas ao cliente
// autenticado pelo global scope RestritoAoCliente (isolamento na camada de dados).
#[Layout('components.layouts.portal', ['ativo' => 'inicio', 'titulo' => 'Início'])]
class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.portal.dashboard', [
            'numEquipamentos' => Equipamento::count(),
            'proximasVisitas' => EventoAgenda::query()
                ->where('inicio', '>=', now())
                ->orderBy('inicio')
                ->limit(5)
                ->get(),
            // Só ENVIADOS — igual à listagem do portal (o cliente nunca vê trabalho interno).
            'relatoriosRecentes' => Relatorio::query()
                ->where('estado', \App\Enums\EstadoRelatorio::Enviado)
                ->with('intervencao.equipamento')
                ->orderByDesc('data')
                ->limit(5)
                ->get(),
        ]);
    }
}
