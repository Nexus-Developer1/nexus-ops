<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use App\Services\Agenda\ConversorVisita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Um evento pode abranger VÁRIOS equipamentos do mesmo cliente: o 1.º escolhido é o principal
// (dá o cliente ao evento), os seguintes são adicionais (chips com ×). Os adicionais passam para os
// "cobertos" do relatório quando o rascunho nasce (ou a visita é iniciada); num evento já convertido,
// acrescentar/tirar aqui sincroniza os cobertos do relatório — uma só fonte de verdade. Equipamentos
// de outro cliente são recusados.
class AgendaEquipamentosExtraTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $acme;

    private Equipamento $ups1;

    private Equipamento $ups2;

    private Equipamento $ups3;

    private Equipamento $alheio;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        $this->acme = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $this->acme->id, 'designacao' => 'Sede']);
        $mk = fn (Local $l, string $sn) => Equipamento::create(['local_id' => $l->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => $sn]);
        $this->ups1 = $mk($local, 'AC-001');
        $this->ups2 = $mk($local, 'AC-002');
        $this->ups3 = $mk($local, 'AC-003');

        $beta = Cliente::create(['nome' => 'BETA', 'ativo' => true]);
        $this->alheio = $mk(Local::create(['cliente_id' => $beta->id, 'designacao' => 'DC']), 'BT-001');
    }

    private function modal()
    {
        return Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-08', '2026-09-08')
            ->set('formTitulo', 'Serviço')
            ->set('formInicio', '2026-09-08T09:00')
            ->set('formFim', '2026-09-08T13:00');
    }

    public function test_primeiro_e_principal_seguintes_sao_adicionais_e_ficam_na_pivot(): void
    {
        $c = $this->modal()
            ->call('selecionarEquipamento', $this->ups1->id)
            ->assertSet('formEquipamentoId', $this->ups1->id)
            ->call('selecionarEquipamento', $this->ups2->id)
            ->call('selecionarEquipamento', $this->ups3->id)
            ->call('selecionarEquipamento', $this->ups2->id) // repetido não duplica
            ->assertSet('formEquipamentosExtra', [$this->ups2->id, $this->ups3->id])
            ->assertSee('AC-002')->assertSee('AC-003');

        $c->call('removerEquipamentoExtra', $this->ups3->id)
            ->assertSet('formEquipamentosExtra', [$this->ups2->id])
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();

        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();
        $this->assertSame($this->ups1->id, $e->equipamento_id);
        $this->assertSame($this->acme->id, $e->cliente_id);
        $this->assertSame([$this->ups2->id], $e->equipamentosAdicionais()->pluck('equipamentos.id')->all());
        $this->assertSame([$this->ups1->id, $this->ups2->id], $e->equipamentoIdsTodos());
    }

    public function test_adicionais_passam_para_os_cobertos_do_rascunho_de_relatorio(): void
    {
        // Evento futuro com equipamento → nasce um rascunho (camada agenda → relatórios).
        $this->modal()
            ->call('selecionarEquipamento', $this->ups1->id)
            ->call('selecionarEquipamento', $this->ups2->id)
            ->call('selecionarEquipamento', $this->ups3->id)
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();

        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();
        $this->assertNotNull($e->intervencao_id);
        $i = $e->intervencao;
        $this->assertSame($this->ups1->id, $i->equipamento_id);
        $this->assertEqualsCanonicalizing([$this->ups2->id, $this->ups3->id], $i->equipamentosCobertos()->pluck('equipamentos.id')->all());
    }

    public function test_editar_evento_convertido_sincroniza_os_cobertos_do_relatorio(): void
    {
        $this->modal()
            ->call('selecionarEquipamento', $this->ups1->id)
            ->call('selecionarEquipamento', $this->ups2->id)
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();
        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();

        // Reabrir: o principal fica trancado ao relatório; os adicionais vêm dos cobertos.
        $c = Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->call('abrirEdicao')
            ->assertSet('editandoConvertido', true)
            ->assertSet('formEquipamentoId', $this->ups1->id)
            ->assertSet('formEquipamentosExtra', [$this->ups2->id]);

        // Acrescentar o 3.º e tirar o 2.º → cobertos do relatório = [3].
        $c->call('selecionarEquipamento', $this->ups3->id)
            ->assertSet('formEquipamentoId', $this->ups1->id) // num convertido nunca troca o principal
            ->call('removerEquipamentoExtra', $this->ups2->id)
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();

        $this->assertSame([$this->ups3->id], $e->intervencao->equipamentosCobertos()->pluck('equipamentos.id')->all());
        $this->assertSame([$this->ups3->id], $e->fresh()->equipamentosAdicionais()->pluck('equipamentos.id')->all());
    }

    // O bug reportado: em edição a caixa vinha com o texto do principal e a pesquisa corria com
    // esse texto (nada batia). A caixa fica vazia — o principal está no chip — e pesquisar mostra
    // os outros equipamentos para acrescentar.
    public function test_em_edicao_a_caixa_esta_vazia_e_a_pesquisa_encontra_para_acrescentar(): void
    {
        $this->modal()->call('selecionarEquipamento', $this->ups1->id)->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();
        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->call('abrirEdicao')
            ->assertSet('formEquipamentoBusca', '')          // caixa livre
            ->assertSet('formEquipamentoId', $this->ups1->id) // principal no chip
            ->assertSee('AC-001')
            ->set('formEquipamentoBusca', 'acme')             // escrever NÃO deita o principal abaixo
            ->assertSet('formEquipamentoId', $this->ups1->id)
            ->assertSee('AC-002')->assertSee('AC-003')        // a lista mostra os outros do cliente
            ->call('selecionarEquipamento', $this->ups2->id)
            ->assertSet('formEquipamentosExtra', [$this->ups2->id])
            ->assertSet('formEquipamentoBusca', '');           // e volta a ficar livre
    }

    public function test_tirar_o_principal_promove_o_primeiro_adicional_mas_nao_num_convertido(): void
    {
        $c = $this->modal()
            ->call('selecionarEquipamento', $this->ups1->id)
            ->call('selecionarEquipamento', $this->ups2->id)
            ->call('selecionarEquipamento', $this->ups3->id)
            ->call('removerEquipamentoPrincipal')
            ->assertSet('formEquipamentoId', $this->ups2->id)
            ->assertSet('formEquipamentosExtra', [$this->ups3->id]);
        $c->call('removerEquipamentoPrincipal')->call('removerEquipamentoPrincipal')
            ->assertSet('formEquipamentoId', null)
            ->assertSet('formEquipamentosExtra', []);

        // Convertido: o principal é do relatório e não sai pela agenda.
        $this->modal()->call('selecionarEquipamento', $this->ups1->id)->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();
        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)->call('abrirEdicao')
            ->assertDontSeeHtml('wire:click="removerEquipamentoPrincipal"')
            ->call('removerEquipamentoPrincipal')
            ->assertSet('formEquipamentoId', $this->ups1->id);
    }

    public function test_equipamento_de_outro_cliente_e_recusado(): void
    {
        $this->modal()
            ->call('selecionarEquipamento', $this->ups1->id)
            ->call('selecionarEquipamento', $this->alheio->id)
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')
            ->assertHasErrors('formEquipamentoId');

        $this->assertNull(EventoAgenda::where('titulo', 'Serviço')->first());
    }

    public function test_iniciar_visita_leva_os_adicionais_para_os_cobertos(): void
    {
        // Evento no PASSADO (não gera rascunho); ao iniciar a visita, a intervenção nasce com os cobertos.
        $e = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Visita', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-08-20 09:00'), 'fim' => Carbon::parse('2026-08-20 11:00'),
            'equipamento_id' => $this->ups1->id, 'cliente_id' => $this->acme->id]);
        $e->equipamentosAdicionais()->sync([$this->ups2->id]);

        $i = app(ConversorVisita::class)->iniciar($e, $this->admin->id);

        $this->assertSame($this->ups1->id, $i->equipamento_id);
        $this->assertSame([$this->ups2->id], $i->equipamentosCobertos()->pluck('equipamentos.id')->all());
        $this->assertSame(1, Intervencao::count());
    }
}
