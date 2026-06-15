<?php

namespace App\Services\Agenda;

use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Support\Collection;

// Gera um feed iCal (.ics) com os eventos de um técnico, para subscrição em
// calendários externos (CLAUDE.md §6 — "feed iCal para calendários externos").
class GeradorIcal
{
    public function paraTecnico(User $tecnico): string
    {
        $eventos = EventoAgenda::query()
            ->with('cliente')
            ->where('tecnico_id', $tecnico->id)
            ->where('estado', '!=', 'cancelado')
            ->orderBy('inicio')
            ->get();

        return $this->montar($eventos, 'Nexus Ops · ' . $tecnico->nome);
    }

    private function montar(Collection $eventos, string $nome): string
    {
        $linhas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Nexus Ops//Agenda//PT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escapar($nome),
        ];

        foreach ($eventos as $e) {
            $linhas[] = 'BEGIN:VEVENT';
            $linhas[] = 'UID:evento-' . $e->id . '@nexus-ops';
            $linhas[] = 'DTSTAMP:' . $e->updated_at->utc()->format('Ymd\THis\Z');
            $linhas[] = 'DTSTART:' . $e->inicio->utc()->format('Ymd\THis\Z');
            $linhas[] = 'DTEND:' . $e->fim->utc()->format('Ymd\THis\Z');
            $linhas[] = 'SUMMARY:' . $this->escapar($e->titulo);
            if ($e->cliente) {
                $linhas[] = 'LOCATION:' . $this->escapar($e->cliente->nome);
            }
            $linhas[] = 'STATUS:' . ($e->estado->value === 'concluido' ? 'CONFIRMED' : 'TENTATIVE');
            $linhas[] = 'END:VEVENT';
        }

        $linhas[] = 'END:VCALENDAR';

        // O iCal exige CRLF entre linhas.
        return implode("\r\n", $linhas) . "\r\n";
    }

    private function escapar(string $texto): string
    {
        return str_replace(["\\", ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $texto);
    }
}
