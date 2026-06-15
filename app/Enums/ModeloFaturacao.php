<?php

namespace App\Enums;

// Modelo de faturação do contrato (CLAUDE.md §4).
enum ModeloFaturacao: string
{
    case Avenca = 'avenca';
    case PorVisita = 'por_visita';
    case TempoMateriais = 't_m';

    public function rotulo(): string
    {
        return match ($this) {
            self::Avenca => 'Avença',
            self::PorVisita => 'Por visita',
            self::TempoMateriais => 'Tempo & Materiais',
        };
    }
}
