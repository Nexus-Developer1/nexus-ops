<?php

namespace Tests\Feature;

use App\Models\LinhaFatura;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Sync de faturação do ERP (PHC fi) com o driver Fake: popula linhas_fatura, upsert por
// id_erp não duplica, e o WHERE series filtra linhas sem nº de série.
class SincronizarFaturacaoErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Força o driver Fake (independente do ERP_DRIVER do ambiente).
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver());
    }

    public function test_comando_popula_linhas_fatura_com_o_fake(): void
    {
        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 8])->assertSuccessful();

        $this->assertGreaterThan(0, LinhaFatura::count());
        $this->assertSame('synced', LinhaFatura::firstOrFail()->synced_at ? 'synced' : 'sem'); // synced_at preenchido
    }

    public function test_where_series_filtra_linhas_sem_serie(): void
    {
        // No Fake, 1 em cada 4 candidatas não tem série (i=3, i=7) → não entram.
        // Com --limit=8: candidatas i=0..7, sem série em i=3 e i=7 → 6 linhas com série.
        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 8])->assertSuccessful();

        $this->assertSame(6, LinhaFatura::count());
        $this->assertTrue(LinhaFatura::get()->every(fn ($l) => filled($l->series)));
    }

    public function test_updateorcreate_nao_duplica_em_segunda_corrida(): void
    {
        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 8])->assertSuccessful();
        $primeiro = LinhaFatura::count();

        // 2.ª corrida: mesmos id_erp (determinístico) → atualiza, não duplica.
        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 8])->assertSuccessful();

        $this->assertSame($primeiro, LinhaFatura::count());
    }

    public function test_grava_campos_principais(): void
    {
        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 4])->assertSuccessful();

        $linha = LinhaFatura::firstOrFail();
        $this->assertNotEmpty($linha->id_erp);
        $this->assertNotEmpty($linha->cliente_no);   // nº de cliente (ft.no) preenchido
        $this->assertNotEmpty($linha->series);
        $this->assertNotNull($linha->qtt);
        $this->assertNotNull($linha->synced_at);
    }
}
