<?php

namespace Tests\Feature;

use App\Services\Erp\SqlServerErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// obterLinhasFatura() do driver real lê fi (linhas) + ft (documento, via ftstamp). Aqui a
// ligação 'erp' aponta para SQLite em memória com o MESMO esquema de colunas — valida a
// query estendida com os valores (epv/desconto/etotal da fi; etotal/ettotal/anulado da ft)
// e o filtro "só linhas com nº de série".
class SqlServerErpDriverFaturacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.erp', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::purge('erp');

        Schema::connection('erp')->create('fi', function ($t) {
            $t->string('fistamp');
            $t->string('ftstamp')->nullable();
            $t->string('nmdoc')->nullable();
            $t->integer('fno')->nullable();
            $t->string('ref')->nullable();
            $t->string('design')->nullable();
            $t->string('series')->nullable();
            $t->decimal('qtt')->nullable();
            $t->decimal('epv')->nullable();      // preço unitário (sem IVA)
            $t->decimal('desconto')->nullable(); // percentagem
            $t->decimal('etotal')->nullable();   // total da linha (sem IVA)
        });

        Schema::connection('erp')->create('ft', function ($t) {
            $t->string('ftstamp');
            $t->date('fdata')->nullable();
            $t->integer('no')->nullable();
            $t->decimal('etotal')->nullable();  // total do documento (sem IVA)
            $t->decimal('ettotal')->nullable(); // total do documento (com IVA)
            $t->boolean('anulado')->default(false);
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('erp')->dropIfExists('fi');
        Schema::connection('erp')->dropIfExists('ft');
        DB::purge('erp');

        parent::tearDown();
    }

    public function test_traz_os_valores_da_linha_e_do_documento(): void
    {
        DB::connection('erp')->table('ft')->insert([
            'ftstamp' => 'FT-1', 'fdata' => '2026-05-10', 'no' => 148,
            'etotal' => 800.00, 'ettotal' => 984.00, 'anulado' => false,
        ]);
        DB::connection('erp')->table('fi')->insert([
            ['fistamp' => 'FI-1', 'ftstamp' => 'FT-1', 'nmdoc' => 'Factura', 'fno' => 42,
                'ref' => 'UPS-NPW', 'design' => 'UPS RIELLO NPW', 'series' => 'S1',
                'qtt' => 2, 'epv' => 350.50, 'desconto' => 5, 'etotal' => 665.95],
            // Sem série → filtrada pelo WHERE (linhas de serviço não entram).
            ['fistamp' => 'FI-2', 'ftstamp' => 'FT-1', 'nmdoc' => 'Factura', 'fno' => 42,
                'ref' => 'MO', 'design' => 'Mão de obra', 'series' => '',
                'qtt' => 1, 'epv' => 100, 'desconto' => 0, 'etotal' => 100],
        ]);

        $linhas = iterator_to_array((new SqlServerErpDriver())->obterLinhasFatura());

        $this->assertCount(1, $linhas); // só a linha com série
        $l = $linhas[0];
        $this->assertSame('FI-1', $l->idErp);
        $this->assertSame(350.50, $l->precoUnitario);
        $this->assertSame(5.0, $l->desconto);
        $this->assertSame(665.95, $l->totalLinha);
        $this->assertSame(800.00, $l->totalDocumento);
        $this->assertSame(984.00, $l->totalDocumentoIva);
        $this->assertFalse($l->anulada);
        $this->assertSame('2026-05-10', $l->data);
        $this->assertSame('148', $l->clienteNo);
    }

    public function test_documento_anulado_vem_marcado(): void
    {
        DB::connection('erp')->table('ft')->insert([
            'ftstamp' => 'FT-2', 'fdata' => '2026-04-01', 'no' => 148,
            'etotal' => 100, 'ettotal' => 123, 'anulado' => true,
        ]);
        DB::connection('erp')->table('fi')->insert([
            'fistamp' => 'FI-3', 'ftstamp' => 'FT-2', 'nmdoc' => 'Factura', 'fno' => 7,
            'ref' => 'X', 'design' => 'X', 'series' => 'SN-X', 'qtt' => 1,
            'epv' => 100, 'desconto' => 0, 'etotal' => 100,
        ]);

        $l = iterator_to_array((new SqlServerErpDriver())->obterLinhasFatura())[0];
        $this->assertTrue($l->anulada);
    }
}
