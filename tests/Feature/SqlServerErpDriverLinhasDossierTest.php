<?php

namespace Tests\Feature;

use App\Services\Erp\SqlServerErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// obterLinhasDossier() lê as linhas de UM dossiê ao vivo (tabela bi) por bostamp. O PHC
// guarda linhas em branco (separadores) no dossiê — têm de ser saltadas para não sujar a
// ficha. Aqui a ligação 'erp' aponta para SQLite em memória.
class SqlServerErpDriverLinhasDossierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.erp', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::purge('erp');

        Schema::connection('erp')->create('bi', function ($t) {
            $t->string('bostamp');
            $t->integer('lordem')->nullable();
            $t->string('ref')->nullable();
            $t->string('usr6')->nullable();
            $t->string('usr1')->nullable();
            $t->string('design')->nullable();
            $t->decimal('binum1')->nullable();
            $t->decimal('qtt')->nullable();
            $t->decimal('qtt2')->nullable();
            $t->string('series')->nullable();
            $t->decimal('edebito')->nullable();
            $t->decimal('ettdeb')->nullable();
        });

        $this->app->bind(SqlServerErpDriver::class, fn () => new SqlServerErpDriver);
    }

    protected function tearDown(): void
    {
        Schema::connection('erp')->dropIfExists('bi');
        DB::purge('erp');

        parent::tearDown();
    }

    public function test_le_linhas_por_bostamp_e_salta_as_em_branco(): void
    {
        DB::connection('erp')->table('bi')->insert([
            // Linha de artigo (ordem 1).
            ['bostamp' => 'BO-1', 'lordem' => 1, 'ref' => 'UPS-NPW ', 'usr6' => 'PN-1 ', 'usr1' => 'RIELLO ',
                'design' => 'UPS Riello NPW  ', 'binum1' => 0, 'qtt' => 2, 'qtt2' => 1, 'series' => 'SN-9',
                'edebito' => 350.50, 'ettdeb' => 701.00],
            // Linha EM BRANCO (separador do PHC) — tudo vazio/zero → saltada.
            ['bostamp' => 'BO-1', 'lordem' => 2, 'ref' => '  ', 'usr6' => null, 'usr1' => null,
                'design' => '   ', 'binum1' => 0, 'qtt' => 0, 'qtt2' => 0, 'series' => null,
                'edebito' => 0, 'ettdeb' => 0],
            // Linha de comentário (só descrição) — mantém-se.
            ['bostamp' => 'BO-1', 'lordem' => 3, 'ref' => null, 'usr6' => null, 'usr1' => null,
                'design' => 'Instalação incluída', 'binum1' => null, 'qtt' => null, 'qtt2' => null,
                'series' => null, 'edebito' => null, 'ettdeb' => null],
            // Linha de OUTRO dossiê — não deve vir.
            ['bostamp' => 'BO-2', 'lordem' => 1, 'ref' => 'OUTRO', 'usr6' => null, 'usr1' => null,
                'design' => 'Outro dossiê', 'binum1' => null, 'qtt' => 1, 'qtt2' => null,
                'series' => null, 'edebito' => 10, 'ettdeb' => 10],
        ]);

        $linhas = iterator_to_array((new SqlServerErpDriver)->obterLinhasDossier('BO-1'));

        // 3 linhas na bi de BO-1, mas a em branco é saltada → 2 (artigo + comentário), por ordem.
        $this->assertCount(2, $linhas);
        $this->assertSame('UPS-NPW', $linhas[0]->ref);      // trimado
        $this->assertSame('PN-1', $linhas[0]->pn);
        $this->assertSame('RIELLO', $linhas[0]->marca);
        $this->assertSame(701.00, $linhas[0]->total);
        $this->assertNull($linhas[1]->ref);                 // linha de comentário
        $this->assertSame('Instalação incluída', $linhas[1]->descricao);
    }
}
