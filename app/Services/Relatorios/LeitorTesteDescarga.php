<?php

namespace App\Services\Relatorios;

// Lê o ficheiro de registo do teste de descarga do carregador (battest.txt): colunas separadas
// por tab/espaços, cabeçalho "Date Time Vbat+ Vbat- ...", uma amostra por segundo. Devolve a
// curva [{t: "20:02:51", p: Vbat+, n: Vbat−}, ...] REDUZIDA a maxPontos (1 em cada N amostras —
// mantém a forma da curva e o pico de recuperação no fim, e o JSON fica pequeno na BD).
class LeitorTesteDescarga
{
    public const MAX_PONTOS = 600;

    /** @return list<array{t: string, p: float, n: float}> */
    public function ler(string $conteudo, int $maxPontos = self::MAX_PONTOS): array
    {
        $amostras = [];

        foreach (preg_split('/\r\n|\r|\n/', $conteudo) as $linha) {
            $colunas = preg_split('/[\t ]+/', trim($linha));
            if (count($colunas) < 4) {
                continue;
            }

            // Formato: Date Time Vbat+ Vbat− … — a hora identifica a linha de dados (o
            // cabeçalho e lixo ficam de fora). Vírgula decimal aceite.
            if (! preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $colunas[1])) {
                continue;
            }

            $p = str_replace(',', '.', $colunas[2]);
            $n = str_replace(',', '.', $colunas[3]);
            if (! is_numeric($p) || ! is_numeric($n)) {
                continue;
            }

            $amostras[] = ['t' => $colunas[1], 'p' => (float) $p, 'n' => (float) $n];
        }

        $total = count($amostras);
        if ($total <= $maxPontos) {
            return $amostras;
        }

        // Redução 1-em-N, garantindo que a ÚLTIMA amostra entra (o fim da curva — a subida
        // quando a rede volta — é exatamente o que se quer ver).
        $passo = (int) ceil($total / $maxPontos);
        $curva = [];
        for ($i = 0; $i < $total; $i += $passo) {
            $curva[] = $amostras[$i];
        }
        if (end($curva) !== $amostras[$total - 1]) {
            $curva[] = $amostras[$total - 1];
        }

        return $curva;
    }
}
