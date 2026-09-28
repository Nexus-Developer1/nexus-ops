<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Encomendas\Ficha;
use App\Livewire\Encomendas\Listagem;
use App\Models\Dossier;
use App\Models\User;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use App\Services\Erp\LeituraErpAoVivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Leituras AO VIVO do PHC nos ecrãs (26.ª revisão de segurança, set. 2026): o PHC lento prendia os
// processos que atendem os pedidos. Timeout curto (ligação erp_interativo), o que se leu fica
// guardado uns segundos, e depois de uma falha não se insiste durante um minuto.
class LeituraErpAoVivoTest extends TestCase
{
    use RefreshDatabase;

    /** Driver que conta as leituras e pode estar «em baixo». */
    private function phc(bool $emBaixo = false): FakeErpDriver
    {
        $erp = new class extends FakeErpDriver
        {
            public int $leituras = 0;

            public bool $emBaixo = false;

            public function obterTotaisDossiers(array $bostamps): array
            {
                $this->leituras++;
                if ($this->emBaixo) {
                    throw new RuntimeException('SQLSTATE[HY000]: Adaptive Server connection timed out');
                }

                return array_fill_keys($bostamps, 100.0);
            }

            public function obterLinhasDossier(string $bostamp): iterable
            {
                $this->leituras++;
                if ($this->emBaixo) {
                    throw new RuntimeException('SQLSTATE[HY000]: Adaptive Server connection timed out');
                }

                return parent::obterLinhasDossier($bostamp);
            }
        };
        $erp->emBaixo = $emBaixo;
        $this->app->instance(ErpSyncDriver::class, $erp);

        return $erp;
    }

    public function test_os_ecras_usam_a_ligacao_com_timeout_curto(): void
    {
        $this->assertSame(3, config('database.connections.erp_interativo.options')[\PDO::ATTR_TIMEOUT]);
        $this->assertSame(30, config('database.connections.erp.options')[\PDO::ATTR_TIMEOUT]); // syncs: igual
    }

    // Escrever na pesquisa ou mudar de página não volta a perguntar pelos mesmos dossiês.
    public function test_o_que_se_leu_fica_guardado(): void
    {
        $erp = $this->phc();
        $leitura = app(LeituraErpAoVivo::class);

        $a = $leitura->totais(['BO-1', 'BO-2']);
        $b = $leitura->totais(['BO-1', 'BO-2']);
        $this->assertSame($a, $b);
        $this->assertSame(1, $erp->leituras);

        $leitura->totais(['BO-1', 'BO-3']); // só o BO-3 é novo
        $this->assertSame(2, $erp->leituras);

        $leitura->linhas('BO-1');
        $leitura->linhas('BO-1');
        $this->assertSame(3, $erp->leituras);
    }

    // PHC em baixo: uma tentativa, uma linha no log, e um minuto sem voltar a tentar — os
    // ecrãs mostram o que está guardado, sem esperar pelo PHC a cada tecla.
    public function test_depois_de_uma_falha_nao_insiste_durante_um_minuto(): void
    {
        Log::spy();
        $erp = $this->phc(emBaixo: true);
        $leitura = app(LeituraErpAoVivo::class);

        $this->assertSame([], $leitura->totais(['BO-1']));
        $this->assertNull($leitura->linhas('BO-1'));
        $this->assertSame([], $leitura->totais(['BO-2']));
        $this->assertSame(1, $erp->leituras, 'só a primeira tentativa chega ao PHC');
        $this->assertTrue($leitura->emBaixo());
        Log::shouldHaveReceived('warning')->once();

        // Passado o minuto, volta a tentar — e o PHC já respondeu.
        $this->travel(LeituraErpAoVivo::PAUSA + 1)->seconds();
        $erp->emBaixo = false;
        $this->assertFalse($leitura->emBaixo());
        $this->assertArrayHasKey('BO-1', $leitura->totais(['BO-1']));
    }

    // Na ficha, as duas leituras são independentes: se só o total falhar, as linhas aparecem.
    public function test_na_ficha_uma_falha_no_total_nao_esconde_as_linhas(): void
    {
        $this->app->instance(ErpSyncDriver::class, new class extends FakeErpDriver
        {
            public function obterTotaisDossiers(array $bostamps): array
            {
                throw new RuntimeException('SQLSTATE[HY000]: Adaptive Server connection timed out');
            }
        });
        $dossier = Dossier::create(['id_erp' => 'BO-F-1', 'ndos' => 3, 'nmdos' => 'Proposta', 'obrano' => 7431, 'ano' => 2026,
            'data' => now(), 'cliente_no' => '1', 'nome' => 'ACME', 'total_debito' => 1062.09, 'fechada' => false]);
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        Livewire::actingAs($admin)->test(Ficha::class, ['dossier' => $dossier])
            ->assertViewHas('erroLinhas', false)
            ->assertViewHas('linhas', fn ($l) => count($l) >= 1)
            ->assertSee('UPS Riello NPW 2000VA')
            ->assertSee('1 062,09 €'); // total da última sincronização
    }

    // Na listagem, o PHC em baixo não impede a página: ficam os totais da sincronização.
    public function test_listagem_com_phc_em_baixo_abre_com_os_totais_guardados(): void
    {
        $erp = $this->phc(emBaixo: true);
        Dossier::create(['id_erp' => 'BO-L-1', 'ndos' => 3, 'nmdos' => 'Proposta', 'obrano' => 7431, 'ano' => 2026,
            'data' => now(), 'cliente_no' => '1', 'nome' => 'ACME', 'total_debito' => 1062.09, 'fechada' => false]);
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        Livewire::actingAs($admin)->test(Listagem::class)
            ->assertSee('1 062,09 €')
            ->set('pesquisa', 'ACM')
            ->set('pesquisa', 'ACME')
            ->assertSee('1 062,09 €');
        $this->assertSame(1, $erp->leituras, 'a pesquisa não volta a esperar pelo PHC');
    }
}
