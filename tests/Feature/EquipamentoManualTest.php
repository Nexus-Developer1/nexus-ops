<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Livewire\Equipamentos\Listagem;
use App\Livewire\Equipamentos\Novo;
use App\Livewire\Relatorios\Novo as RelatorioNovo;
use App\Models\Artigo;
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
            ->set('bancos', [['numero_serie' => '', 'modelo' => '', 'capacidade' => '', 'num_baterias' => '16', 'data_instalacao' => '', 'proxima_troca' => '']])
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('numero_serie', 'SN-MANUAL-1')->firstOrFail();
        $this->assertNull($eq->id_erp);                       // não vendido por nós
        $this->assertSame($local->id, $eq->local_id);
        $this->assertSame('APC', $eq->fabricante);
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

    // Listagem: pesquisa ÚNICA e global (o combobox "1º filtrar por cliente" saiu — no PHC há
    // faturas sem a série associada ao cliente certo e a navegação por cliente enganava).
    // A série/modelo encontra o equipamento esteja em que cliente estiver.
    public function test_pesquisa_e_global_por_serie_modelo_e_cliente(): void
    {
        $admin = $this->admin();
        $acme = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $beta = Cliente::create(['nome' => 'BETA', 'ativo' => true]);
        $localA = Local::create(['cliente_id' => $acme->id, 'designacao' => 'DC-A']);
        $localB = Local::create(['cliente_id' => $beta->id, 'designacao' => 'DC-B']);
        $upsAcme = Equipamento::create(['local_id' => $localA->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-ACME-1', 'modelo' => 'NPW 2000']);
        $outroAcme = Equipamento::create(['local_id' => $localA->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-ACME-2', 'modelo' => 'MST 60']);
        $upsBeta = Equipamento::create(['local_id' => $localB->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-BETA-1', 'modelo' => 'NPW 2000']);

        $c = Livewire::actingAs($admin)->test(Listagem::class);

        // Por série: encontra o equipamento seja de que cliente for.
        $c->set('pesquisa', 'SN-BETA-1')
            ->assertViewHas('equipamentos', fn ($p) => $p->pluck('id')->contains($upsBeta->id) && $p->total() === 1);

        // Por modelo: devolve os NPW dos DOIS clientes.
        $c->set('pesquisa', 'NPW')
            ->assertViewHas('equipamentos', fn ($p) => $p->pluck('id')->contains($upsAcme->id)
                && $p->pluck('id')->contains($upsBeta->id)
                && ! $p->pluck('id')->contains($outroAcme->id));

        // Pelo NOME do cliente: continua a funcionar na mesma caixa.
        $c->set('pesquisa', 'ACME')
            ->assertViewHas('equipamentos', fn ($p) => $p->total() === 2);

        // Limpar mostra todos.
        $c->set('pesquisa', '')
            ->assertViewHas('equipamentos', fn ($p) => $p->total() === 3);
    }

    // Equipamentos "por associar" (vieram do PHC sem cliente na fatura): aparecem na listagem
    // e na pesquisa por série, e a ficha permite associar o cliente.
    public function test_equipamento_sem_cliente_aparece_e_associa_se_na_ficha(): void
    {
        $admin = $this->admin();
        $e = Equipamento::create(['local_id' => null, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'AM34UT975400001']);

        Livewire::actingAs($admin)->test(Listagem::class)
            ->set('pesquisa', 'AM34UT9754')
            ->assertViewHas('equipamentos', fn ($p) => $p->pluck('id')->contains($e->id))
            ->assertSee('por associar');

        // A ficha abre sem rebentar e mostra o estado "por associar".
        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $e])
            ->assertSee('Sem cliente — por associar');

        // Associar o cliente pela ficha aterra na "Instalação principal" dele.
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $e])
            ->call('mudarCliente', $cliente->id)
            ->assertHasNoErrors();

        $this->assertSame($cliente->id, $e->fresh()->local->cliente_id);
    }

    public function test_cliente_e_obrigatorio(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Novo::class)
            ->set('modelo', 'Sem cliente')
            ->call('guardar')
            ->assertHasErrors('cliente_id');
    }

    // PDU saiu do catálogo do registo manual (o case do enum mantém-se p/ equipamentos legados).
    public function test_pdu_nao_e_aceite_no_registo_manual(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('modelo', 'PDU X')
            ->set('tipo', 'pdu')
            ->call('guardar')
            ->assertHasErrors('tipo');
    }

    // As secções dependem do tipo: bancos são de UPS, componentes de incêndio/sistema.
    // Dados preenchidos ANTES de mudar o tipo não podem gravar-se "escondidos".
    public function test_bancos_e_componentes_so_gravam_no_tipo_a_que_pertencem(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        // Gerador: nem bancos nem componentes, mesmo que tenham ficado preenchidos.
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('modelo', 'Gerador Y')
            ->set('bancos', [['numero_serie' => 'BANK-Z', 'modelo' => '', 'capacidade' => '', 'num_baterias' => '8', 'data_instalacao' => '', 'proxima_troca' => '2027-01-01']])
            ->set('componentes', [['designacao' => 'Peça avulsa', 'quantidade' => 2]])
            ->set('tipo', 'gerador')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'Gerador Y')->firstOrFail();
        $this->assertArrayNotHasKey('bancos', $eq->atributos ?? []);
        $this->assertArrayNotHasKey('componentes', $eq->atributos ?? []);
        $this->assertNull($eq->proxima_troca_baterias);

        // UPS: bancos E componentes (as modulares são compostas por chassis/módulos/gestão).
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('modelo', 'UPS Z')
            ->set('tipo', 'ups')
            ->set('bancos', [['numero_serie' => 'BANK-U', 'modelo' => '', 'capacidade' => '', 'num_baterias' => '4', 'data_instalacao' => '', 'proxima_troca' => '']])
            ->set('componentes', [['designacao' => 'Módulo de potência 25kVA MPX 25 PM', 'quantidade' => 3]])
            ->call('guardar')
            ->assertHasNoErrors();

        $ups = Equipamento::where('modelo', 'UPS Z')->firstOrFail();
        $this->assertSame('BANK-U', $ups->atributos['bancos'][0]['numero_serie']);
        $this->assertSame('Módulo de potência 25kVA MPX 25 PM', $ups->atributos['componentes'][0]['designacao']);
        $this->assertEquals(3, $ups->atributos['componentes'][0]['quantidade']);
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

    // Histórico de intervenções: com relatório ligado, a linha abre-o; sem relatório, não há link.
    public function test_historico_de_intervencoes_liga_ao_relatorio_respetivo(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-HIST']);

        $comRelatorio = Intervencao::create(['equipamento_id' => $eq->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => '2026-07-20']);
        $relatorio = Relatorio::create(['intervencao_id' => $comRelatorio->id, 'numero' => '2026/0042', 'data' => now(), 'estado' => 'finalizado']);
        Intervencao::create(['equipamento_id' => $eq->id, 'tipo' => 'preventiva', 'estado' => 'em_curso', 'data_inicio' => '2026-07-21']); // sem relatório

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $eq])
            ->assertSeeHtml(route('relatorios.editar', $relatorio)) // linha com relatório → link para ele
            ->assertSee('Relatório 2026/0042');
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

    public function test_novo_equipamento_guarda_vario_s_bancos_com_total_e_proxima_troca_mais_proxima(): void
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

    public function test_seccao_escondida_com_dados_invalidos_nao_bloqueia_o_guardar(): void
    {
        // Preencheu bancos (com data inválida) como UPS e depois mudou o tipo para gerador:
        // a secção escondeu-se — a validação NÃO pode rebentar a apontar para campos
        // invisíveis (a gravação descarta os dados escondidos de qualquer forma).
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('tipo', 'gerador')
            ->set('bancos', [['numero_serie' => 'X', 'modelo' => '', 'capacidade' => '',
                'num_baterias' => 'nao-e-numero', 'data_instalacao' => 'data-invalida', 'proxima_troca' => '']])
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect();

        $eq = Equipamento::whereNull('id_erp')->firstOrFail();
        $this->assertSame('gerador', $eq->tipo->value);
        $this->assertNull($eq->atributos['bancos'] ?? null); // os bancos escondidos não gravaram
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

    // ---- Tipos novos: monitorização ambiental e diversos (soluções pontuais, com descrição) ----

    public function test_novo_equipamento_monitorizacao_ambiental_com_componentes(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('tipo', 'ambiental')
            ->set('modelo', 'Monit. sala técnica')
            ->call('adicionarComponente')
            ->set('componentes.0.designacao', 'Sonda de temperatura e humidade')
            ->set('componentes.0.quantidade', '3')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'Monit. sala técnica')->firstOrFail();
        $this->assertSame('ambiental', $eq->tipo->value);
        $this->assertSame('Monitorização ambiental', $eq->tipo->rotulo());
        $this->assertCount(1, $eq->atributos['componentes']);
        $this->assertEquals(3, $eq->atributos['componentes'][0]['quantidade']);
    }

    public function test_tipo_diversos_exige_descricao_e_mostra_a_na_ficha(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        // Sem descrição não grava — em "Diversos" o tipo não diz nada, a descrição é obrigatória.
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('tipo', 'diversos')
            ->set('modelo', 'Solução pontual')
            ->call('guardar')
            ->assertHasErrors('tipo_descricao');

        // Com descrição grava (em atributos) e a ficha mostra-a junto à etiqueta do tipo.
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('tipo', 'diversos')
            ->set('modelo', 'Solução pontual')
            ->set('tipo_descricao', 'Controlo de acessos da portaria')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'Solução pontual')->firstOrFail();
        $this->assertSame('diversos', $eq->tipo->value);
        $this->assertSame('Controlo de acessos da portaria', $eq->atributos['tipo_descricao']);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $eq])
            ->assertSee('Diversos')
            ->assertSee('Controlo de acessos da portaria');
    }

    public function test_descricao_de_diversos_nao_grava_noutros_tipos(): void
    {
        // Preencheu a descrição em "Diversos" e depois mudou o tipo: o campo escondeu-se,
        // não pode gravar-se "às escondidas" nem bloquear a validação.
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('tipo', 'diversos')
            ->set('tipo_descricao', 'Ficou para trás')
            ->set('modelo', 'Gerador W')
            ->set('tipo', 'gerador')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'Gerador W')->firstOrFail();
        $this->assertArrayNotHasKey('tipo_descricao', $eq->atributos ?? []);
    }

    // Compor o sistema pesquisando por referência do PHC (catálogo local `artigos`, sincronizado).
    public function test_componentes_adicionam_se_por_referencia_do_phc(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $artigo = Artigo::create(['id_erp' => 'DET-701P', 'designacao' => 'Detetor ótico convencional 701P', 'faminome' => 'Deteção de incêndio']);
        Artigo::create(['id_erp' => 'BAT-12V', 'designacao' => 'Bateria 12V 9Ah']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('tipo', 'incendio')
            ->set('modelo', 'SADI com artigos')
            // Pesquisa por referência OU designação — só o detetor casa com "701".
            ->set('artigoBusca', '701')
            ->assertViewHas('artigosFiltrados', fn ($a) => $a->pluck('id')->contains($artigo->id) && $a->count() === 1)
            ->call('adicionarComponenteArtigo', $artigo->id)
            ->assertSet('artigoBusca', '')                       // pesquisa limpa após escolher
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('modelo', 'SADI com artigos')->firstOrFail();
        // A referência viaja na designação do componente (pesquisável e visível no PDF).
        $this->assertSame('DET-701P — Detetor ótico convencional 701P', $eq->atributos['componentes'][0]['designacao']);
        $this->assertEquals(1, $eq->atributos['componentes'][0]['quantidade']);
    }

    public function test_ficha_tambem_adiciona_componentes_por_referencia_do_phc(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'incendio', 'estado' => 'operacional']);
        $artigo = Artigo::create(['id_erp' => 'CIL-NOVEC-106', 'designacao' => 'Cilindro Novec 1230 106L']);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $eq])
            ->set('artigoBusca', 'novec')
            ->call('adicionarComponenteArtigo', $artigo->id)
            ->call('guardarComponentes')
            ->assertHasNoErrors();

        $eq->refresh();
        $this->assertSame('CIL-NOVEC-106 — Cilindro Novec 1230 106L', $eq->atributos['componentes'][0]['designacao']);
    }

    // Alertas de manutenção na ficha: linhas data + TEXTO EDITÁVEL; gravar substitui o
    // conjunto, reabrir mostra as linhas e sem data não grava.
    public function test_ficha_programa_alertas_de_manutencao_com_texto_editavel(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-AL']);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $eq])
            ->call('adicionarAlertaManutencao')
            ->set('alertasManutencao.0.data', now()->addMonth()->toDateString())
            ->set('alertasManutencao.0.texto', 'Teste de autonomia anual')
            ->call('guardarAlertasManutencao')
            ->assertHasNoErrors();

        $this->assertCount(1, $eq->fresh()->alertasManutencao);
        $this->assertSame('Teste de autonomia anual', $eq->fresh()->alertasManutencao->first()->texto);

        // Reabrir mostra a linha; linha nova sem data não grava; remover tudo apaga.
        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $eq->fresh()])
            ->assertSet('alertasManutencao.0.texto', 'Teste de autonomia anual')
            ->call('adicionarAlertaManutencao')
            ->call('guardarAlertasManutencao')
            ->assertHasErrors('alertasManutencao.1.data')
            ->call('removerAlertaManutencao', 1)
            ->call('removerAlertaManutencao', 0)
            ->call('guardarAlertasManutencao')
            ->assertHasNoErrors();

        $this->assertCount(0, $eq->fresh()->alertasManutencao);
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
