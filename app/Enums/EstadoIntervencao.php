<?php

namespace App\Enums;

enum EstadoIntervencao: string
{
    case Planeada = 'planeada';
    case EmCurso = 'em_curso';
    case Concluida = 'concluida';

    public function rotulo(): string
    {
        return match ($this) {
            self::Planeada => 'Planeada',
            self::EmCurso => 'Em curso',
            self::Concluida => 'Concluída',
        };
    }

    // Classes Tailwind da etiqueta de estado (tokens do design system).
    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Planeada => 'bg-slate-100 text-texto-medio',
            self::EmCurso => 'bg-aviso-100 text-aviso-500',
            self::Concluida => 'bg-verde-50 text-verde-700',
        };
    }
}
