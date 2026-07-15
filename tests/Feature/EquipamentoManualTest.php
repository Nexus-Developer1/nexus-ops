<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Novo;
use App\Livewire\Relatorios\Novo as RelatorioNovo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Registo MANUAL de equipamentos não vindos do ERP (id_erp nulo). Devem ficar disponíveis em
// contratos/relatórios e o sync do ERP não lhes toca.
class EquipamentoManualTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_cria_equipamento_manual_com_id_erp_nulo_e_atributos(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Datacenter']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->assertSet('local_id', $local->id)              // pré-selecionou o local do cliente
            ->set('tipo', 'ups')
            ->set('fabricante', 'APC')
            ->set('modelo', 'Smart-UPS SRT 5000')
            ->set('numero_serie', 'SN-MANUAL-1')
            ->set('potencia_kva', '5')
            ->set('num_baterias', '16')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('numero_serie', 'SN-MANUAL-1')->firstOrFail();
        $this->assertNull($eq->id_erp);                       // não vendido por nós
        $this->assertSame($local->id, $eq->local_id);
        $this->assertSame('APC', $eq->fabricante);
        $this->assertEquals(5, $eq->atributos['potencia_kva']);   // valor (o tipo muda no round-trip JSONB)
        $this->assertEquals(16, $eq->atributos['num_baterias']);
    }

    public function test_usa_instalacao_principal_quando_cliente_nao_tem_locais(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'BETA', 'ativo' => true]); // sem locais

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->assertSet('local_id', null)
            ->set('modelo', 'Gerador X')
            ->set('tipo', 'gerador')
            ->call('guardar')
            ->assertHasNoErrors();

        $local = Local::where('cliente_id', $cliente->id)->where('designacao', 'Instalação principal')->firstOrFail();
        $eq = Equipamento::where('local_id', $local->id)->firstOrFail();
        $this->assertNull($eq->id_erp);
        $this->assertSame('gerador', $eq->tipo->value);
    }

    public function test_equipamento_manual_fica_disponivel_no_relatorio_do_cliente(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'GAMA', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);

        // Cria o equipamento manual.
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('modelo', 'UPS de terceiros')
            ->set('numero_serie', 'SN-3RD')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('numero_serie', 'SN-3RD')->firstOrFail();

        // No editor de relatório, escolher o cliente traz o equipamento manual (1 equip → principal).
        Livewire::actingAs($admin)->test(RelatorioNovo::class)
            ->call('selecionarCliente', $cliente->id)
            ->assertSet('equipamento_id', $eq->id);
    }

    public function test_cliente_e_obrigatorio(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Novo::class)
            ->set('modelo', 'Sem cliente')
            ->call('guardar')
            ->assertHasErrors('cliente_id');
    }
}
