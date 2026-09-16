<?php

namespace App\Enums;

// Ciclo de vida de um contrato (CLAUDE.md §4). «Expirado»/«Renovado» saíram (set. 2026): nunca
// eram atribuídos — a expiração lê-se pela data_fim, e renovar é criar contrato novo.
enum EstadoContrato: string
{
    case Rascunho = 'rascunho';
    case Ativo = 'ativo';
    case Suspenso = 'suspenso';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Ativo => 'Ativo',
            self::Suspenso => 'Suspenso',
        };
    }

    // Classes Tailwind da etiqueta de estado (tokens do design system).
    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Rascunho => 'bg-slate-100 text-texto-medio',
            self::Ativo => 'bg-verde-50 text-verde-700',
            self::Suspenso => 'bg-aviso-100 text-aviso-500',
        };
    }
}
