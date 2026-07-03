<?php

namespace Tests\Feature;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Sync de equipamentos Riello do ERP (PHC ma) com o driver Fake: filtra só Riello, correlaciona
// por mastamp (idempotente), salta equipamentos sem cliente, e — o mais importante — NÃO destrói
// o enriquecimento do técnico no re-sync (coalesce puro).
class SincronizarEquipamentosErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Força o driver Fake (independente do ERP_DRIVER do ambiente).
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver());
    }

    // Popula os clientes fake (id_erp 1000..) a que os equipamentos se ligam.
    private function sincronizarClientes(int $limite = 10): void
    {
        $this->artisan('erp:sincronizar-clientes', ['--limit' => $limite])->assertSuccessful();
    }

    public function test_filtro_riello_so_riello_entra(): void
    {
        $this->sincronizarClientes();

        // Candidatos i=0..7: não-Riello em i=3 e i=7 (marca EATON) → 6 Riello.
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])->assertSuccessful();

        $this->assertSame(6, Equipamento::count());
        // Todos os importados são Riello/UPS/operacional — nada de outra marca entrou.
        $this->assertTrue(Equipamento::get()->every(fn ($e) => $e->fabricante === 'Riello'
            && $e->tipo === TipoEquipamento::Ups
            && $e->estado === EstadoEquipamento::Operacional));
    }

    public function test_idempotencia_por_mastamp_nao_duplica(): void
    {
        $this->sincronizarClientes();

        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])->assertSuccessful();
        $idErpsAntes = Equipamento::orderBy('id')->pluck('id_erp', 'id')->all();

        // 2.ª corrida: mesmos mastamp (determinístico) → casa com os existentes, não cria novos.
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])
            ->expectsOutputToContain('0 criados')
            ->assertSuccessful();

        // Mesmos ids e mesmos id_erp — não nasceram linhas novas (o mastamp casou com as existentes).
        $this->assertSame($idErpsAntes, Equipamento::orderBy('id')->pluck('id_erp', 'id')->all());
    }

    public function test_salta_equipamento_sem_cliente_na_app(): void
    {
        // Só clientes 1000, 1001, 1002 existem na app.
        $this->sincronizarClientes(limite: 3);

        // Riello em i=0,1,2,4,5,6 → clienteNo 1000,1001,1002,1004,1005,1006.
        // Presentes: 1000/1001/1002 → 3 criados; 1004/1005/1006 → 3 saltados (sem cliente).
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])
            ->expectsOutputToContain('3 sem cliente')
            ->assertSuccessful();

        $this->assertSame(3, Equipamento::count());
    }

    public function test_resync_nao_destroi_enriquecimento_do_tecnico(): void
    {
        $this->sincronizarClientes();
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])->assertSuccessful();

        // O técnico enriquece um equipamento: move-o para um local REAL e define a próxima troca
        // de baterias e atributos UPS.
        $equip = Equipamento::firstOrFail();
        $clienteId = $equip->local->cliente_id;
        $localReal = Local::create(['cliente_id' => $clienteId, 'designacao' => 'Sala Técnica -1']);

        $equip->update([
            'local_id' => $localReal->id,
            'proxima_troca_baterias' => '2027-09-15',
            'atributos' => ['potencia_kva' => 10, 'topologia' => 'online'],
            'estado' => EstadoEquipamento::Degradado,
        ]);

        // Re-sync: o ERP volta a trazer este mastamp com o local "Instalação principal".
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])->assertSuccessful();

        // As edições do técnico SOBREVIVEM — coalesce puro não sobrepõe nada já preenchido.
        $equip->refresh();
        $this->assertSame($localReal->id, $equip->local_id);                       // não voltou a "Instalação principal"
        $this->assertSame('2027-09-15', $equip->proxima_troca_baterias->format('Y-m-d'));
        // assertEquals (não assertSame): o jsonb do Postgres reordena as chaves; o que importa é
        // o conteúdo ser igual, não a ordem — o essencial é que os atributos do técnico sobreviveram.
        $this->assertEquals(['potencia_kva' => 10, 'topologia' => 'online'], $equip->atributos);
        $this->assertSame(EstadoEquipamento::Degradado, $equip->estado);           // não voltou a "operacional"
    }
}
