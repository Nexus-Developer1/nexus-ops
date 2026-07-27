<?php

namespace Tests\Feature;

use App\Services\Erp\ClienteErp;
use App\Services\Erp\SqlServerErpDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// obterClientes() do driver real: lê a tabela cl pela ligação 'erp' (a MESMA que a faturação
// já usa) e mapeia para ClienteErp — deixou de ser o stub que lançava "ainda não implementado".
// Aqui a ligação 'erp' aponta para SQLite em memória (só precisamos de exercitar a query/mapeamento).
class SqlServerErpDriverClientesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reaponta a ligação 'erp' para SQLite em memória (sem tocar no dblib real).
        config()->set('database.connections.erp', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::purge('erp');

        Schema::connection('erp')->create('cl', function ($t) {
            $t->string('no');
            $t->integer('estab')->default(0); // estabelecimento (0 = sede — só essa entra no sync)
            $t->string('nome');
            $t->string('ncont')->nullable();
            $t->string('morada')->nullable();
            $t->string('codpost')->nullable();
            $t->string('email')->nullable();
            $t->string('telefone')->nullable();
            $t->string('tlmvl')->nullable();
            $t->integer('vendedor')->nullable();
            $t->string('vendnm')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('erp')->dropIfExists('cl');
        DB::purge('erp');

        parent::tearDown();
    }

    public function test_le_clientes_da_tabela_cl_e_mapeia_para_dto(): void
    {
        DB::connection('erp')->table('cl')->insert([
            ['no' => '1001', 'estab' => 0, 'nome' => 'ACME Lda', 'ncont' => '500123456', 'morada' => 'Rua A, 1',
                'codpost' => '1000-001', 'email' => 'geral@acme.pt', 'telefone' => '210000000',
                'tlmvl' => '910000000', 'vendedor' => 7, 'vendnm' => 'João'],
            ['no' => '1002', 'estab' => 0, 'nome' => 'Beta SA', 'ncont' => null, 'morada' => null,
                'codpost' => null, 'email' => null, 'telefone' => null,
                'tlmvl' => null, 'vendedor' => null, 'vendnm' => null],
            // Estabelecimento (estab ≠ 0) do MESMO nº de cliente: NÃO entra no sync — sem o
            // filtro, escrevia por cima da sede a cada corrida (nome/morada do último a passar).
            ['no' => '1001', 'estab' => 1, 'nome' => 'ACME Lda - Armazém Norte', 'ncont' => '500123456',
                'morada' => 'Zona Industrial, Lote 9', 'codpost' => '4700-000', 'email' => null,
                'telefone' => null, 'tlmvl' => null, 'vendedor' => 7, 'vendnm' => 'João'],
        ]);

        $clientes = iterator_to_array((new SqlServerErpDriver())->obterClientes());

        $this->assertCount(2, $clientes); // só as sedes — o estabelecimento ficou de fora
        $this->assertContainsOnlyInstancesOf(ClienteErp::class, $clientes);

        // Mapeamento PHC → DTO: cl.no → idErp (string), cl.ncont → nif, cl.vendedor → int.
        $acme = $clientes[0];
        $this->assertSame('1001', $acme->idErp);
        $this->assertSame('ACME Lda', $acme->nome);
        $this->assertSame('500123456', $acme->nif);
        $this->assertSame('geral@acme.pt', $acme->email);
        $this->assertSame(7, $acme->vendedor);

        // Nulos do ERP sobrevivem sem rebentar (cliente sem contribuinte/vendedor).
        $this->assertNull($clientes[1]->nif);
        $this->assertNull($clientes[1]->vendedor);
    }
}
