<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\LinhaFatura;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Sync INCREMENTAL do ERP: cada registo guarda o hash dos dados da última corrida
// (hash_sync); na corrida seguinte, hash igual → saltado sem escrever nada. Era isto que
// faltava à faturação (~191 mil updateOrCreate por corrida ≈ 20 min para meia dúzia de
// linhas novas). O FakeErpDriver é determinístico, por isso a 2.ª corrida é sempre "igual".
class SincronizarErpIncrementalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver);
    }

    public function test_faturacao_segunda_corrida_salta_tudo_sem_escrever(): void
    {
        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 20])->assertSuccessful();
        $total = LinhaFatura::count();
        $this->assertGreaterThan(0, $total);
        $this->assertSame(0, LinhaFatura::whereNull('hash_sync')->count()); // todas com impressão digital

        $syncedAntes = LinhaFatura::orderBy('id')->pluck('synced_at', 'id')->all();

        // 2.ª corrida (dados iguais): tudo saltado, nada reescrito — nem o synced_at mexe.
        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 20])
            ->expectsOutputToContain("{$total} iguais (saltadas)")
            ->assertSuccessful();

        $this->assertSame($total, LinhaFatura::count());
        $this->assertEquals($syncedAntes, LinhaFatura::orderBy('id')->pluck('synced_at', 'id')->all());
    }

    public function test_faturacao_linha_alterada_e_reescrita(): void
    {
        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 20])->assertSuccessful();
        $total = LinhaFatura::count();

        // Simula uma alteração no ERP: estraga o hash de UMA linha (como se os dados diferissem).
        $alvo = LinhaFatura::orderBy('id')->first();
        $alvo->update(['hash_sync' => 'desatualizado', 'qtt' => 999]);

        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 20])
            ->expectsOutputToContain('0 criadas, 1 atualizadas, '.($total - 1).' iguais (saltadas)')
            ->assertSuccessful();

        // A linha voltou a espelhar o ERP e a impressão digital foi refeita.
        $alvo->refresh();
        $this->assertNotSame('desatualizado', $alvo->hash_sync);
        $this->assertNotEquals(999.0, (float) $alvo->qtt);
    }

    public function test_clientes_segunda_corrida_salta_tudo(): void
    {
        $this->artisan('erp:sincronizar-clientes', ['--limit' => 10])->assertSuccessful();
        $this->assertSame(10, Cliente::count());

        $this->artisan('erp:sincronizar-clientes', ['--limit' => 10])
            ->expectsOutputToContain('0 criados, 0 atualizados, 10 iguais (saltados)')
            ->assertSuccessful();
    }

    public function test_equipamentos_segunda_corrida_salta_e_sem_cliente_retenta(): void
    {
        // Clientes primeiro (equipamentos ligam por cliente_no 1000..1009).
        $this->artisan('erp:sincronizar-clientes', ['--limit' => 10])->assertSuccessful();
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 20])->assertSuccessful();
        $total = Equipamento::whereNotNull('id_erp')->count();
        $this->assertGreaterThan(0, $total);

        // 2.ª corrida: tudo igual → saltado; nenhum updated_at mexe.
        $antes = Equipamento::orderBy('id')->pluck('updated_at', 'id')->all();
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 20])
            ->expectsOutputToContain("{$total} iguais (saltados)")
            ->assertSuccessful();
        $this->assertEquals($antes, Equipamento::orderBy('id')->pluck('updated_at', 'id')->all());

        // Sem o cliente na app, o equipamento entra "POR ASSOCIAR" (local null) — e fura o
        // salto por hash em todas as corridas até o cliente aparecer.
        Cliente::query()->forceDelete();
        Equipamento::query()->forceDelete();
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 4])
            ->expectsOutputToContain('sem cliente (por associar)')
            ->assertSuccessful();
        $criados = Equipamento::count();
        $this->assertGreaterThan(0, $criados);
        $this->assertSame($criados, Equipamento::whereNull('local_id')->count()); // todos por associar

        // O cliente aparece → a corrida seguinte ASSOCIA os pendentes sem criar duplicados.
        $this->artisan('erp:sincronizar-clientes', ['--limit' => 10])->assertSuccessful();
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 4])->assertSuccessful();
        $this->assertSame($criados, Equipamento::count());
        $this->assertSame(0, Equipamento::whereNull('local_id')->count());
    }

    public function test_opcao_completo_reprocessa_ignorando_hashes(): void
    {
        $this->artisan('erp:sincronizar-clientes', ['--limit' => 10])->assertSuccessful();

        // --completo não salta nenhum (0 iguais); sem mudanças reais, o Eloquent não reescreve.
        $this->artisan('erp:sincronizar-clientes', ['--limit' => 10, '--completo' => true])
            ->expectsOutputToContain('0 criados, 10 atualizados, 0 iguais (saltados)')
            ->assertSuccessful();
    }
}
