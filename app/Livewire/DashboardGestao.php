<?php

namespace App\Livewire;

use App\Services\Alertas\ServicoAlertas;
use App\Services\Gestao\ServicoMetricas;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Dashboard de gestão (CLAUDE.md §6): KPIs, rentabilidade de visitas, cumprimento
// de SLA, renovações próximas e equipamentos sem visitas recentes.
#[Layout('components.layouts.app', ['ativo' => 'dashboard', 'titulo' => 'Dashboard'])]
class DashboardGestao extends Component
{
    public function render(ServicoMetricas $metricas, ServicoAlertas $alertas)
    {
        return view('livewire.dashboard-gestao', [
            'resumo' => $metricas->resumo(),
            'renovacoes' => $metricas->renovacoesProximas(),
            'semVisitas' => $metricas->equipamentosSemVisitas(),
            'numAlertas' => $alertas->recolher()->count(),
        ]);
    }
}
