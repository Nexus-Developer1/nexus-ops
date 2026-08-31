<?php

namespace App\Services\Agenda;

use App\Models\EventoAgenda;
use App\Models\User;
use App\Notifications\EventoAgendaNotificacao;
use Illuminate\Support\Facades\Notification;

// Ponto único dos emails aos técnicos associados a um evento (criado / alterado / removido).
// Só dispara se o evento tiver `notificar_tecnicos` (a escolha feita no formulário e guardada
// no evento — vale também para o arrasto na agenda e para o remover no detalhe). Quem fez a
// ação NÃO recebe o email (já sabe); os emails vão pela fila (notificação ShouldQueue).
//
// Instantâneos (arrays) em vez de modelos: no "removido" o evento já não existe quando o
// worker corre; no "alterado" é preciso o ANTES tal como estava, para o email dizer o que mudou.
class NotificadorAgenda
{
    /** @return array<string, mixed> */
    public static function instantaneo(EventoAgenda $e): array
    {
        $e->loadMissing(['tecnico', 'tecnicosAdicionais', 'cliente', 'equipamento', 'contrato']);

        return [
            'id' => $e->id,
            'titulo' => $e->titulo,
            'inicio' => $e->inicio->toIso8601String(),
            'fim' => $e->fim->toIso8601String(),
            'segmentos' => array_map(fn ($s) => [$s[0]->toIso8601String(), $s[1]->toIso8601String()], $e->segmentos()),
            'tecnico_ids' => $e->tecnicoIdsTodos(),
            'tecnicos_nomes' => (string) $e->tecnico_label,
            'cliente' => $e->cliente?->nome,
            'equipamento' => $e->equipamento
                ? trim(($e->equipamento->numero_serie ?? '').' · '.trim(($e->equipamento->fabricante ?? '').' '.($e->equipamento->modelo ?? '')), ' ·')
                : null,
            'contrato' => $e->contrato?->numero,
            'notificar' => (bool) $e->notificar_tecnicos,
        ];
    }

    public function criado(EventoAgenda $evento): void
    {
        $agora = self::instantaneo($evento);
        if (! $agora['notificar']) {
            return;
        }

        $this->enviar($agora['tecnico_ids'], new EventoAgendaNotificacao('criado', $agora, null, $this->autor()));
    }

    /** @param array<string, mixed> $antes */
    public function alterado(EventoAgenda $evento, array $antes): void
    {
        $agora = self::instantaneo($evento);
        if (! $agora['notificar']) {
            return;
        }

        // Quem saiu do evento recebe "removido" (para ele, deixou de existir); quem ficou ou
        // entrou recebe "alterado" com o antes/depois — e quem acabou de entrar não tem antes.
        $sairam = array_values(array_diff($antes['tecnico_ids'], $agora['tecnico_ids']));
        $entraram = array_values(array_diff($agora['tecnico_ids'], $antes['tecnico_ids']));
        $ficaram = array_values(array_intersect($antes['tecnico_ids'], $agora['tecnico_ids']));

        $this->enviar($sairam, new EventoAgendaNotificacao('removido', $antes, null, $this->autor()));
        $this->enviar($entraram, new EventoAgendaNotificacao('criado', $agora, null, $this->autor()));

        // Para quem ficou, só se algo visível mudou — arrastar 5 min e voltar a pôr não avisa.
        if ($ficaram !== [] && $this->mudouAlgo($antes, $agora)) {
            $this->enviar($ficaram, new EventoAgendaNotificacao('alterado', $agora, $antes, $this->autor()));
        }
    }

    /** @param array<string, mixed> $antes */
    public function removido(array $antes): void
    {
        if (! $antes['notificar']) {
            return;
        }

        $this->enviar($antes['tecnico_ids'], new EventoAgendaNotificacao('removido', $antes, null, $this->autor()));
    }

    private function mudouAlgo(array $a, array $d): bool
    {
        foreach (['titulo', 'inicio', 'fim', 'segmentos', 'tecnicos_nomes', 'cliente', 'equipamento', 'contrato'] as $campo) {
            if (($a[$campo] ?? null) != ($d[$campo] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<int> $ids */
    private function enviar(array $ids, EventoAgendaNotificacao $notificacao): void
    {
        $ids = array_values(array_diff($ids, [(int) auth()->id()]));
        if ($ids === []) {
            return;
        }

        $destinatarios = User::whereIn('id', $ids)->where('ativo', true)->whereNotNull('email')->get();
        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::send($destinatarios, $notificacao);
    }

    private function autor(): string
    {
        return auth()->user()?->nome ?? 'Nexus Infra';
    }
}
