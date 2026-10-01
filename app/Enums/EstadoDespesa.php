<?php

namespace App\Enums;

// Processo de validação de um registo de despesas: nasce pendente, é aprovado, aprovado
// PARCIALMENTE (algumas linhas recusadas — out. 2026) ou rejeitado pelo aprovador; rejeitado
// pode ser corrigido e volta a pendente. Aprovada e aprovada parcialmente = fechada.
enum EstadoDespesa: string
{
    case Pendente = 'pendente';
    case Aprovada = 'aprovada';
    case AprovadaParcialmente = 'aprovada_parcial';
    case Rejeitada = 'rejeitada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente de aprovação',
            self::Aprovada => 'Aprovada',
            self::AprovadaParcialmente => 'Aprovada parcialmente',
            self::Rejeitada => 'Rejeitada',
        };
    }

    // Classes Tailwind da etiqueta de estado (tokens do design system).
    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Pendente => 'bg-aviso-100 text-aviso-500',
            self::Aprovada, self::AprovadaParcialmente => 'bg-verde-50 text-verde-700',
            self::Rejeitada => 'bg-perigo-100 text-perigo-600',
        };
    }

    // Já decidida a favor (toda ou em parte): fechada — ninguém edita, e segue para a contabilidade.
    public function aprovada(): bool
    {
        return $this === self::Aprovada || $this === self::AprovadaParcialmente;
    }
}
