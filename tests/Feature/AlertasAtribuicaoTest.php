<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Alertas\Painel;
use App\Livewire\Contratos\Editor;
use App\Livewire\DashboardGestao;
use App\Livewire\Equipamentos\Ficha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Notifications\ResumoAlertas;
use App\Services\Alertas\ServicoAlertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Alertas atribuídos a um utilizador (pedido da equipa): equipamentos e contratos escolhem
// "equipa completa" ou uma pessoa; eventos vão automaticamente aos técnicos do evento;
// despesas aos aprovadores. Cada um vê os da equipa + os seus; admin vê tudo; o email diário
// leva a cada técnico os alertas atribuídos a ele.
class AlertasAtribuicaoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rui;

    private User $julio;

    private Equipamento $eq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->rui = User::create(['nome' => 'Rui Pereira', 'email' => 'r@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->julio = User::create(['nome' => 'Julio Santos', 'email' => 'j@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $this->eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-AT']);
    }

    private function servico(): ServicoAlertas
    {
        return app(ServicoAlertas::class);
    }

    public function test_alerta_de_equipamento_atribuido_so_aparece_a_essa_pessoa_e_aos_admins(): void
    {
        $this->eq->alertasManutencao()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'SO-RUI', 'user_id' => $this->rui->id]);
        $this->eq->alertasManutencao()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'EQUIPA-TODA']);

        $todos = $this->servico()->recolher();
        $doRui = $todos->firstWhere('titulo', 'SO-RUI · Riello NPW');
        $this->assertSame([$this->rui->id], $doRui['atribuido_a']);
        $this->assertSame('Rui Pereira', $doRui['atribuido_nome']);
        $this->assertSame([], $todos->firstWhere('titulo', 'EQUIPA-TODA · Riello NPW')['atribuido_a']);

        $titulos = fn (User $u) => $this->servico()->recolherPara($u)->pluck('titulo')->all();
        $this->assertContains('SO-RUI · Riello NPW', $titulos($this->rui));
        $this->assertContains('EQUIPA-TODA · Riello NPW', $titulos($this->rui));
        $this->assertNotContains('SO-RUI · Riello NPW', $titulos($this->julio));   // não é dele
        $this->assertContains('EQUIPA-TODA · Riello NPW', $titulos($this->julio));
        $this->assertContains('SO-RUI · Riello NPW', $titulos($this->admin));      // admin vê tudo
    }

    public function test_alerta_de_evento_vai_aos_tecnicos_do_evento_principal_e_adicionais(): void
    {
        $ana = User::create(['nome' => 'Ana', 'email' => 'ana@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $evento = EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'Serviço', 'estado' => 'planeado', 'tecnico_id' => $this->rui->id,
            'inicio' => now()->addDays(3), 'fim' => now()->addDays(3)->addHours(2)]);
        $evento->tecnicosAdicionais()->attach($this->julio->id);
        $evento->alertas()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'Levar baterias']);

        $alerta = $this->servico()->recolher()->firstWhere('tipo', 'evento_programado');
        $this->assertEqualsCanonicalizing([$this->rui->id, $this->julio->id], $alerta['atribuido_a']);
        $this->assertSame('Rui Pereira, Julio Santos', $alerta['atribuido_nome']);

        $this->assertCount(1, $this->servico()->recolherPara($this->rui)->where('tipo', 'evento_programado'));
        $this->assertCount(1, $this->servico()->recolherPara($this->julio)->where('tipo', 'evento_programado'));
        $this->assertCount(0, $this->servico()->recolherPara($ana)->where('tipo', 'evento_programado'));
    }

    public function test_alerta_de_despesa_vai_aos_aprovadores_com_conta(): void
    {
        config(['despesas.aprovadores' => ['pgouveia@nxs.pt']]);
        $paulo = User::create(['nome' => 'Paulo Gouveia', 'email' => 'pgouveia@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        RegistoDespesa::create(['criado_por' => $this->rui->id, 'estado' => 'pendente', 'submetido_em' => now()]);

        $alerta = $this->servico()->recolher()->firstWhere('tipo', 'despesa_aprovacao');
        $this->assertSame([$paulo->id], $alerta['atribuido_a']);
        $this->assertCount(1, $this->servico()->recolherPara($paulo)->where('tipo', 'despesa_aprovacao'));
        $this->assertCount(0, $this->servico()->recolherPara($this->rui)->where('tipo', 'despesa_aprovacao'));
        $this->assertCount(1, $this->servico()->recolherPara($this->admin)->where('tipo', 'despesa_aprovacao'));
    }

    public function test_ficha_do_equipamento_grava_a_atribuicao_e_recusa_contas_invalidas(): void
    {
        $c = Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $this->eq])
            ->call('adicionarAlertaManutencao')
            ->set('alertasManutencao.0.data', now()->addMonth()->toDateString())
            ->set('alertasManutencao.0.texto', 'Teste anual')
            ->assertSee('Equipa completa')->assertSee('Rui Pereira');

        // Conta inexistente ou de cliente → recusado.
        $cliente = User::create(['nome' => 'Cli', 'email' => 'c@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Cliente, 'ativo' => true]);
        $c->set('alertasManutencao.0.user_id', (string) $cliente->id)->call('guardarAlertasManutencao')->assertHasErrors('alertasManutencao.0.user_id');
        $c->set('alertasManutencao.0.user_id', '9999')->call('guardarAlertasManutencao')->assertHasErrors('alertasManutencao.0.user_id');

        $c->set('alertasManutencao.0.user_id', (string) $this->rui->id)->call('guardarAlertasManutencao')->assertHasNoErrors();
        $this->assertSame($this->rui->id, $this->eq->fresh()->alertasManutencao->first()->user_id);

        // Reabrir mostra a atribuição; "Equipa completa" grava null.
        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $this->eq->fresh()])
            ->assertSet('alertasManutencao.0.user_id', (string) $this->rui->id)
            ->set('alertasManutencao.0.user_id', '')
            ->call('guardarAlertasManutencao')->assertHasNoErrors();
        $this->assertNull($this->eq->fresh()->alertasManutencao->first()->user_id);
    }

    public function test_editor_do_contrato_grava_a_atribuicao(): void
    {
        $contrato = Contrato::create(['numero' => 'C-AT', 'cliente_id' => $this->eq->local->cliente_id, 'data_inicio' => now()->subMonth(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'), 'renovacao_automatica' => false, 'periodo_aviso_dias' => 30]);
        $contrato->equipamentos()->sync([$this->eq->id]);

        Livewire::actingAs($this->admin)->test(Editor::class, ['contrato' => $contrato])
            ->call('adicionarAlertaVisita')
            ->set('alertasVisita.0.data', now()->addDays(2)->toDateString())
            ->set('alertasVisita.0.texto', 'Agendar visita')
            ->set('alertasVisita.0.user_id', (string) $this->julio->id)
            ->call('guardar')
            ->assertHasNoErrors();

        $alerta = $contrato->fresh()->alertasVisita->first();
        $this->assertSame($this->julio->id, $alerta->user_id);

        $doServico = $this->servico()->recolher()->firstWhere('tipo', 'visita_programada');
        $this->assertSame([$this->julio->id], $doServico['atribuido_a']);
        $this->assertSame('Julio Santos', $doServico['atribuido_nome']);
    }

    public function test_dashboard_e_painel_mostram_a_cada_um_o_que_lhe_cabe(): void
    {
        $this->eq->alertasManutencao()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'SO-RUI', 'user_id' => $this->rui->id]);
        $this->eq->alertasManutencao()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'SO-JULIO', 'user_id' => $this->julio->id]);
        $this->eq->alertasManutencao()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'EQUIPA-TODA']);

        // Dashboard: técnico vê os da equipa + os dele; admin vê tudo (com o nome de quem trata).
        Livewire::actingAs($this->rui)->test(DashboardGestao::class)
            ->assertSee('SO-RUI')->assertSee('EQUIPA-TODA')->assertDontSee('SO-JULIO');
        Livewire::actingAs($this->admin)->test(DashboardGestao::class)
            ->assertSee('SO-RUI')->assertSee('SO-JULIO')->assertSee('Rui Pereira');

        // Painel: técnico por defeito "os meus"; admin por defeito "todos"; filtros equipa / pessoa.
        Livewire::actingAs($this->julio)->test(Painel::class)
            ->assertSee('SO-JULIO')->assertSee('EQUIPA-TODA')->assertDontSee('SO-RUI')
            ->assertViewHas('alertas', fn ($a) => $a->count() === 2)
            ->set('atribuido', 'todos')->assertSee('SO-RUI');
        Livewire::actingAs($this->admin)->test(Painel::class)
            ->assertViewHas('alertas', fn ($a) => $a->count() === 3)
            ->assertSee('Atribuído a: Rui Pereira')->assertSee('Atribuído a: equipa completa')
            ->set('atribuido', 'equipa')->assertViewHas('alertas', fn ($a) => $a->count() === 1 && $a[0]['titulo'] === 'EQUIPA-TODA · Riello NPW')
            ->set('atribuido', (string) $this->rui->id)->assertViewHas('alertas', fn ($a) => $a->count() === 1 && $a[0]['titulo'] === 'SO-RUI · Riello NPW');
    }

    public function test_email_diario_leva_a_cada_tecnico_os_alertas_atribuidos_a_ele(): void
    {
        Notification::fake();
        $this->eq->alertasManutencao()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'SO-RUI', 'user_id' => $this->rui->id]);
        $this->eq->alertasManutencao()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'EQUIPA-TODA']);

        $this->artisan('alertas:verificar')->assertSuccessful();

        // Admin: resumo completo (2). Rui: só o dele (1), com a menção de atribuição. Júlio: nada.
        Notification::assertSentTo($this->admin, ResumoAlertas::class, fn (ResumoAlertas $n) => $n->alertas->count() === 2);
        Notification::assertSentTo($this->rui, ResumoAlertas::class, function (ResumoAlertas $n) {
            $html = (string) $n->toMail($this->rui)->render();

            return $n->alertas->count() === 1 && str_contains($html, 'SO-RUI') && str_contains($html, 'atribuído a Rui Pereira');
        });
        Notification::assertNotSentTo($this->julio, ResumoAlertas::class);
    }
}
