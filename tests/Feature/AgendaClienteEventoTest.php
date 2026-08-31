<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Campo "Cliente" no modal do evento (entre o tipo de evento e o equipamento): combobox por
// nome/NIF/nº ERP. Escolher o cliente restringe a pesquisa de equipamentos aos dele (e a lista
// aparece logo, sem escrever nada); escolher um equipamento preenche o cliente; um evento sem
// equipamento nem contrato fica com o cliente escolhido. Um evento tem um só cliente: trocar de
// cliente (ou tirá-lo) deita os equipamentos abaixo. Num evento convertido o cliente é do relatório.
class AgendaClienteEventoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $acme;

    private Cliente $beta;

    private Equipamento $ac1;

    private Equipamento $ac2;

    private Equipamento $bt1;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        $this->acme = Cliente::create(['nome' => 'Câmara de Évora', 'nif' => '500111222', 'id_erp' => 'C0042', 'ativo' => true]);
        $this->beta = Cliente::create(['nome' => 'BETA Lda', 'nif' => '500333444', 'id_erp' => 'C0099', 'ativo' => true]);
        $mk = fn (Cliente $c, string $sn) => Equipamento::create(['local_id' => Local::create(['cliente_id' => $c->id, 'designacao' => 'Sede '.$sn])->id,
            'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => $sn]);
        $this->ac1 = $mk($this->acme, 'AC-001');
        $this->ac2 = $mk($this->acme, 'AC-002');
        $this->bt1 = $mk($this->beta, 'BT-001');
    }

    private function modal()
    {
        return Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-08-10', '2026-08-10') // passado: não gera rascunho
            ->set('formTitulo', 'Serviço')
            ->set('formInicio', '2026-08-10T09:00')
            ->set('formFim', '2026-08-10T13:00');
    }

    public function test_pesquisa_por_nome_sem_acentos_nif_ou_numero_erp(): void
    {
        $this->modal()
            ->set('formClienteBusca', 'evora')->assertSee('Câmara de Évora')->assertDontSee('BETA Lda')
            ->set('formClienteBusca', '500333')->assertSee('BETA Lda')->assertDontSee('Câmara de Évora')
            ->set('formClienteBusca', 'C0042')->assertSee('Câmara de Évora');
    }

    public function test_escolher_cliente_lista_so_os_equipamentos_dele_mesmo_sem_escrever(): void
    {
        $this->modal()
            ->call('selecionarCliente', $this->acme->id)
            ->assertSet('formClienteId', $this->acme->id)
            ->assertSet('formClienteBusca', '')
            ->assertSee('AC-001')->assertSee('AC-002')->assertDontSee('BT-001') // caixa vazia, lista dele
            ->set('formEquipamentoBusca', 'riello')
            ->assertSee('AC-001')->assertDontSee('BT-001'); // a pesquisa continua restrita
    }

    public function test_evento_sem_equipamento_fica_com_o_cliente_escolhido(): void
    {
        $this->modal()
            ->call('selecionarCliente', $this->acme->id)
            ->call('criarEvento')->assertHasNoErrors();

        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();
        $this->assertSame($this->acme->id, $e->cliente_id);
        $this->assertNull($e->equipamento_id);
    }

    public function test_escolher_equipamento_preenche_o_cliente(): void
    {
        $this->modal()
            ->call('selecionarEquipamento', $this->bt1->id)
            ->assertSet('formClienteId', $this->beta->id)
            ->assertSee('BETA Lda')
            ->call('criarEvento')->assertHasNoErrors();

        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();
        $this->assertSame($this->beta->id, $e->cliente_id);
        $this->assertSame($this->bt1->id, $e->equipamento_id);
    }

    public function test_trocar_ou_tirar_o_cliente_deita_os_equipamentos_abaixo(): void
    {
        $c = $this->modal()
            ->call('selecionarEquipamento', $this->ac1->id)
            ->call('selecionarEquipamento', $this->ac2->id)
            ->assertSet('formClienteId', $this->acme->id)
            ->call('selecionarCliente', $this->acme->id) // o mesmo: nada muda
            ->assertSet('formEquipamentoId', $this->ac1->id)
            ->assertSet('formEquipamentosExtra', [$this->ac2->id])
            ->call('selecionarCliente', $this->beta->id) // outro: equipamentos fora
            ->assertSet('formClienteId', $this->beta->id)
            ->assertSet('formEquipamentoId', null)
            ->assertSet('formEquipamentosExtra', []);

        $c->call('selecionarEquipamento', $this->bt1->id)
            ->call('removerCliente')
            ->assertSet('formClienteId', null)
            ->assertSet('formEquipamentoId', null)
            ->assertSet('formEquipamentosExtra', []);
    }

    public function test_editar_traz_o_cliente_e_num_convertido_fica_trancado(): void
    {
        // Evento sem equipamento, só com cliente → editar mostra o cliente e deixa mudar.
        $this->modal()->call('selecionarCliente', $this->acme->id)->call('criarEvento')->assertHasNoErrors();
        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)->call('abrirEdicao')
            ->assertSet('formClienteId', $this->acme->id)
            ->assertSee('Câmara de Évora')
            ->call('selecionarCliente', $this->beta->id)
            ->call('criarEvento')->assertHasNoErrors();
        $this->assertSame($this->beta->id, $e->fresh()->cliente_id);

        // Evento FUTURO com equipamento → rascunho de relatório → cliente trancado ao relatório.
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-08', '2026-09-08')
            ->set('formTitulo', 'Preventiva')
            ->set('formInicio', '2026-09-08T09:00')->set('formFim', '2026-09-08T11:00')
            ->call('selecionarEquipamento', $this->ac1->id)
            ->call('criarEvento')->assertHasNoErrors();
        $f = EventoAgenda::where('titulo', 'Preventiva')->firstOrFail();
        $this->assertNotNull($f->intervencao_id);
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $f->id)->call('abrirEdicao')
            ->assertSet('editandoConvertido', true)
            ->assertSet('formClienteId', $this->acme->id)
            ->assertDontSeeHtml('wire:click="removerCliente"')
            ->call('selecionarCliente', $this->beta->id)
            ->call('removerCliente')
            ->assertSet('formClienteId', $this->acme->id)
            ->assertSet('formEquipamentoId', $this->ac1->id);
    }

    public function test_contratos_ficam_filtrados_ao_cliente_escolhido(): void
    {
        $mf = ModeloFaturacao::query()->value('id');
        $mkContrato = fn (Cliente $c, string $n) => Contrato::create(['numero' => $n, 'cliente_id' => $c->id, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31',
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => $mf]);
        $mkContrato($this->acme, '2026/0001');
        $mkContrato($this->beta, '2026/0002');

        $this->modal()
            ->assertSee('2026/0001')->assertSee('2026/0002')
            ->call('selecionarCliente', $this->acme->id)
            ->assertSee('2026/0001')->assertDontSee('2026/0002');
    }
}
