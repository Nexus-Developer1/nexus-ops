<?php

namespace App\Observers;

use App\Jobs\SincronizarEventoGraph;
use App\Models\EventoAgenda;
use App\Services\Agenda\CalendarioGraph;

// Espelho no calendário partilhado do M365: cada criação / alteração / remoção / restauro de um
// evento manda um job para a fila (DEPOIS do commit — o job lê a BD, não pode correr antes de a
// transação fechar). Apanha TODOS os caminhos que gravam eventos (agenda, contratos, conversão
// em intervenção), não só o modal. Desligado por config → não faz nada.
class EventoAgendaObserver
{
    public function created(EventoAgenda $evento): void
    {
        $this->espelhar($evento);
    }

    public function updated(EventoAgenda $evento): void
    {
        $this->espelhar($evento);
    }

    public function restored(EventoAgenda $evento): void
    {
        $this->espelhar($evento);
    }

    public function deleted(EventoAgenda $evento): void
    {
        if (! app(CalendarioGraph::class)->ativo() || ! $evento->graph_event_id) {
            return;
        }

        SincronizarEventoGraph::dispatch('remover', $evento->id, $evento->graph_event_id)->afterCommit();
    }

    private function espelhar(EventoAgenda $evento): void
    {
        if (! app(CalendarioGraph::class)->ativo()) {
            return;
        }

        SincronizarEventoGraph::dispatch('espelhar', $evento->id)->afterCommit();
    }
}
