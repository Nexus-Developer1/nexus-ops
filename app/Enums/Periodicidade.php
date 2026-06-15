<?php

namespace App\Enums;

// Periodicidade dos planos de visita preventiva (CLAUDE.md §4).
// `meses()` é a âncora da geração automática de visitas (Fase 2 — agenda).
enum Periodicidade: string
{
    case Mensal = 'mensal';
    case Trimestral = 'trimestral';
    case Semestral = 'semestral';
    case Anual = 'anual';

    public function rotulo(): string
    {
        return match ($this) {
            self::Mensal => 'Mensal',
            self::Trimestral => 'Trimestral',
            self::Semestral => 'Semestral',
            self::Anual => 'Anual',
        };
    }

    // Intervalo em meses entre visitas — usado para gerar as visitas planeadas.
    public function meses(): int
    {
        return match ($this) {
            self::Mensal => 1,
            self::Trimestral => 3,
            self::Semestral => 6,
            self::Anual => 12,
        };
    }
}
