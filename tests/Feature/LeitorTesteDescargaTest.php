<?php

namespace Tests\Feature;

use App\Services\Relatorios\LeitorTesteDescarga;
use Tests\TestCase;

// Leitura do ficheiro do carregador (battest.txt): cabeçalho e lixo ignorados, vírgula decimal
// aceite, e redução 1-em-N para MAX_PONTOS mantendo sempre a última amostra (a recuperação
// no fim da curva é o que interessa ver).
class LeitorTesteDescargaTest extends TestCase
{
    public function test_le_o_formato_do_battest(): void
    {
        $conteudo = "Date\tTime\tVbat+\tVbat-\tIbat+\tIbat-\tOutLoad\tBatCap\tBatTime\n"
            ."09/12/2025\t20:02:51\t270.6\t270.2\t0.0\t0.0\t3.0\t100\t1176\t\n"
            ."09/12/2025\t20:02:52\t270,0\t269,6\t3.2\t3.2\t3.0\t100\t1176\t\n"
            ."linha de lixo qualquer\n"
            ."09/12/2025\t20:02:53\tn/a\t269.2\t3.1\t3.1\t3.0\t100\t2970\t\n"
            ."09/12/2025\t20:02:54\t269.4\t269.2\t2.8\t2.8\t2.7\t100\t1176\t\n";

        $curva = app(LeitorTesteDescarga::class)->ler($conteudo);

        $this->assertSame([
            ['t' => '20:02:51', 'p' => 270.6, 'n' => 270.2],
            ['t' => '20:02:52', 'p' => 270.0, 'n' => 269.6],
            ['t' => '20:02:54', 'p' => 269.4, 'n' => 269.2],
        ], $curva);
    }

    public function test_reduz_a_max_pontos_e_mantem_a_ultima_amostra(): void
    {
        $linhas = '';
        for ($i = 0; $i < 2000; $i++) {
            $t = sprintf('20:%02d:%02d', intdiv($i, 60) % 60, $i % 60);
            $linhas .= "09/12/2025\t{$t}\t".(270 - $i * 0.01)."\t".(270.2 - $i * 0.01)."\n";
        }

        $curva = app(LeitorTesteDescarga::class)->ler($linhas);

        $this->assertLessThanOrEqual(LeitorTesteDescarga::MAX_PONTOS + 1, count($curva));
        $this->assertGreaterThan(400, count($curva));
        $this->assertSame('20:00:00', $curva[0]['t']);
        $this->assertSame(sprintf('20:%02d:%02d', intdiv(1999, 60) % 60, 1999 % 60), end($curva)['t']); // última sempre presente
    }

    public function test_ficheiro_sem_dados_devolve_vazio(): void
    {
        $this->assertSame([], app(LeitorTesteDescarga::class)->ler("olá\nisto não é um battest\n"));
    }
}
