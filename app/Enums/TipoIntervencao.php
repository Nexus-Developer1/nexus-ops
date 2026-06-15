<?php

namespace App\Enums;

enum TipoIntervencao: string
{
    case Preventiva = 'preventiva';
    case Corretiva = 'corretiva';
    case Instalacao = 'instalacao';

    public function rotulo(): string
    {
        return match ($this) {
            self::Preventiva => 'Preventiva',
            self::Corretiva => 'Corretiva',
            self::Instalacao => 'Instalação',
        };
    }
}
