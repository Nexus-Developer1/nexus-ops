<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Livewire\Equipamentos\Novo;
use App\Livewire\Relatorios\Novo as RelatorioNovo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
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

    // ---- Cliente final e localização da instalação ----

    public function test_novo_equipamento_guarda_cliente_final_e_localizacao(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('modelo', 'UPS X')
            ->set('cliente_final', 'Hospital Central')
            ->set('localizacao_instalacao', 'Edifício B, piso 2')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'UPS X')->firstOrFail();
        $this->assertSame('Hospital Central', $eq->cliente_final);
        $this->assertSame('Edifício B, piso 2', $eq->localizacao_instalacao);
    }

    public function test_ficha_edita_cliente_final_e_localizacao(): void
    {
        // Equipamento já existente (ex.: vindo do ERP) — a ficha é a forma de lhes pôr estes campos.
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-ERP']);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $eq])
            ->set('clienteFinal', 'Cliente Final X')
            ->set('localizacaoInstalacao', 'Sala 12')
            ->call('guardarIdentificacao')
            ->assertHasNoErrors();

        $this->assertSame('Cliente Final X', $eq->fresh()->cliente_final);
        $this->assertSame('Sala 12', $eq->fresh()->localizacao_instalacao);
    }

    public function test_relatorio_pdf_mostra_cliente_final_e_localizacao_explicitos(): void
    {
        $cliente = Cliente::create(['nome' => 'Parceiro Lda', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Instalação principal']);
        $eq = Equipamento::create([
            'local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'SRT', 'numero_serie' => 'SN-9',
            'cliente_final' => 'Hospital Central', 'localizacao_instalacao' => 'Edifício B, piso 2',
        ]);
        $interv = Intervencao::create(['equipamento_id' => $eq->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/0500', 'data' => now(), 'estado' => 'finalizado']);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertStringContainsString('Cliente final', $html);
        $this->assertStringContainsString('Hospital Central', $html);
        $this->assertStringContainsString('Localização da instalação', $html);
        $this->assertStringContainsString('Edifício B, piso 2', $html);
    }
}
