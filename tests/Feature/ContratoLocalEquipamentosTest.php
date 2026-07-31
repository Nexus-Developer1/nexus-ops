<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Editor;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Na escolha de equipamentos do contrato aparece ONDE cada um está instalado (morada real),
// pela mesma cascata da ficha de medições: localização explícita → morada do local →
// morada da sede do cliente → nome do local.
class ContratoLocalEquipamentosTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_mostra_a_morada_de_instalacao_por_cascata(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'morada' => 'Av. da Sede, 100', 'codpost' => '1000-001 Lisboa', 'ativo' => true]);

        // (1) localização explícita no equipamento ganha a tudo.
        $localA = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Instalação principal', 'morada' => 'Rua do Local, 5']);
        $comExplicita = Equipamento::create(['local_id' => $localA->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'numero_serie' => 'SN-EXP', 'localizacao_instalacao' => 'Edifício B, piso 2, sala UPS']);

        // (2) sem explícita → morada do local.
        $comMoradaLocal = Equipamento::create(['local_id' => $localA->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-LOC']);

        // (3) local sem morada → morada da sede do cliente (ERP).
        $localB = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Instalação principal']);
        $comSede = Equipamento::create(['local_id' => $localB->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-SEDE']);

        $c = Livewire::actingAs($admin)->test(Editor::class)->set('cliente_id', $cliente->id);

        $c->assertSee('Edifício B, piso 2, sala UPS')   // explícita
            ->assertSee('Rua do Local, 5')              // morada do local
            ->assertSee('Av. da Sede, 100 · 1000-001 Lisboa'); // sede do cliente

        // A cascata em si (independente da renderização).
        $editor = new Editor;
        $this->assertSame('Edifício B, piso 2, sala UPS', $editor->localDe($comExplicita->load('local.cliente')));
        $this->assertSame('Rua do Local, 5', $editor->localDe($comMoradaLocal->load('local.cliente')));
        $this->assertSame('Av. da Sede, 100 · 1000-001 Lisboa', $editor->localDe($comSede->load('local.cliente')));
    }

    public function test_sem_moradas_cai_no_nome_do_local(): void
    {
        $cliente = Cliente::create(['nome' => 'Beta', 'ativo' => true]); // sem morada
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Armazém Norte']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-X']);

        $this->assertSame('Armazém Norte', (new Editor)->localDe($equip->load('local.cliente')));
    }
}
