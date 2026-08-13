<?php

namespace Tests\Feature;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
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
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver);
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
        // Todos os importados são Riello/UPS/operacional — nada de outra marca entrou —
        // e trazem a data de criação no PHC (ordena os "mais recentes" pela ordem do PHC).
        $this->assertTrue(Equipamento::get()->every(fn ($e) => $e->fabricante === 'Riello'
            && $e->tipo === TipoEquipamento::Ups
            && $e->estado === EstadoEquipamento::Operacional
            && $e->criado_erp_em !== null));
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

    // Sem cliente já NÃO salta: no PHC há faturas sem o nº de cliente associado (erro humano)
    // e esses equipamentos nunca entravam. Agora entram "por associar" (local null) — a pesquisa
    // por série encontra-os — e ficam associados assim que o cliente aparecer na app.
    public function test_sem_cliente_entra_por_associar_e_associa_quando_o_cliente_aparece(): void
    {
        // Só clientes 1000, 1001, 1002 existem na app.
        $this->sincronizarClientes(limite: 3);

        // Riello em i=0,1,2,4,5,6 → clienteNo 1000,1001,1002,1004,1005,1006.
        // 1000/1001/1002 ficam associados; 1004/1005/1006 entram SEM local ("por associar").
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])
            ->expectsOutputToContain('6 criados, 0 atualizados, 0 iguais (saltados), 3 sem cliente (por associar)')
            ->assertSuccessful();

        $this->assertSame(6, Equipamento::count());
        $this->assertSame(3, Equipamento::whereNull('local_id')->count());

        // Os clientes em falta aparecem na app → a corrida seguinte ASSOCIA os pendentes
        // (furam o salto por hash) sem criar duplicados.
        $this->sincronizarClientes(limite: 10);
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])
            ->expectsOutputToContain('0 criados')
            ->assertSuccessful();

        $this->assertSame(6, Equipamento::count());
        $this->assertSame(0, Equipamento::whereNull('local_id')->count());
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

    public function test_sync_traz_a_familia_do_erp(): void
    {
        $this->sincronizarClientes();
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])->assertSuccessful();

        // O Fake gera famílias: a maioria "UPS", mas 1 em cada 5 é "Peças / Reparação" (i=4).
        $this->assertTrue(Equipamento::where('faminome', 'UPS')->exists());
        $this->assertTrue(Equipamento::where('faminome', 'Peças / Reparação')->exists());
        // O código da família também vem preenchido.
        $this->assertNotNull(Equipamento::where('faminome', 'UPS')->value('familia'));
    }

    public function test_familia_e_do_erp_e_realinha_com_completo(): void
    {
        $this->sincronizarClientes();
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])->assertSuccessful();

        // Alguém "sujou" a família na BD (não é editável na UI). Com o sync INCREMENTAL, os
        // dados do ERP não mudaram → o equipamento é saltado pelo hash e o estrago local fica.
        $equip = Equipamento::where('faminome', 'UPS')->firstOrFail();
        $equip->update(['faminome' => 'ERRADO']);

        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8])->assertSuccessful();
        $this->assertSame('ERRADO', $equip->fresh()->faminome); // saltado (hash igual)

        // O --completo ignora os hashes e realinha os campos do ERP (é a rede de segurança
        // para drift local em campos que pertencem ao PHC).
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 8, '--completo' => true])->assertSuccessful();
        $this->assertSame('UPS', $equip->fresh()->faminome); // realinhada com o PHC
    }
}
