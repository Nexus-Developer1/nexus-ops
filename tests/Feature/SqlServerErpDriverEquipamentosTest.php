<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\SqlServerErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// obterEquipamentos() do driver real lê a tabela ma. As colunas char do PHC vêm com PADDING de
// espaços à direita; sem trim, o mastamp ("...001 ") não casa com o id_erp já carregado pelo
// Python ("...001") e o sync criava duplicados. Aqui a ligação 'erp' aponta para SQLite em
// memória, com um mastamp propositadamente "padded".
class SqlServerErpDriverEquipamentosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reaponta a ligação 'erp' para SQLite em memória (sem tocar no dblib real).
        config()->set('database.connections.erp', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::purge('erp');

        Schema::connection('erp')->create('ma', function ($t) {
            $t->string('mastamp');
            $t->string('ref')->nullable();
            $t->string('serie')->nullable();
            $t->string('design')->nullable();
            $t->string('marca')->nullable();
            $t->date('instal')->nullable();
            $t->integer('no')->nullable();
        });

        // Artigos (para a família, via LEFT JOIN st ON st.ref = ma.ref).
        Schema::connection('erp')->create('st', function ($t) {
            $t->string('ref');
            $t->string('familia')->nullable();
            $t->string('faminome')->nullable();
        });

        // Força o driver real (que lê da ligação 'erp' = SQLite acima).
        $this->app->bind(ErpSyncDriver::class, fn () => new SqlServerErpDriver());
    }

    protected function tearDown(): void
    {
        Schema::connection('erp')->dropIfExists('ma');
        Schema::connection('erp')->dropIfExists('st');
        DB::purge('erp');

        parent::tearDown();
    }

    public function test_trima_padding_de_char_nos_campos_de_texto(): void
    {
        DB::connection('erp')->table('ma')->insert([
            'mastamp' => 'Mic23091346621,906000001 ',      // padding à direita (coluna char)
            'serie' => 'MH19VNPW0012345  ',
            'design' => 'UPS RIELLO NPW 2000VA   ',
            'marca' => 'RIELLO  ',
            'instal' => '2023-05-10',
            'no' => 1000,
        ]);

        $equipamentos = iterator_to_array((new SqlServerErpDriver())->obterEquipamentos());

        $this->assertCount(1, $equipamentos);
        $e = $equipamentos[0];
        // Todos os campos de texto vêm sem espaços — tal como o Python (limpar/.strip()).
        $this->assertSame('Mic23091346621,906000001', $e->idErp);
        $this->assertSame('MH19VNPW0012345', $e->numeroSerie);
        $this->assertSame('UPS RIELLO NPW 2000VA', $e->modelo);
    }

    public function test_mastamp_com_espacos_casa_com_id_erp_existente_sem_espacos(): void
    {
        $mastampSemEspacos = 'Mic23091346621,906000001';

        // Equipamento JÁ existente, carregado pelo Python (id_erp SEM espaços) + enriquecido pelo
        // técnico (local real diferente do "Instalação principal" + próxima troca de baterias).
        $cliente = Cliente::create(['id_erp' => '1000', 'nome' => 'ACME Lda', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Instalação principal']);
        $localReal = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala Técnica -1']);

        $existente = Equipamento::create([
            'id_erp' => $mastampSemEspacos,
            'local_id' => $localReal->id,
            'tipo' => 'ups',
            'fabricante' => 'Riello',
            'numero_serie' => 'MH19VNPW0012345',
            'estado' => 'operacional',
            'proxima_troca_baterias' => '2027-01-01',
            'qr_code' => $mastampSemEspacos,
        ]);

        // O ERP devolve O MESMO equipamento, mas com o mastamp PADDED (char).
        DB::connection('erp')->table('ma')->insert([
            'mastamp' => $mastampSemEspacos.'   ',     // com espaços à direita
            'serie' => 'MH19VNPW0012345',
            'design' => 'UPS RIELLO NPW 2000VA',
            'marca' => 'RIELLO',
            'instal' => '2023-05-10',
            'no' => 1000,
        ]);

        // Com o trim, o id_erp casa → updateOrCreate ATUALIZA (não cria).
        $this->artisan('erp:sincronizar-equipamentos')
            ->expectsOutputToContain('0 criados, 1 atualizados')
            ->assertSuccessful();

        // Um só equipamento (sem duplicado) e o enriquecimento do técnico intacto.
        $this->assertSame(1, Equipamento::count());
        $existente->refresh();
        $this->assertSame($localReal->id, $existente->local_id);
        $this->assertSame('2027-01-01', $existente->proxima_troca_baterias->format('Y-m-d'));
    }

    public function test_traz_a_familia_do_artigo_via_join_st(): void
    {
        // Artigo (st) com família; ma liga-lhe por ref.
        DB::connection('erp')->table('st')->insert(['ref' => 'A100', 'familia' => '100', 'faminome' => 'UPS  ']); // padding

        DB::connection('erp')->table('ma')->insert([
            'mastamp' => 'MAX001', 'ref' => 'A100', 'serie' => 'SN-1',
            'design' => 'UPS RIELLO', 'marca' => 'RIELLO', 'instal' => '2023-05-10', 'no' => 1000,
        ]);

        $e = iterator_to_array((new SqlServerErpDriver())->obterEquipamentos())[0];
        $this->assertSame('100', $e->familia);
        $this->assertSame('UPS', $e->faminome); // trimado
    }
}
