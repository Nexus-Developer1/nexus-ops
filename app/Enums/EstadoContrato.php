<?php

namespace App\Enums;

// Ciclo de vida de um contrato (CLAUDE.md §4).
enum EstadoContrato: string
{
    case Rascunho = 'rascunho';
    case Ativo = 'ativo';
    case Suspenso = 'suspenso';
    case Expirado = 'expirado';
    case Renovado = 'renovado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Ativo => 'Ativo',
            self::Suspenso => 'Suspenso',
            self::Expirado => 'Expirado',
            self::Renovado => 'Renovado',
        };
    }

    // Classes Tailwind da etiqueta de estado (tokens do design system).
    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Rascunho => 'bg-slate-100 text-texto-medio',
            self::Ativo => 'bg-verde-50 text-verde-700',
            self::Suspenso => 'bg-aviso-100 text-aviso-500',
            self::Expirado => 'bg-perigo-100 text-perigo-600',
            self::Renovado => 'bg-info-100 text-info-600',
        };
    }
}
