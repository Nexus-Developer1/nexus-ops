<?php

namespace App\Services\Agenda;

use App\Models\EventoAgenda;
use App\Models\User;
use App\Notifications\EventoAgendaNotificacao;
use Illuminate\Support\Facades\Notification;

// Ponto único dos emails aos técnicos associados a um evento (criado / alterado / removido),
// cada um com o CONVITE iCalendar anexado (um por técnico — o Outlook mete o evento no
// calendário, e um cancelamento tira-o). Só dispara se o evento tiver `notificar_tecnicos`
// (a escolha do formulário, guardada no evento — vale também para o arrasto na agenda e para
// o remover no detalhe). Quem fez a ação NÃO recebe (já sabe); os emails vão pela fila.
//
// SEQUENCE (iCalendar): 0 na criação; a cada alteração ENVIADA incrementa-se ical_sequence no
// evento e o convite vai com esse valor; o cancelamento vai com o seguinte — sem sequence
// crescente o Outlook ignora a atualização. Instantâneos (arrays) em vez de modelos: no
// "removido" o evento já não existe quando o worker corre; no "alterado" é preciso o ANTES.
class NotificadorAgenda
{
    /** @return array<string, mixed> */
    public static function instantaneo(EventoAgenda $e): array
    {
        $e->loadMissing(['tecnico', 'tecnicosAdicionais', 'cliente', 'equipamento', 'equipamentosAdicionais', 'contrato']);

        return [
            'id' => $e->id,
            'uid' => GeradorIcs::uid($e->id),
            'sequence' => (int) $e->ical_sequence,
            'titulo' => $e->titulo,
            'inicio' => $e->inicio->toIso8601String(),
            'fim' => $e->fim->toIso8601String(),
            'segmentos' => array_map(fn ($s) => [$s[0]->toIso8601String(), $s[1]->toIso8601String()], $e->segmentos()),
            'tecnico_ids' => $e->tecnicoIdsTodos(),
            'tecnicos_nomes' => (string) $e->tecnico_label,
            'cliente' => $e->cliente?->nome,
            // Principal + adicionais ("SN1 · Riello NPW, SN2 · Riello NPW") — o email e o convite
            // dizem tudo o que o trabalho abrange.
            'equipamento' => $e->equipamento
                ? implode(', ', array_map(
                    fn ($eq) => trim(($eq->numero_serie ?? '').' · '.trim(($eq->fabricante ?? '').' '.($eq->modelo ?? '')), ' ·'),
                    [$e->equipamento, ...$e->equipamentosAdicionais->all()],
                ))
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

        // Criação: SEQUENCE 0 (o valor que o evento já tem).
        $this->enviar($agora['tecnico_ids'], new EventoAgendaNotificacao('criado', $agora, null, $this->autor()));
    }

    /** @param array<string, mixed> $antes */
    public function alterado(EventoAgenda $evento, array $antes): void
    {
        $agora = self::instantaneo($evento);
        if (! $agora['notificar']) {
            return;
        }

        $sairam = array_values(array_diff($antes['tecnico_ids'], $agora['tecnico_ids']));
        $entraram = array_values(array_diff($agora['tecnico_ids'], $antes['tecnico_ids']));
        $ficaram = array_values(array_intersect($antes['tecnico_ids'], $agora['tecnico_ids']));
        $mudou = $this->mudouAlgo($antes, $agora);

        // Só se vai mesmo enviar algo é que o SEQUENCE avança (um arrasto que volta ao mesmo sítio
        // não gasta sequence nem manda email).
        if ($sairam === [] && $entraram === [] && ! $mudou) {
            return;
        }

        $evento->increment('ical_sequence');
        $agora['sequence'] = (int) $evento->fresh()->ical_sequence;

        // Quem saiu: CANCEL com o UID do evento (o Outlook tira-o do calendário dele).
        $this->enviar($sairam, new EventoAgendaNotificacao('removido', ['sequence' => $agora['sequence']] + $antes, null, $this->autor()));
        // Quem entrou: REQUEST novo (para ele é a primeira vez que vê o evento).
        $this->enviar($entraram, new EventoAgendaNotificacao('criado', $agora, null, $this->autor()));
        // Quem ficou: REQUEST atualizado com o antes/depois.
        if ($ficaram !== [] && $mudou) {
            $this->enviar($ficaram, new EventoAgendaNotificacao('alterado', $agora, $antes, $this->autor()));
        }
    }

    /** @param array<string, mixed> $antes */
    public function removido(array $antes): void
    {
        if (! $antes['notificar']) {
            return;
        }

        // Cancelamento: mesmo UID, SEQUENCE seguinte ao último enviado.
        $antes['sequence'] = ((int) ($antes['sequence'] ?? 0)) + 1;

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
