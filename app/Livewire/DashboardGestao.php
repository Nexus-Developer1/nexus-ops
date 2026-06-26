<?php

namespace App\Livewire;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
use App\Services\Alertas\ServicoAlertas;
use App\Services\Gestao\ServicoMetricas;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Dashboard de gestão (CLAUDE.md §6): KPIs, rentabilidade de visitas, cumprimento
// de SLA, renovações próximas, equipamentos sem visitas recentes e gráficos
// (distribuição de ativos por tipo/estado e visitas preventivas por mês).
#[Layout('components.layouts.app', ['ativo' => 'dashboard', 'titulo' => 'Dashboard'])]
class DashboardGestao extends Component
{
    private const CORES_TIPO = ['ups' => '#2563eb', 'gerador' => '#ea580c', 'pdu' => '#9333ea'];

    private const CORES_ESTADO = ['operacional' => '#16a34a', 'degradado' => '#f59e0b', 'critico' => '#dc2626', 'inativo' => '#94a3b8'];

    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public function render(ServicoMetricas $metricas, ServicoAlertas $alertas)
    {
        $porTipo = $metricas->equipamentosPorTipo();
        $porEstado = $metricas->equipamentosPorEstado();
        $visitas = $metricas->visitasPorMes();

        return view('livewire.dashboard-gestao', [
            'resumo' => $metricas->resumo(),
            'renovacoes' => $metricas->renovacoesProximas(),
            'semVisitas' => $metricas->equipamentosSemVisitas(),
            'numAlertas' => $alertas->recolher()->count(),

            // Configurações Chart.js (montadas no servidor; o canvas só as recebe).
            'graficoTipos' => $this->donut(
                array_map(fn ($k) => TipoEquipamento::tryFrom($k)?->rotulo() ?? ucfirst($k), array_keys($porTipo)),
                array_values($porTipo),
                array_map(fn ($k) => self::CORES_TIPO[$k] ?? '#94a3b8', array_keys($porTipo)),
            ),
            'graficoEstados' => $this->donut(
                array_map(fn ($k) => EstadoEquipamento::tryFrom($k)?->rotulo() ?? ucfirst($k), array_keys($porEstado)),
                array_values($porEstado),
                array_map(fn ($k) => self::CORES_ESTADO[$k] ?? '#94a3b8', array_keys($porEstado)),
            ),
            'graficoVisitas' => $this->barras(self::MESES, $visitas['planeadas'], $visitas['realizadas']),
        ]);
    }

    /** @return array<string, mixed> */
    private function donut(array $labels, array $valores, array $cores): array
    {
        return [
            'type' => 'doughnut',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'data' => $valores,
                    'backgroundColor' => $cores,
                    'borderWidth' => 2,
                    'borderColor' => '#ffffff',
                ]],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'cutout' => '62%',
                'plugins' => [
                    'legend' => ['position' => 'bottom', 'labels' => ['padding' => 14, 'usePointStyle' => true, 'font' => ['size' => 12]]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function barras(array $labels, array $planeadas, array $realizadas): array
    {
        return [
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'Planeadas', 'data' => $planeadas, 'backgroundColor' => '#cbd5e1', 'borderRadius' => 4],
                    ['label' => 'Realizadas', 'data' => $realizadas, 'backgroundColor' => '#16a34a', 'borderRadius' => 4],
                ],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'padding' => 14]]],
                'scales' => [
                    'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                    'x' => ['grid' => ['display' => false]],
                ],
            ],
        ];
    }
}
