<?php

namespace App\Livewire;

use App\Livewire\Concerns\ApenasEquipa;
use App\Enums\EstadoEquipamento;
use App\Enums\EstadoEvento;
use App\Enums\TipoEquipamento;
use App\Jobs\SincronizarErp;
use App\Models\EventoAgenda;
use App\Services\Alertas\ServicoAlertas;
use App\Services\Gestao\ServicoMetricas;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Dashboard de gestão (CLAUDE.md §6): KPIs, rentabilidade de visitas, cumprimento
// de SLA, renovações próximas, equipamentos sem visitas recentes e gráficos
// (distribuição de ativos por tipo/estado e visitas preventivas por mês).
#[Layout('components.layouts.app', ['ativo' => 'dashboard', 'titulo' => 'Dashboard'])]
class DashboardGestao extends Component
{
    use ApenasEquipa;

    private const CORES_TIPO = ['ups' => '#2563eb', 'gerador' => '#ea580c', 'pdu' => '#9333ea'];

    private const CORES_ESTADO = ['operacional' => '#16a34a', 'degradado' => '#f59e0b', 'critico' => '#dc2626', 'inativo' => '#94a3b8'];

    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    // Enquanto espera pelo fim do sync pedido pelo botão, a view faz poll e depois troca a
    // mensagem pelo RESUMO por etapa (criados/atualizados) — o job deixa-o em cache.
    // #[Locked]: estado interno do fluxo, nunca definível pelo browser — sem isto, um valor
    // forjado rebentava no Carbon::parse/render (500 provocável; 11.ª revisão de segurança).
    #[\Livewire\Attributes\Locked]
    public ?string $syncPedidoEm = null;

    /** @var array{falhou: bool, resultados: array<string, array{ok: bool, detalhe: string}>}|null */
    #[\Livewire\Attributes\Locked]
    public ?array $syncResultado = null;

    // Força a sincronização de TODOS os dados do PHC já (sem esperar pelo agendado das
    // 08h/13h/19h). Corre em background na fila, em modo SILENCIOSO — sem email de
    // resultado (isso é só no agendado); o desfecho aparece no dashboard e fica no log.
    // Throttle de 10 min para o botão não empilhar syncs. Ver Jobs\SincronizarErp.
    public function sincronizarErp(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        if (blank(config('erp.driver'))) {
            session()->flash('erro-sync', 'A ligação ao PHC não está configurada neste ambiente.');

            return;
        }

        if (! Cache::add('erp-sync-manual-pedido', now()->toDateTimeString(), 600)) {
            session()->flash('erro-sync', 'Já foi pedida uma sincronização há pouco — aguarde uns minutos.');

            return;
        }

        SincronizarErp::dispatch();
        $this->syncPedidoEm = now()->toIso8601String();
        $this->syncResultado = null;
    }

    // Chamado pelo wire:poll enquanto há um sync pedido: quando o job termina (resultado em
    // cache com timestamp posterior ao pedido), troca a espera pelo resumo por etapa.
    public function verificarSync(): void
    {
        if (! $this->syncPedidoEm) {
            return;
        }

        $ultimo = Cache::get('erp-sync:ultimo');
        // Comparação por Carbon (não lexicográfica): strings ISO-8601 com offsets diferentes
        // (mudança de hora) ordenavam mal na comparação de texto.
        if ($ultimo && \Illuminate\Support\Carbon::parse($ultimo['terminado_em'])->gte(\Illuminate\Support\Carbon::parse($this->syncPedidoEm))) {
            $this->syncResultado = ['falhou' => (bool) $ultimo['falhou'], 'resultados' => $ultimo['resultados']];
            $this->syncPedidoEm = null;

            return;
        }

        // Corrida invulgarmente longa (ex.: completa, ~20 min) — larga o poll; o desfecho
        // fica no log e o utilizador pode voltar a olhar mais tarde.
        if (\Illuminate\Support\Carbon::parse($this->syncPedidoEm)->lt(now()->subSeconds(180))) {
            $this->syncPedidoEm = null;
            session()->flash('erro-sync', 'A sincronização continua em segundo plano (está a demorar mais do que o habitual). O resultado fica no log da aplicação.');
        }
    }

    public function render(ServicoMetricas $metricas, ServicoAlertas $alertas)
    {
        $porTipo = $metricas->equipamentosPorTipo();
        $porEstado = $metricas->equipamentosPorEstado();
        $visitas = $metricas->visitasPorMes();
        $listaAlertas = $alertas->recolher();

        return view('livewire.dashboard-gestao', [
            'resumo' => $metricas->resumo(),
            'renovacoes' => $metricas->renovacoesProximas(),
            'semVisitas' => $metricas->equipamentosSemVisitas(),
            'numAlertas' => $listaAlertas->count(),
            // Próximos alertas (baterias, renovações, visitas em atraso, SLA) — os mais graves primeiro.
            'proximosAlertas' => $listaAlertas->take(6),
            // Agenda dos próximos 7 dias (inclui hoje; cancelados fora; eventos multi-dia entram
            // se o intervalo tocar a janela).
            'agendaSemana' => EventoAgenda::query()
                ->where('estado', '!=', EstadoEvento::Cancelado->value)
                ->where('fim', '>=', now()->startOfDay())
                ->where('inicio', '<', now()->startOfDay()->addDays(7))
                ->with(['tecnico', 'cliente'])
                ->orderBy('inicio')
                ->limit(8)
                ->get(),

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
