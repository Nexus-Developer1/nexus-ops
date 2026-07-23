<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Livewire\Equipamentos\Listagem;
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
            ->set('bancos', [['numero_serie' => '', 'modelo' => '', 'capacidade' => '', 'num_baterias' => '16', 'data_instalacao' => '', 'proxima_troca' => '']])
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

    public function test_listagem_filtra_por_familia(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $ups = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-UPS', 'faminome' => 'UPS']);
        $peca = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-PECA', 'faminome' => 'Peças / Reparação']);

        Livewire::actingAs($admin)->test(Listagem::class)
            // O dropdown lista as famílias presentes.
            ->assertViewHas('familias', fn ($f) => $f->contains('UPS') && $f->contains('Peças / Reparação'))
            // Filtrar por "UPS" esconde as peças.
            ->set('familia', 'UPS')
            ->assertViewHas('equipamentos', fn ($p) => $p->pluck('id')->contains($ups->id) && ! $p->pluck('id')->contains($peca->id));
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

    // ---- Banco de baterias (parte do equipamento) ----

    public function test_novo_equipamento_guarda_banco_de_baterias(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('modelo', 'UPS B')
            ->set('bancos', [['numero_serie' => 'BANK-001', 'modelo' => 'Riello 12V', 'capacidade' => '7 Ah / 384 V', 'num_baterias' => '16', 'data_instalacao' => '', 'proxima_troca' => '']])
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'UPS B')->firstOrFail();
        $this->assertSame('BANK-001', $eq->atributos['bancos'][0]['numero_serie']);
        $this->assertSame('Riello 12V', $eq->atributos['bancos'][0]['modelo']);
        $this->assertSame('7 Ah / 384 V', $eq->atributos['bancos'][0]['capacidade']);
        $this->assertEquals(16, $eq->atributos['num_baterias']); // total dos bancos
    }

    public function test_novo_equipamento_guarda_VARIOS_bancos_com_total_e_proxima_troca_mais_proxima(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('modelo', 'UPS 2 bancos')
            ->set('bancos', [
                ['numero_serie' => 'BANK-A', 'modelo' => 'Riello', 'capacidade' => '', 'num_baterias' => '16', 'data_instalacao' => '', 'proxima_troca' => '2028-06-01'],
                ['numero_serie' => 'BANK-B', 'modelo' => 'Eaton', 'capacidade' => '', 'num_baterias' => '20', 'data_instalacao' => '', 'proxima_troca' => '2027-03-15'],
            ])
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'UPS 2 bancos')->firstOrFail();
        $this->assertCount(2, $eq->atributos['bancos']);                        // os dois bancos gravados
        $this->assertEquals(36, $eq->atributos['num_baterias']);               // 16 + 20 = total
        $this->assertSame('2027-03-15', $eq->proxima_troca_baterias->format('Y-m-d')); // a mais próxima (alertas)
    }

    public function test_ficha_edita_varios_bancos_e_preserva_outros_atributos(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-ERP',
            'atributos' => ['potencia_kva' => 5]]);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $eq])
            ->set('bancos', [
                ['numero_serie' => 'BANK-9', 'modelo' => 'Eaton', 'capacidade' => '9 Ah', 'num_baterias' => '20', 'data_instalacao' => '', 'proxima_troca' => '2027-01-15'],
                ['numero_serie' => 'BANK-10', 'modelo' => 'Riello', 'capacidade' => '', 'num_baterias' => '10', 'data_instalacao' => '', 'proxima_troca' => ''],
            ])
            ->call('guardarBanco')
            ->assertHasNoErrors();

        $eq->refresh();
        $this->assertCount(2, $eq->atributos['bancos']);
        $this->assertSame('BANK-9', $eq->atributos['bancos'][0]['numero_serie']);
        $this->assertEquals(30, $eq->atributos['num_baterias']);              // 20 + 10 = total
        $this->assertEquals(5, $eq->atributos['potencia_kva']);              // atributo existente preservado
        $this->assertSame('2027-01-15', $eq->proxima_troca_baterias->format('Y-m-d')); // a mais próxima
    }

    public function test_ficha_carrega_banco_no_formato_antigo_como_uma_linha(): void
    {
        // Retrocompatibilidade: equipamento gravado no formato antigo (um banco solto em atributos).
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'proxima_troca_baterias' => '2027-05-05',
            'atributos' => ['banco_numero_serie' => 'ANTIGO-1', 'banco_modelo' => 'Riello', 'num_baterias' => 8, 'data_baterias' => '2024-01-01']]);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $eq])
            ->assertSet('bancos', [[
                'numero_serie' => 'ANTIGO-1', 'modelo' => 'Riello', 'capacidade' => '',
                'num_baterias' => '8', 'data_instalacao' => '2024-01-01', 'proxima_troca' => '2027-05-05',
            ]]);
    }

    // ---- Sistema composto (ex.: deteção de incêndio): tipo novo + lista de componentes ----

    public function test_novo_equipamento_incendio_sem_serie_com_componentes(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('tipo', 'incendio')
            ->set('modelo', 'SADI EP 3988')
            ->call('adicionarComponente')
            ->set('componentes.0.designacao', 'Cilindro Novec 106L')
            ->set('componentes.0.quantidade', '1')
            ->call('adicionarComponente')
            ->set('componentes.1.designacao', 'Detetor ótico 701P')
            ->set('componentes.1.quantidade', '4')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'SADI EP 3988')->firstOrFail();
        $this->assertSame('incendio', $eq->tipo->value);
        $this->assertNull($eq->numero_serie);                                 // sem nº de série
        $this->assertCount(2, $eq->atributos['componentes']);
        $this->assertSame('Cilindro Novec 106L', $eq->atributos['componentes'][0]['designacao']);
        $this->assertEquals(4, $eq->atributos['componentes'][1]['quantidade']);
    }

    public function test_novo_equipamento_tipo_sistema_wifi_com_localizacao_e_componentes(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'Municipio do Barreiro', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('tipo', 'sistema')
            ->set('modelo', 'WiFi EB1')
            ->set('localizacao_instalacao', 'EB1 do Barreiro')
            ->call('adicionarComponente')
            ->set('componentes.0.designacao', 'Access Point')
            ->set('componentes.0.quantidade', '8')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'WiFi EB1')->firstOrFail();
        $this->assertSame('sistema', $eq->tipo->value);
        $this->assertNull($eq->numero_serie);
        $this->assertSame('EB1 do Barreiro', $eq->localizacao_instalacao);   // escola na localização
        $this->assertCount(1, $eq->atributos['componentes']);
        $this->assertEquals(8, $eq->atributos['componentes'][0]['quantidade']);
    }

    public function test_ficha_edita_componentes_do_sistema(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'incendio', 'estado' => 'operacional']);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $eq])
            ->call('adicionarComponente')
            ->set('componentes.0.designacao', 'Sirene para interior')
            ->set('componentes.0.quantidade', '2')
            ->call('guardarComponentes')
            ->assertHasNoErrors();

        $eq->refresh();
        $this->assertCount(1, $eq->atributos['componentes']);
        $this->assertSame('Sirene para interior', $eq->atributos['componentes'][0]['designacao']);
        $this->assertEquals(2, $eq->atributos['componentes'][0]['quantidade']);
    }

    public function test_relatorio_pdf_lista_componentes_do_sistema(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'incendio', 'estado' => 'operacional', 'modelo' => 'SADI',
            'atributos' => ['componentes' => [
                ['designacao' => 'Cilindro Novec 106L', 'quantidade' => 1],
                ['designacao' => 'Detetor 701P', 'quantidade' => 4],
            ]]]);
        $interv = Intervencao::create(['equipamento_id' => $eq->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/0600', 'data' => now(), 'estado' => 'finalizado']);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertStringContainsString('Componentes do sistema', $html);
        $this->assertStringContainsString('Cilindro Novec 106L', $html);
        $this->assertStringContainsString('Detetor 701P', $html);
        $this->assertStringContainsString('4 un.', $html);
    }
}
