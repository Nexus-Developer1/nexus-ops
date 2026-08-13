<?php

namespace Tests\Feature;

use App\Models\Artigo;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Sync do catálogo de artigos do ERP (PHC st) com o driver Fake: correlaciona por ref
// (idempotente), filtra artigos sem referência e o incremental salta os inalterados.
class SincronizarArtigosErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Força o driver Fake (independente do ERP_DRIVER do ambiente).
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver);
    }

    public function test_cria_artigos_e_filtra_os_sem_referencia(): void
    {
        // Candidatos i=0..9: i=4 e i=9 sem referência (filtrados) → 8 artigos.
        $this->artisan('erp:sincronizar-artigos', ['--limit' => 10])
            ->expectsOutputToContain('8 criados')
            ->assertSuccessful();

        $this->assertSame(8, Artigo::count());
        $detetor = Artigo::where('id_erp', 'DET-701P-000')->firstOrFail();
        $this->assertSame('Detetor ótico convencional 701P', $detetor->designacao);
        $this->assertSame('Deteção de incêndio', $detetor->faminome);
    }

    public function test_idempotencia_e_incremental_por_hash(): void
    {
        $this->artisan('erp:sincronizar-artigos', ['--limit' => 10])->assertSuccessful();

        // 2.ª corrida: mesmas refs e mesmo conteúdo → tudo saltado pelo hash, nada duplica.
        $this->artisan('erp:sincronizar-artigos', ['--limit' => 10])
            ->expectsOutputToContain('0 criados, 0 atualizados, 8 iguais')
            ->assertSuccessful();

        $this->assertSame(8, Artigo::count());
    }
}
