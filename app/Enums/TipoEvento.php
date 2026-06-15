<?php

namespace App\Enums;

// Tipo de evento da agenda (CLAUDE.md §4).
enum TipoEvento: string
{
    case VisitaPreventiva = 'visita_preventiva';
    case Intervencao = 'intervencao';
    case Ausencia = 'ausencia';
    case Outro = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::VisitaPreventiva => 'Visita preventiva',
            self::Intervencao => 'Intervenção',
            self::Ausencia => 'Ausência',
            self::Outro => 'Outro',
        };
    }
}
