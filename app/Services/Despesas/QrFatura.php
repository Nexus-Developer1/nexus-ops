<?php

namespace App\Services\Despesas;

/**
 * Interpreta o QR code das faturas portuguesas (Portaria n.º 195/2020 — obrigatório em todas
 * as faturas certificadas desde 2021). Quem lê os píxeis é o browser (resources/js/qr-fatura.js);
 * aqui chega o texto.
 *
 * O texto é uma lista de campos «CHAVE:valor» separados por «*», por exemplo:
 *
 *   A:516520741*B:509101143*C:PT*D:FR*E:N*F:20260921*G:FR COVILHA26/41625*
 *   H:J6M3CZ6D-41625*I1:PT*I7:64.23*I8:14.77*N:14.77*O:79.00*Q:SM2T*R:192
 *
 * Das despesas só dois campos saem daqui com certeza: F (data do documento) → Dia, e O (total
 * do documento, já com IVA) → Valor. Saem também três pistas para o resto da linha:
 *  - A, o NIF de quem vendeu, e a série de faturação (o G até à «/»): identificam a LOJA — a
 *    série é de cada estabelecimento/terminal. É a chave da memória de fornecedores
 *    (MemoriaFornecedor), que lembra a descrição e o tipo que se escolheram da última vez.
 *    (O nome da série às vezes parece trazer o local — «FR COVILHA26» — mas cada vendedor dá-lhe
 *    o nome que quer; não se lê como local.)
 *  - IVA à taxa intermédia (I5/I6 no continente, J5/J6 nos Açores, K5/K6 na Madeira): em
 *    despesas de deslocação é a taxa da restauração — sugere Refeições.
 *
 * O texto vem do browser: não se confia nele. Só se aceita o que tem a forma de uma fatura —
 * NIF do vendedor com 9 dígitos, data que existe, total numérico.
 */
class QrFatura
{
    /** Um QR de fatura tem umas centenas de caracteres; muito mais do que isto não é um. */
    public const TAMANHO_MAXIMO = 1024;

    /**
     * data em Y-m-d; total com duas casas e ponto; nif do vendedor; série de faturação (ou null).
     *
     * @return array{data: string, total: string, nif: string, serie: ?string, intermedia: bool}|null
     */
    public static function ler(string $texto): ?array
    {
        $texto = trim($texto);
        if ($texto === '' || strlen($texto) > self::TAMANHO_MAXIMO) {
            return null;
        }

        $campos = [];
        foreach (explode('*', $texto) as $parte) {
            $par = explode(':', $parte, 2);
            if (count($par) === 2) {
                $campos[trim($par[0])] = trim($par[1]);
            }
        }

        // A: NIF de quem emitiu — é o que distingue uma fatura de um QR code qualquer (um
        // link, um cartão de visita) que esteja no talão.
        if (! preg_match('/^\d{9}$/', $campos['A'] ?? '')) {
            return null;
        }

        // F: data do documento, AAAAMMDD, e tem de existir no calendário.
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})$/', $campos['F'] ?? '', $d)
            || ! checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            return null;
        }

        // O: total do documento com impostos, com ponto decimal.
        if (! preg_match('/^\d{1,9}(\.\d{1,2})?$/', $campos['O'] ?? '')) {
            return null;
        }

        // G: «TIPO SÉRIE/NÚMERO» — fica a série, sem o número do documento.
        $serie = null;
        if (preg_match('#^([^/]{1,60})/\d+$#', $campos['G'] ?? '', $g)) {
            $serie = trim($g[1]) ?: null;
        }

        $intermedia = false;
        foreach (['I5', 'I6', 'J5', 'J6', 'K5', 'K6'] as $chave) {
            if (is_numeric($campos[$chave] ?? null) && (float) $campos[$chave] > 0) {
                $intermedia = true;
            }
        }

        return [
            'data' => "{$d[1]}-{$d[2]}-{$d[3]}",
            'total' => number_format((float) $campos['O'], 2, '.', ''),
            'nif' => $campos['A'],
            'serie' => $serie,
            'intermedia' => $intermedia,
        ];
    }
}
