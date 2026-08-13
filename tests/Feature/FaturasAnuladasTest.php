<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Fatura;
use App\Livewire\Clientes\Faturacao;
use App\Models\Cliente;
use App\Models\LinhaFatura;
use App\Models\User;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use App\Services\Erp\SqlServerErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

// Vaga 1 (dados): faturas ANULADAS no PHC apareciam na app como válidas. O sync passa a
// trazer ft.anulado e a app marca-as com a etiqueta "Anulada" (visível a toda a equipa —
// é estado do documento, não valor). Correção de qualidade de dados, separada da feature
// de valores cancelada a 13/08.
class FaturasAnuladasTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_grava_a_flag_de_anulada(): void
    {
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver);

        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 14])->assertSuccessful();

        // O Fake anula 1 em cada 7 documentos (determinístico).
        $this->assertTrue(LinhaFatura::where('anulada', true)->exists());
        $this->assertTrue(LinhaFatura::where('anulada', false)->exists());
    }

    public function test_driver_real_le_o_anulado_da_ft(): void
    {
        // Liga 'erp' a SQLite em memória com o esquema mínimo fi/ft (como nos testes do driver).
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
        });
        Schema::connection('erp')->create('ft', function ($t) {
            $t->string('ftstamp');
            $t->date('fdata')->nullable();
            $t->integer('no')->nullable();
            $t->boolean('anulado')->default(false);
        });
        DB::connection('erp')->table('ft')->insert(['ftstamp' => 'FT-1', 'fdata' => '2026-05-10', 'no' => 148, 'anulado' => true]);
        DB::connection('erp')->table('fi')->insert(['fistamp' => 'FI-1', 'ftstamp' => 'FT-1', 'nmdoc' => 'Factura',
            'fno' => 42, 'ref' => 'X', 'design' => 'UPS', 'series' => 'SN-1', 'qtt' => 1]);

        $linhas = iterator_to_array((new SqlServerErpDriver)->obterLinhasFatura());

        $this->assertTrue($linhas[0]->anulada);

        Schema::connection('erp')->dropIfExists('fi');
        Schema::connection('erp')->dropIfExists('ft');
        DB::purge('erp');
    }

    public function test_etiqueta_anulada_visivel_a_toda_a_equipa(): void
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);
        $linha = LinhaFatura::create(['id_erp' => 'AN-1', 'cliente_no' => '148', 'data' => now(),
            'nmdoc' => 'Factura', 'fno' => 7, 'design' => 'UPS', 'series' => 'S1', 'qtt' => 1, 'anulada' => true]);

        Livewire::actingAs($tecnico)->test(Faturacao::class, ['cliente' => $cliente])
            ->assertSee('Anulada');

        Livewire::actingAs($tecnico)->test(Fatura::class, ['cliente' => $cliente, 'linha' => $linha])
            ->assertSee('Anulada no PHC');
    }
}
