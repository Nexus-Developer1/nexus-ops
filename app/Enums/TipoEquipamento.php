<?php

namespace App\Enums;

enum TipoEquipamento: string
{
    case Ups = 'ups';
    case Gerador = 'gerador';
    case Pdu = 'pdu';

    public function rotulo(): string
    {
        return match ($this) {
            self::Ups => 'UPS',
            self::Gerador => 'Gerador',
            self::Pdu => 'PDU',
        };
    }

    // Classes Tailwind da etiqueta de tipo (tokens do design system).
    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Ups => 'bg-info-100 text-info-600',
            self::Gerador => 'bg-aviso-100 text-aviso-500',
            self::Pdu => 'bg-slate-100 text-texto-medio',
        };
    }
}
