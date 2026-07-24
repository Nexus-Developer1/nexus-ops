<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Models\EventoAgenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Deteta conflitos ao agendar/reagendar um evento (CLAUDE.md §6): fora de horário
// de cobertura ou sobreposição com outro evento do técnico.
class DetetorConflitos
{
    // Serializa o agendamento por técnico (advisory lock do Postgres, âmbito da transação):
    // sem isto, dois utilizadores a gravar em simultâneo passavam ambos na verificação de
    // conflito ("verifica → grava") e o técnico ficava com double-booking. Chamar DENTRO de
    // uma DB::transaction, ANTES de verificar conflitos. Chaves ordenadas (evita deadlock
    // quando dois eventos partilham técnicos por ordens diferentes).
    /** @param list<int|string> $chaves ids de conta e/ou nomes legados */
    public function travarAgendaDe(array $chaves): void
    {
        $chaves = array_values(array_unique(array_map(strval(...), $chaves)));
        sort($chaves);

        foreach ($chaves as $chave) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['agenda:tecnico:' . $chave]);
        }
    }

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

        return null;
    }

    // Sobreposição para um técnico em TEXTO LIVRE (sem conta): deteta o double-booking com
    // outro evento do mesmo nome.
    public function conflitoPorNome(string $nome, Carbon $inicio, Carbon $fim, ?int $excetoEventoId = null): ?string
    {
        $sobreposto = EventoAgenda::query()
            ->where('tecnico_nome', $nome)
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->when($excetoEventoId, fn ($q) => $q->where('id', '!=', $excetoEventoId))
            ->where('inicio', '<', $fim)
            ->where('fim', '>', $inicio)
            ->first();

        if ($sobreposto) {
            return 'O técnico já tem "' . $sobreposto->titulo . '" neste horário.';
        }

        return null;
    }
}
