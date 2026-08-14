<?php

namespace Tests\Feature;

use App\Services\Erp\SqlServerErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// obterDossiers() do driver real lê a tabela bo (tipos 1/3/7). Aqui a ligação 'erp' aponta
// para SQLite em memória, com colunas char "padded" (o PHC preenche com espaços à direita)
// e um dossiê de tipo excluído (ndos=2) para provar o filtro server-side.
class SqlServerErpDriverDossiersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.erp', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::purge('erp');

        Schema::connection('erp')->create('bo', function ($t) {
            $t->string('bostamp');
            $t->integer('ndos')->nullable();
            $t->string('nmdos')->nullable();
            $t->integer('obrano')->nullable();
            $t->date('dataobra')->nullable();
            $t->integer('boano')->nullable();
            $t->integer('no')->nullable();
            $t->string('nome')->nullable();
            $t->decimal('etotaldeb')->nullable();
            $t->boolean('fechada')->default(false);
            $t->text('u_relat')->nullable();
        });

        $this->app->bind(SqlServerErpDriver::class, fn () => new SqlServerErpDriver);
    }

    protected function tearDown(): void
    {
        Schema::connection('erp')->dropIfExists('bo');
        DB::purge('erp');

        parent::tearDown();
    }

    public function test_trima_e_filtra_por_tipo(): void
    {
        DB::connection('erp')->table('bo')->insert([
            ['bostamp' => 'ADM2508...0001  ', 'ndos' => 3, 'nmdos' => 'Proposta  ', 'obrano' => 42,
                'dataobra' => '2025-09-13', 'boano' => 2025, 'no' => 148, 'nome' => 'ACME Lda   ',
                'etotaldeb' => 1250.50, 'fechada' => false, 'u_relat' => ' Notas  '],
            // Tipo 2 → excluído pelo WHERE (ndos in 1,3,7).
            ['bostamp' => 'ADM2508...0002', 'ndos' => 2, 'nmdos' => 'Guia', 'obrano' => 7,
                'dataobra' => '2025-01-01', 'boano' => 2025, 'no' => 148, 'nome' => 'ACME',
                'etotaldeb' => 10, 'fechada' => true, 'u_relat' => null],
        ]);

        $dossies = iterator_to_array((new SqlServerErpDriver)->obterDossiers());

        $this->assertCount(1, $dossies); // só o tipo 3
        $d = $dossies[0];
        $this->assertSame('ADM2508...0001', $d->idErp);  // trimado
        $this->assertSame(3, $d->ndos);
        $this->assertSame('Proposta', $d->nmdos);        // trimado
        $this->assertSame(42, $d->obrano);
        $this->assertSame('2025-09-13', $d->data);
        $this->assertSame('148', $d->clienteNo);
        $this->assertSame('ACME Lda', $d->nome);         // trimado
        $this->assertSame(1250.50, $d->totalDebito);
        $this->assertFalse($d->fechada);
        $this->assertSame('Notas', $d->uRelat);          // trimado
    }
}
