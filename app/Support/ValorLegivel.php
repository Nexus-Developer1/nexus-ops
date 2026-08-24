<?php

namespace App\Support;

/**
 * Põe em português um valor vindo do detalhe da auditoria.
 *
 * O detalhe é JSON e traz de tudo: verdadeiro/falso, números, texto, listas.
 * Antes era impresso tal e qual, e em PHP um `false` impresso não dá "não" —
 * dá texto vazio. Uma linha como
 *
 *     falhou:
 *     agendado: 1
 *
 * lia-se como um relatório de falha truncado, quando dizia precisamente o
 * contrário: não falhou, e correu pela agenda.
 */
class ValorLegivel
{
    public static function texto(mixed $valor): string
    {
        return match (true) {
            // Antes de is_scalar: em PHP um booleano TAMBÉM é escalar, e era
            // por aí que se perdia.
            is_bool($valor) => $valor ? 'sim' : 'não',
            is_null($valor) => '—',
            is_scalar($valor) => (string) $valor,
            default => json_encode($valor, JSON_UNESCAPED_UNICODE) ?: '—',
        };
    }
}
