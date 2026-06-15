<?php

namespace App\Enums;

enum EstadoRelatorio: string
{
    case Rascunho = 'rascunho';
    case Finalizado = 'finalizado';
    case Enviado = 'enviado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Finalizado => 'Finalizado',
            self::Enviado => 'Enviado',
        };
    }

    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Rascunho => 'bg-slate-100 text-texto-medio',
            self::Finalizado => 'bg-info-100 text-info-600',
            self::Enviado => 'bg-verde-50 text-verde-700',
        };
    }
}
