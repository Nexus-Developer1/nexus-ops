<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Dossier;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Sync dos dossiês do ERP (PHC bo, tipos 1/3/7) com o driver Fake: correlaciona por bostamp
// (idempotente), guarda o tipo (ndos) e o incremental salta os inalterados.
class SincronizarDossiersErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver);
    }

    public function test_cria_dossies_com_o_tipo(): void
    {
        $this->artisan('erp:sincronizar-dossiers', ['--limit' => 9])
            ->expectsOutputToContain('9 criados')
            ->assertSuccessful();

        $this->assertSame(9, Dossier::count());
        // O gerador cicla os tipos 1/3/7 → aparecem os três.
        $this->assertSame([1, 3, 7], Dossier::query()->distinct()->orderBy('ndos')->pluck('ndos')->all());

        $d = Dossier::orderBy('id')->first();
        $this->assertSame(1, $d->ndos);              // i=0 → ndos 1
        $this->assertSame('Encomenda Peças', $d->tipoRotulo());
        $this->assertSame('1000', $d->cliente_no);   // liga a clientes.id_erp
        $this->assertNotNull($d->total_debito);
    }

    public function test_idempotencia_e_incremental_por_hash(): void
    {
        $this->artisan('erp:sincronizar-dossiers', ['--limit' => 9])->assertSuccessful();

        // 2.ª corrida: mesmos bostamp e conteúdo → tudo saltado pelo hash, nada duplica.
        $this->artisan('erp:sincronizar-dossiers', ['--limit' => 9])
            ->expectsOutputToContain('0 criados, 0 atualizados, 9 iguais')
            ->assertSuccessful();

        $this->assertSame(9, Dossier::count());
    }

    public function test_cliente_correlaciona_por_id_erp(): void
    {
        $cliente = Cliente::create(['id_erp' => '1000', 'nome' => 'ACME Lda', 'ativo' => true]);
        $this->artisan('erp:sincronizar-dossiers', ['--limit' => 3])->assertSuccessful();

        // O dossiê do cliente 1000 resolve a relação (cliente_no → clientes.id_erp).
        $dossier = Dossier::where('cliente_no', '1000')->firstOrFail();
        $this->assertTrue($dossier->cliente->is($cliente));
    }
}
