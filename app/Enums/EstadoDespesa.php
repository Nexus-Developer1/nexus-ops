<?php

namespace App\Enums;

// Processo de validação de um registo de despesas: nasce pendente, é aprovado ou rejeitado
// pelo aprovador; rejeitado pode ser corrigido e volta a pendente.
enum EstadoDespesa: string
{
    case Pendente = 'pendente';
    case Aprovada = 'aprovada';
    case Rejeitada = 'rejeitada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente de aprovação',
            self::Aprovada => 'Aprovada',
            self::Rejeitada => 'Rejeitada',
        };
    }

    // Classes Tailwind da etiqueta de estado (tokens do design system).
    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Pendente => 'bg-aviso-100 text-aviso-500',
            self::Aprovada => 'bg-verde-50 text-verde-700',
            self::Rejeitada => 'bg-perigo-100 text-perigo-600',
        };
    }
}
