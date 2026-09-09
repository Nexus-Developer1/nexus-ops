<?php

namespace App\Enums;

// O estado do equipamento NÃO vem do PHC: é uma leitura de quem lá vai. Enquanto ninguém
// o marcar, fica em `PorDefinir` — o valor de origem (set. 2026). Antes disso todos os
// equipamentos nasciam «operacional» por defeito, o que dava uma informação que ninguém
// tinha confirmado.
enum EstadoEquipamento: string
{
    case PorDefinir = 'por_definir';
    case Operacional = 'operacional';
    case Degradado = 'degradado';
    case Critico = 'critico';
    case Inativo = 'inativo';

    public function rotulo(): string
    {
        return match ($this) {
            self::PorDefinir => 'Por definir',
            self::Operacional => 'Operacional',
            self::Degradado => 'Degradado',
            self::Critico => 'Crítico',
            self::Inativo => 'Inativo',
        };
    }

    // Classes Tailwind da etiqueta de estado (tokens do design system).
    public function classesEtiqueta(): string
    {
        return match ($this) {
            // Cinzento cheio: tem de SALTAR À VISTA (é o que falta preencher). Mais escuro
            // que o «Inativo», que é o cinzento apagado de um estado já decidido.
            self::PorDefinir => 'bg-slate-200 text-slate-700',
            self::Operacional => 'bg-verde-50 text-verde-700',
            self::Degradado => 'bg-aviso-100 text-aviso-500',
            self::Critico => 'bg-perigo-100 text-perigo-600',
            self::Inativo => 'bg-slate-100 text-texto-medio',
        };
    }
}
