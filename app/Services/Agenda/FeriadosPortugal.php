<?php

namespace App\Services\Agenda;

use Illuminate\Support\Carbon;

/**
 * Feriados nacionais de Portugal (set. 2026).
 *
 * Treze feriados obrigatórios: dez em data fixa e três que andam com a Páscoa (Sexta-feira
 * Santa, Domingo de Páscoa e Corpo de Deus). São calculados, não escritos numa lista —
 * assim valem para qualquer ano, sem ninguém ter de os acrescentar em janeiro.
 *
 * O Carnaval NÃO é feriado obrigatório: é tolerância de ponto, decidida pelo Governo ano a
 * ano. Entra como «tolerância», aparece na agenda com outro aspeto e nunca impede marcações.
 *
 * Os feriados municipais (Santo António em Lisboa, S. João no Porto…) ficam de fora: mudam
 * de concelho para concelho e a equipa trabalha em todo o país.
 */
class FeriadosPortugal
{
    /** Feriados obrigatórios em data fixa: 'm-d' => nome. */
    private const FIXOS = [
        '01-01' => 'Ano Novo',
        '04-25' => 'Dia da Liberdade',
        '05-01' => 'Dia do Trabalhador',
        '06-10' => 'Dia de Portugal',
        '08-15' => 'Assunção de Nossa Senhora',
        '10-05' => 'Implantação da República',
        '11-01' => 'Todos os Santos',
        '12-01' => 'Restauração da Independência',
        '12-08' => 'Imaculada Conceição',
        '12-25' => 'Natal',
    ];

    /** Cache por ano dentro do mesmo pedido (a agenda pede o mesmo ano muitas vezes). */
    /** @var array<int, array<string, array{nome: string, tolerancia: bool}>> */
    private static array $porAno = [];

    /**
     * Todos os feriados de um ano: 'Y-m-d' => ['nome' => ..., 'tolerancia' => bool].
     *
     * @return array<string, array{nome: string, tolerancia: bool}>
     */
    public function doAno(int $ano): array
    {
        if (isset(self::$porAno[$ano])) {
            return self::$porAno[$ano];
        }

        $feriados = [];
        foreach (self::FIXOS as $md => $nome) {
            $feriados[$ano.'-'.$md] = ['nome' => $nome, 'tolerancia' => false];
        }

        $pascoa = $this->pascoa($ano);
        $moveis = [
            $pascoa->copy()->subDays(2)->format('Y-m-d') => 'Sexta-feira Santa',
            $pascoa->format('Y-m-d') => 'Domingo de Páscoa',
            $pascoa->copy()->addDays(60)->format('Y-m-d') => 'Corpo de Deus',
        ];
        foreach ($moveis as $data => $nome) {
            $feriados[$data] = ['nome' => $nome, 'tolerancia' => false];
        }

        // Tolerância de ponto (não é feriado obrigatório, não bloqueia nada).
        if (config('agenda.mostrar_carnaval', true)) {
            $carnaval = $pascoa->copy()->subDays(47)->format('Y-m-d');
            $feriados[$carnaval] ??= ['nome' => 'Carnaval (tolerância)', 'tolerancia' => true];
        }

        ksort($feriados);

        return self::$porAno[$ano] = $feriados;
    }

    /** Nome do feriado nesse dia, ou null. Por omissão ignora as tolerâncias de ponto. */
    public function nome(Carbon|string $data, bool $incluirTolerancia = false): ?string
    {
        $dia = $data instanceof Carbon ? $data : Carbon::parse($data);
        $registo = $this->doAno((int) $dia->format('Y'))[$dia->format('Y-m-d')] ?? null;

        if (! $registo || (! $incluirTolerancia && $registo['tolerancia'])) {
            return null;
        }

        return $registo['nome'];
    }

    public function eFeriado(Carbon|string $data): bool
    {
        return $this->nome($data) !== null;
    }

    /**
     * Feriados no intervalo pedido (fim exclusivo, como o calendário pede as coisas).
     *
     * @return array<string, array{nome: string, tolerancia: bool}>
     */
    public function entre(Carbon $de, Carbon $ate): array
    {
        $feriados = [];
        for ($ano = (int) $de->format('Y'); $ano <= (int) $ate->format('Y'); $ano++) {
            $feriados += $this->doAno($ano);
        }

        return array_filter(
            $feriados,
            fn (string $data) => $data >= $de->format('Y-m-d') && $data < $ate->format('Y-m-d'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Domingo de Páscoa (calendário gregoriano) pelo algoritmo de Meeus/Jones/Butcher.
     * Não se usa o easter_date() do PHP: depende da extensão `calendar`, que pode não estar
     * instalada, e rebenta fora do intervalo do timestamp em sistemas de 32 bits.
     */
    public function pascoa(int $ano): Carbon
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($ano, $mes, $dia, 0, 0, 0);
    }
}
