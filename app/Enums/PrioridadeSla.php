<?php

namespace App\Enums;

// Prioridade de um SLA do contrato (CLAUDE.md §4) — medida contra timestamps
// das intervenções corretivas.
enum PrioridadeSla: string
{
    case Critica = 'critica';
    case Alta = 'alta';
    case Normal = 'normal';

    public function rotulo(): string
    {
        return match ($this) {
            self::Critica => 'Crítica',
            self::Alta => 'Alta',
            self::Normal => 'Normal',
        };
    }

    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Critica => 'bg-perigo-100 text-perigo-600',
            self::Alta => 'bg-aviso-100 text-aviso-500',
            self::Normal => 'bg-slate-100 text-texto-medio',
        };
    }
}
