<?php

namespace App\Livewire;

use App\Enums\EstadoEvento;
use App\Enums\PapelUtilizador;
use App\Jobs\SincronizarErp;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Services\Alertas\ServicoAlertas;
use App\Services\Gestao\ServicoMetricas;
use Illuminate\Support\Carbon;
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

    // Filtro do cartão da agenda: só os eventos de um técnico (principal OU adicional).
    // Persiste entre visitas (#[Session]) — quem gere uma equipa fixa não o repõe sempre.
    #[\Livewire\Attributes\Session]
    public string $agendaTecnico = ''; // '' = todos | id do utilizador

    // Filtro do cartão "Próximos alertas": só os alertas ATRIBUÍDOS a um técnico.
    #[\Livewire\Attributes\Session]
    public string $alertasTecnico = ''; // '' = todos | id do utilizador

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
        if ($ultimo && Carbon::parse($ultimo['terminado_em'])->gte(Carbon::parse($this->syncPedidoEm))) {
            $this->syncResultado = ['falhou' => (bool) $ultimo['falhou'], 'resultados' => $ultimo['resultados']];
            $this->syncPedidoEm = null;

            return;
        }

        // Corrida invulgarmente longa (ex.: completa, ~20 min) — larga o poll; o desfecho
        // fica no log e o utilizador pode voltar a olhar mais tarde.
        if (Carbon::parse($this->syncPedidoEm)->lt(now()->subSeconds(180))) {
            $this->syncPedidoEm = null;
            session()->flash('erro-sync', 'A sincronização continua em segundo plano (está a demorar mais do que o habitual). O resultado fica no log da aplicação.');
        }
    }

    // Dar um alerta como concluído a partir do cartão "Próximos alertas".
    public function concluirAlerta(string $chave, ServicoAlertas $servico): void
    {
        session()->flash('sucesso', $servico->concluir($chave, auth()->user())
            ? 'Alerta concluído.'
            : 'Esse alerta já não está em aberto.');
    }

    public function render(ServicoMetricas $metricas, ServicoAlertas $alertas)
    {
        // TODOS os alertas em aberto — o mesmo critério da página de Alertas, para os números
        // baterem certo em todo o lado; a atribuição é etiqueta/filtro, não visibilidade.
        $listaAlertas = $alertas->recolher();
        // Filtro do cartão: um técnico escolhido → só os alertas atribuídos a ele.
        $alertasFiltrados = ctype_digit($this->alertasTecnico)
            ? $listaAlertas->filter(fn ($a) => in_array((int) $this->alertasTecnico, $a['atribuido_a'], true))->values()
            : $listaAlertas;

        return view('livewire.dashboard-gestao', [
            'resumo' => $metricas->resumo(),
            'renovacoes' => $metricas->renovacoesProximas(),
            'numAlertas' => $listaAlertas->count(),
            // Próximos alertas (baterias, renovações, visitas em atraso, SLA) — os mais graves primeiro.
            'proximosAlertas' => $alertasFiltrados->take(6),
            // Agenda dos próximos 7 dias (cancelados fora; eventos multi-dia entram se o intervalo
            // tocar a janela). Um evento sai ASSIM QUE ACABA (fim < agora) — com o corte no início
            // do dia, uma reunião das 9h–11h ficava no cartão até à meia-noite (pedido da equipa).
            'agendaSemana' => EventoAgenda::query()
                ->where('estado', '!=', EstadoEvento::Cancelado->value)
                ->where('fim', '>=', now())
                ->where('inicio', '<', now()->startOfDay()->addDays(7))
                // Técnico escolhido: principal ou um dos adicionais do evento.
                ->when(ctype_digit($this->agendaTecnico), fn ($q) => $q->where(fn ($q) => $q
                    ->where('tecnico_id', (int) $this->agendaTecnico)
                    ->orWhereHas('tecnicosAdicionais', fn ($t) => $t->whereKey((int) $this->agendaTecnico))))
                ->with(['tecnico', 'cliente'])
                ->orderBy('inicio')
                ->limit(8)
                ->get(),
            // Contas da equipa (técnicos e admins) para o filtro da agenda.
            'tecnicosAgenda' => User::where('ativo', true)
                ->whereIn('papel', [PapelUtilizador::Tecnico->value, PapelUtilizador::Admin->value])
                ->orderBy('nome')
                ->get(['id', 'nome']),
        ]);
    }
}
