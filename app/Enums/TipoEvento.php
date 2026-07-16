<?php

namespace App\Enums;

// Tipo de evento da agenda (CLAUDE.md §4).
enum TipoEvento: string
{
    // Nota: as ausências dos técnicos não são eventos de agenda — vivem em tecnico_disponibilidade.
    case VisitaPreventiva = 'visita_preventiva';
    case Intervencao = 'intervencao';
    case Outro = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::VisitaPreventiva => 'Visita preventiva',
            self::Intervencao => 'Intervenção',
            self::Outro => 'Outro',
        };
    }
}
