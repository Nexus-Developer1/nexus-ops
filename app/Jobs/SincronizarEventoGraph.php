<?php

namespace App\Jobs;

use App\Models\EventoAgenda;
use App\Services\Agenda\CalendarioGraph;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// Espelha UM evento no calendário partilhado do M365 (Graph) — corre na fila, nunca no pedido.
// 'espelhar' = cria/atualiza a partir do estado ATUAL do evento (lê a BD, por isso várias
// alterações seguidas colapsam no último estado); 'remover' = apaga pelo id do Graph (passa-se o
// id porque o evento já pode ter sido apagado). Um 403 = permissão em falta: loga UMA vez e
// desiste (sem retries a martelar a Microsoft).
class SincronizarEventoGraph implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public string $acao,           // 'espelhar' | 'remover'
        public int $eventoId,
        public ?string $graphEventId = null,
    ) {}

    public function handle(CalendarioGraph $calendario): void
    {
        if (! $calendario->ativo()) {
            return;
        }

        try {
            if ($this->acao === 'remover') {
                if ($this->graphEventId) {
                    $calendario->remover($this->graphEventId);
                }

                return;
            }

            $evento = EventoAgenda::withTrashed()->with(['tecnico', 'tecnicosAdicionais', 'cliente', 'local', 'equipamento', 'contrato'])->find($this->eventoId);
            if (! $evento) {
                return;
            }

            // Apagado ou cancelado entretanto → o espelho sai do calendário.
            if ($evento->trashed() || $evento->estado->value === 'cancelado') {
                if ($evento->graph_event_id) {
                    $calendario->remover($evento->graph_event_id);
                    $evento->forceFill(['graph_event_id' => null])->saveQuietly();
                }

                return;
            }

            $calendario->espelhar($evento);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '(403)')) {
                Log::warning('Graph: sem permissão para o calendário da agenda (Calendars.ReadWrite por consentir?) — evento não espelhado.', ['evento' => $this->eventoId]);

                return; // não vale a pena repetir até alguém dar o consentimento
            }

            throw $e; // outros erros (rede, 5xx) → retries com backoff
        }
    }
}
