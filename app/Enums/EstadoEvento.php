<?php

namespace App\Enums;

// Estado de um evento da agenda (CLAUDE.md §4).
enum EstadoEvento: string
{
    case Planeado = 'planeado';
    case Confirmado = 'confirmado';
    case EmCurso = 'em_curso';
    case Concluido = 'concluido';
    case Cancelado = 'cancelado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Planeado => 'Planeado',
            self::Confirmado => 'Confirmado',
            self::EmCurso => 'Em curso',
            self::Concluido => 'Concluído',
            self::Cancelado => 'Cancelado',
        };
    }
}
