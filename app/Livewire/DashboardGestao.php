<?php

namespace App\Livewire;

use App\Livewire\Concerns\ApenasEquipa;
use App\Enums\EstadoEvento;
use App\Jobs\SincronizarErp;
use App\Models\EventoAgenda;
use App\Services\Alertas\ServicoAlertas;
use App\Services\Gestao\ServicoMetricas;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Dashboard de gestão (CLAUDE.md §6): KPIs, agenda dos próximos dias, próximos alertas
// e renovações próximas. Os gráficos (tipo/estado/visitas por mês), o cumprimento de SLA
// e os equipamentos sem visitas saíram a pedido da equipa — as métricas continuam no
// ServicoMetricas para os relatórios de gestão.
#[Layout('components.layouts.app', ['ativo' => 'dashboard', 'titulo' => 'Dashboard'])]
class DashboardGestao extends Component
{
    use ApenasEquipa;

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
        $listaAlertas = $alertas->recolher();

        return view('livewire.dashboard-gestao', [
            'resumo' => $metricas->resumo(),
            'renovacoes' => $metricas->renovacoesProximas(),
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
        ]);
    }
}
