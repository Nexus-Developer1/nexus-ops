<?php

namespace App\Enums;

enum EstadoEquipamento: string
{
    case Operacional = 'operacional';
    case Degradado = 'degradado';
    case Critico = 'critico';
    case Inativo = 'inativo';

    public function rotulo(): string
    {
        return match ($this) {
            self::Operacional => 'Operacional',
            self::Degradado => 'Degradado',
            self::Critico => 'Crítico',
            self::Inativo => 'Inativo',
        };
    }

    // Classes Tailwind da etiqueta de estado (tokens do design system).
    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Operacional => 'bg-verde-50 text-verde-700',
            self::Degradado => 'bg-aviso-100 text-aviso-500',
            self::Critico => 'bg-perigo-100 text-perigo-600',
            self::Inativo => 'bg-slate-100 text-texto-medio',
        };
    }
}
