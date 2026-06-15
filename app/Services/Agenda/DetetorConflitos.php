<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Models\EventoAgenda;
use App\Models\TecnicoDisponibilidade;
use Illuminate\Support\Carbon;

// Deteta conflitos ao agendar/reagendar um evento (CLAUDE.md §6): fora de horário
// de cobertura, sobreposição com outro evento do técnico, ou técnico em ausência.
class DetetorConflitos
{
    // Evento fora do horário comercial / fim-de-semana? (independente de técnico).
    public function foraDeHorario(Carbon $inicio, Carbon $fim): ?string
    {
        $abertura = (int) config('agenda.hora_abertura');
        $fecho = (int) config('agenda.hora_fecho');
        $diasUteis = (array) config('agenda.dias_uteis');

        if (! in_array($inicio->dayOfWeekIso, $diasUteis, true)) {
            return 'Fora do horário de cobertura (fim-de-semana).';
        }

        // O fim pode coincidir com a hora de fecho (ex.: termina às 19:00).
        if ($inicio->hour < $abertura || $fim->copy()->subSecond()->hour >= $fecho) {
            return "Fora do horário de cobertura ({$abertura}h–{$fecho}h).";
        }

        return null;
    }

    // Devolve a razão do conflito (texto legível) ou null se não houver conflito.
    public function conflito(int $tecnicoId, Carbon $inicio, Carbon $fim, ?int $excetoEventoId = null): ?string
    {
        // Sobreposição com outro evento do mesmo técnico (intervalos que se cruzam).
        $sobreposto = EventoAgenda::query()
            ->where('tecnico_id', $tecnicoId)
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->when($excetoEventoId, fn ($q) => $q->where('id', '!=', $excetoEventoId))
            ->where('inicio', '<', $fim)
            ->where('fim', '>', $inicio)
            ->first();

        if ($sobreposto) {
            return 'O técnico já tem "' . $sobreposto->titulo . '" neste horário.';
        }

        // Técnico em ausência/férias no período.
        $ausencia = TecnicoDisponibilidade::query()
            ->where('tecnico_id', $tecnicoId)
            ->where('inicio', '<', $fim)
            ->where('fim', '>', $inicio)
            ->first();

        if ($ausencia) {
            return 'O técnico está ausente neste período' . ($ausencia->motivo ? ' (' . $ausencia->motivo . ')' : '') . '.';
        }

        return null;
    }
}
