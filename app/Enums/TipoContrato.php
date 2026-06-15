<?php

namespace App\Enums;

// Âmbito do contrato de manutenção (CLAUDE.md §4).
enum TipoContrato: string
{
    case Preventiva = 'preventiva';
    case Corretiva = 'corretiva';
    case FullService = 'full_service';

    public function rotulo(): string
    {
        return match ($this) {
            self::Preventiva => 'Preventiva',
            self::Corretiva => 'Corretiva',
            self::FullService => 'Full-service',
        };
    }
}
