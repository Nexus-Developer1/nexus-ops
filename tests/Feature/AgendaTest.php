<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Enums\EstadoEvento;
use App\Enums\EstadoIntervencao;
use App\Enums\PapelUtilizador;
use App\Enums\TipoEvento;
use App\Enums\TipoIntervencao;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Contratos\Ficha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use App\Services\Agenda\GeradorIcal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private function contratoComUps(string $numero, Carbon $inicio, Carbon $fim): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40']);
        $contrato = Contrato::create([
            'numero' => $numero, 'cliente_id' => $cliente->id,
            'data_inicio' => $inicio, 'data_fim' => $fim,
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync([$equip->id]);

        return [$contrato, $equip];
    }

    public function test_ativar_contrato_nao_gera_visitas_na_agenda(): void
    {
        // (Fase 2) Ativar já NÃO gera visitas — passam a ser agendadas à mão na agenda.
        [$contrato] = $this->contratoComUps('2026/0003', now(), now()->addMonths(6));

        Livewire::actingAs($this->admin())
            ->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $this->assertSame(EstadoContrato::Ativo, $contrato->fresh()->estado);
        $this->assertSame(0, $contrato->eventos()->count()); // nenhuma visita criada
    }

    public function test_reagendar_deteta_conflito_de_tecnico(): void
    {
        $tec = $this->tecnico();
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);

        $a = EventoAgenda::create(['tipo' => 'intervencao', 'titulo' => 'A', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-01 09:00'), 'fim' => Carbon::parse('2026-07-01 10:00'), 'tecnico_id' => $tec->id, 'cliente_id' => $cliente->id]);
        $b = EventoAgenda::create(['tipo' => 'intervencao', 'titulo' => 'B', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-01 14:00'), 'fim' => Carbon::parse('2026-07-01 15:00'), 'tecnico_id' => $tec->id, 'cliente_id' => $cliente->id]);

        // Tenta mover B para cima de A → conflito; B não deve mudar.
        Livewire::actingAs($this->admin())
            ->test(Calendario::class)
            ->call('reagendar', $b->id, '2026-07-01T09:30:00', '2026-07-01T10:30:00', $tec->id)
            ->assertReturned(fn ($r) => $r['ok'] === false);

        $this->assertEquals('2026-07-01 14:00:00', $b->fresh()->inicio->format('Y-m-d H:i:s'));
    }

    private function eventoVisita(): EventoAgenda
    {
        $tec = $this->tecnico();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40']);

        return EventoAgenda::create([
            'tipo' => 'visita_preventiva', 'titulo' => 'Preventiva · APC X40', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-01 09:00'), 'fim' => Carbon::parse('2026-07-01 10:30'),
            'tecnico_id' => $tec->id, 'cliente_id' => $cliente->id, 'local_id' => $local->id, 'equipamento_id' => $equip->id,
        ]);
    }

    public function test_iniciar_visita_converte_evento_em_intervencao(): void
    {
        $evento = $this->eventoVisita();

        Livewire::actingAs($this->admin())
            ->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->call('iniciarVisita')
            ->assertRedirect();

        $evento->refresh();
        $this->assertNotNull($evento->intervencao_id);
        $this->assertSame(EstadoEvento::EmCurso, $evento->estado);

        $intervencao = Intervencao::findOrFail($evento->intervencao_id);
        $this->assertSame($evento->id, $intervencao->evento_agenda_id);          // regra de ouro: ligados
        $this->assertSame($evento->equipamento_id, $intervencao->equipamento_id);
        $this->assertSame($evento->tecnico_id, $intervencao->tecnico_id);
        $this->assertSame(TipoIntervencao::Preventiva, $intervencao->tipo);
        $this->assertSame(EstadoIntervencao::EmCurso, $intervencao->estado);
    }

    public function test_iniciar_visita_e_idempotente(): void
    {
        $evento = $this->eventoVisita();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Calendario::class)
            ->call('selecionar', $evento->id)->call('iniciarVisita');

        $idIntervencao = $evento->fresh()->intervencao_id;

        // Segunda tentativa não deve criar nova intervenção.
        Livewire::actingAs($admin)->test(Calendario::class)
            ->call('selecionar', $evento->id)->call('iniciarVisita');

        $this->assertSame(1, Intervencao::count());
        $this->assertSame($idIntervencao, $evento->fresh()->intervencao_id);
    }

    public function test_reagendar_fora_de_horario_e_bloqueado(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        // 2026-07-06 é segunda-feira.
        $e = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'X', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 09:00'), 'fim' => Carbon::parse('2026-07-06 10:00'), 'cliente_id' => $cliente->id]);

        // Mover para as 22:00 (fora do horário) → bloqueado.
        Livewire::actingAs($admin)->test(Calendario::class)
            ->call('reagendar', $e->id, '2026-07-06T22:00:00', '2026-07-06T23:00:00', null)
            ->assertReturned(fn ($r) => $r['ok'] === false);

        // Mover para sábado (fim-de-semana) → bloqueado.
        Livewire::actingAs($admin)->test(Calendario::class)
            ->call('reagendar', $e->id, '2026-07-11T10:00:00', '2026-07-11T11:00:00', null)
            ->assertReturned(fn ($r) => $r['ok'] === false);
    }

    public function test_criar_evento_guarda_conta_do_tecnico_sem_email(): void
    {
        Notification::fake();
        $tec = $this->tecnico();

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Reunião de equipa')
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-07-06T10:00')
            ->set('formFim', '2026-07-06T11:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        // Guarda a CONTA (liga conflitos/iCal) + o nome desnormalizado (cores/filtro).
        $this->assertDatabaseHas('eventos_agenda', ['titulo' => 'Reunião de equipa', 'tipo' => 'outro',
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome]);
        $this->assertDatabaseHas('assuntos_evento', ['nome' => 'Reunião de equipa']);
        Notification::assertNothingSent(); // criar evento já NÃO envia email ao técnico
    }

    public function test_evento_com_varios_tecnicos_guarda_pivot(): void
    {
        Notification::fake();
        $tec = $this->tecnico(); // 'Téc'
        $maria = User::create(['nome' => 'Maria Costa', 'email' => 'm@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Instalação a dois')
            ->set('formTecnicoIds', [$tec->id, $maria->id])
            ->set('formInicio', '2026-07-06T10:00')
            ->set('formFim', '2026-07-06T11:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        // Principal = 1.º por ordem alfabética (Maria Costa < Téc); o outro fica na pivot.
        $e = EventoAgenda::where('titulo', 'Instalação a dois')->firstOrFail();
        $this->assertSame($maria->id, $e->tecnico_id);
        $this->assertSame('Maria Costa', $e->tecnico_nome);
        $this->assertSame([$tec->id], $e->tecnicosAdicionais()->pluck('utilizadores.id')->all());
        $this->assertEqualsCanonicalizing([$maria->id, $tec->id], $e->tecnicoIdsTodos());

        // Sem emails (a notificação de atribuição foi removida); o label mostra os dois.
        Notification::assertNothingSent();
        $this->assertStringContainsString('Maria Costa', $e->fresh()->tecnico_label);
        $this->assertStringContainsString('Téc', $e->fresh()->tecnico_label);

        // O feed iCal do técnico ADICIONAL também inclui o evento.
        $ics = app(GeradorIcal::class)->paraTecnico($tec);
        $this->assertStringContainsString('Instala', $ics);
    }

    public function test_conflito_detetado_para_o_segundo_tecnico(): void
    {
        Notification::fake();
        $tec = $this->tecnico();
        $maria = User::create(['nome' => 'Maria Costa', 'email' => 'm@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'C2', 'email' => 'c2@x.pt', 'ativo' => true]);
        // O Téc (que alfabeticamente seria o SEGUNDO) já tem um evento no horário.
        EventoAgenda::create(['tipo' => 'intervencao', 'titulo' => 'Ocupado', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 10:00'), 'fim' => Carbon::parse('2026-07-06 11:00'),
            'tecnico_id' => $tec->id, 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Visita a dois')
            ->set('formTecnicoIds', [$maria->id, $tec->id])
            ->set('formInicio', '2026-07-06T10:00')
            ->set('formFim', '2026-07-06T11:00')
            ->call('criarEvento')
            ->assertHasErrors('formInicio'); // o conflito do 2.º técnico também bloqueia

        $this->assertDatabaseMissing('eventos_agenda', ['titulo' => 'Visita a dois']);
    }

    public function test_criar_evento_deteta_conflito_com_evento_legado_por_nome(): void
    {
        Notification::fake();
        $tec = $this->tecnico(); // nome 'Téc'
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        // Evento LEGADO: só o nome em texto (sem conta), mesmo nome da conta do técnico.
        EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Existente', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 10:00'), 'fim' => Carbon::parse('2026-07-06 11:00'),
            'tecnico_nome' => $tec->nome, 'tecnico_id' => null, 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Nova')
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-07-06T10:30')
            ->set('formFim', '2026-07-06T11:30')
            ->call('criarEvento')
            ->assertHasErrors('formInicio'); // sobreposição apanhada pelo NOME (evento legado)

        $this->assertDatabaseMissing('eventos_agenda', ['titulo' => 'Nova']);
    }

    public function test_evento_com_equipamento_herda_local_e_cliente(): void
    {
        Notification::fake();
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40', 'numero_serie' => 'SN-001']);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Inspeção')
            ->set('formEquipamentoId', $equip->id)
            ->set('formInicio', '2026-07-06T10:00')
            ->set('formFim', '2026-07-06T11:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        // Equipamento escolhido → evento herda local_id e cliente_id (equipamento → local → cliente).
        $this->assertDatabaseHas('eventos_agenda', [
            'titulo' => 'Inspeção',
            'equipamento_id' => $equip->id,
            'local_id' => $local->id,
            'cliente_id' => $cliente->id,
        ]);
    }

    public function test_evento_futuro_com_equipamento_gera_rascunho_de_relatorio(): void
    {
        Notification::fake();
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40', 'numero_serie' => 'SN-002']);

        $inicio = now()->addWeek()->setTime(10, 0);
        $fim = (clone $inicio)->setTime(11, 30);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Inspeção')
            ->set('formEquipamentoId', $equip->id)
            ->set('formInicio', $inicio->format('Y-m-d\TH:i'))
            ->set('formFim', $fim->format('Y-m-d\TH:i'))
            ->call('criarEvento')
            ->assertHasNoErrors();

        $evento = EventoAgenda::where('titulo', 'Inspeção')->firstOrFail();

        // Intervenção planeada, ligada ao evento, com contexto herdado (incl. horas).
        // Visita agendada → nasce PREVENTIVA (o gerador de rascunho mudou de propósito).
        $this->assertDatabaseHas('intervencoes', [
            'evento_agenda_id' => $evento->id,
            'equipamento_id' => $equip->id,
            'tipo' => 'preventiva',
            'estado' => 'planeada',
            'hora_inicio' => '10:00:00',
            'hora_fim' => '11:30:00',
        ]);
        $this->assertEquals($inicio->toDateString(), $evento->intervencao->data_inicio->toDateString());

        // Os dois lados da ligação ficam preenchidos.
        $this->assertNotNull($evento->intervencao_id);

        // Relatório em rascunho, sem número.
        $this->assertDatabaseHas('relatorios', [
            'intervencao_id' => $evento->intervencao_id,
            'estado' => 'rascunho',
            'numero' => null,
        ]);
    }

    public function test_evento_sem_equipamento_nao_gera_rascunho(): void
    {
        Notification::fake();

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Reunião')
            ->set('formInicio', now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('formFim', now()->addWeek()->addHour()->format('Y-m-d\TH:i'))
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('intervencoes', 0);
        $this->assertDatabaseCount('relatorios', 0);
    }

    public function test_evento_com_equipamento_mas_data_passada_nao_gera_rascunho(): void
    {
        Notification::fake();
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40', 'numero_serie' => 'SN-003']);

        // Dia útil no passado, em horário comercial (evita o bloqueio de fim-de-semana/horário).
        // inicio < now() → o evento cria-se, mas NÃO gera rascunho (apesar de ter equipamento).
        $passado = now()->subWeekdays(5);
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Inspeção')
            ->set('formEquipamentoId', $equip->id)
            ->set('formInicio', (clone $passado)->setTime(10, 0)->format('Y-m-d\TH:i'))
            ->set('formFim', (clone $passado)->setTime(11, 0)->format('Y-m-d\TH:i'))
            ->call('criarEvento')
            ->assertHasNoErrors();

        // O evento foi mesmo criado (para garantir que testamos o gancho, não a validação).
        $this->assertDatabaseHas('eventos_agenda', ['titulo' => 'Inspeção', 'equipamento_id' => $equip->id]);

        $this->assertDatabaseCount('intervencoes', 0);
        $this->assertDatabaseCount('relatorios', 0);
    }

    public function test_feed_ical_do_tecnico(): void
    {
        $tec = $this->tecnico();
        $cliente = Cliente::create(['nome' => 'Central Norte', 'ativo' => true]);
        EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'Preventiva · UPS', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 09:00'), 'fim' => Carbon::parse('2026-07-06 10:00'), 'tecnico_id' => $tec->id, 'cliente_id' => $cliente->id]);

        $url = URL::signedRoute('agenda.ical', ['tecnico' => $tec->id]);

        $resposta = $this->get($url);
        $resposta->assertOk();
        $resposta->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('BEGIN:VCALENDAR', $resposta->getContent());
        $this->assertStringContainsString('SUMMARY:Preventiva · UPS', $resposta->getContent());
        $this->assertStringContainsString('LOCATION:Central Norte', $resposta->getContent());
    }

    public function test_feed_ical_sem_assinatura_e_rejeitado(): void
    {
        $tec = $this->tecnico();
        $this->get(route('agenda.ical', ['tecnico' => $tec->id]))->assertForbidden();
    }

    public function test_reagendar_sem_conflito_persiste(): void
    {
        $tec = $this->tecnico();
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        $e = EventoAgenda::create(['tipo' => 'intervencao', 'titulo' => 'X', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-20 09:00'), 'fim' => Carbon::parse('2026-07-20 10:00'), 'tecnico_id' => $tec->id, 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())
            ->test(Calendario::class)
            ->call('reagendar', $e->id, '2026-07-21T11:00:00', '2026-07-21T12:00:00', $tec->id)
            ->assertReturned(fn ($r) => $r['ok'] === true);

        $this->assertEquals('2026-07-21 11:00:00', $e->fresh()->inicio->format('Y-m-d H:i:s'));
    }

    // ---- Edição de evento (abrirEdicao + criarEvento com editandoId) ----

    public function test_editar_evento_proprio_altera_titulo_tecnico_e_horas(): void
    {
        Notification::fake();
        $tec = $this->tecnico();
        $maria = User::create(['nome' => 'Maria Costa', 'email' => 'm@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        $e = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 10:00'), 'fim' => Carbon::parse('2026-07-06 11:00'),
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome, 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->call('abrirEdicao')
            // O formulário abre pré-preenchido com os dados do evento.
            ->assertSet('editandoId', $e->id)
            ->assertSet('modalCriar', true)
            ->assertSet('formTitulo', 'Reunião')
            ->assertSet('formTecnicoIds', [$tec->id])
            ->assertSet('formInicio', '2026-07-06T10:00')
            // Altera e grava (troca o técnico para a Maria).
            ->set('formTitulo', 'Reunião com cliente')
            ->set('formTecnicoIds', [$maria->id])
            ->set('formInicio', '2026-07-07T14:00')
            ->set('formFim', '2026-07-07T15:00')
            ->call('criarEvento')
            ->assertHasNoErrors()
            ->assertSet('modalCriar', false)
            ->assertSet('editandoId', null);

        $e->refresh();
        $this->assertSame('Reunião com cliente', $e->titulo);
        $this->assertSame($maria->id, $e->tecnico_id);
        $this->assertSame('Maria Costa', $e->tecnico_nome);
        $this->assertSame('2026-07-07 14:00', $e->inicio->format('Y-m-d H:i'));
        $this->assertSame(TipoEvento::Outro, $e->tipo); // o tipo não muda na edição
        $this->assertSame(1, EventoAgenda::count()); // editou — não criou um novo
        Notification::assertNothingSent(); // editar também não envia emails
    }

    public function test_editar_evento_legado_casa_o_nome_com_a_conta(): void
    {
        $tec = $this->tecnico(); // conta 'Téc'
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        // Evento LEGADO: só o nome em texto, igual ao da conta.
        $e = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 10:00'), 'fim' => Carbon::parse('2026-07-06 11:00'),
            'tecnico_nome' => $tec->nome, 'tecnico_id' => null, 'cliente_id' => $cliente->id]);

        // Ao abrir a edição, o nome legado é casado com a conta e pré-selecionado.
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->call('abrirEdicao')
            ->assertSet('formTecnicoIds', [$tec->id])
            ->call('criarEvento')
            ->assertHasNoErrors();

        // Depois de gravar, o evento legado fica LIGADO à conta (iCal/notificações passam a contar).
        $e->refresh();
        $this->assertSame($tec->id, $e->tecnico_id);
        $this->assertSame($tec->nome, $e->tecnico_nome);
    }

    public function test_editar_evento_nao_conflitua_consigo_mesmo(): void
    {
        Notification::fake();
        $tec = $this->tecnico();
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        $e = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 10:00'), 'fim' => Carbon::parse('2026-07-06 11:00'),
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome, 'cliente_id' => $cliente->id]);

        // Guardar SEM mexer nas horas: o próprio evento não pode contar como sobreposição.
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->call('abrirEdicao')
            ->set('formTitulo', 'Reunião (atualizada)')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertSame('Reunião (atualizada)', $e->fresh()->titulo);
    }

    public function test_editar_deteta_conflito_com_outro_evento_do_tecnico(): void
    {
        Notification::fake();
        $tec = $this->tecnico();
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Ocupado', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 14:00'), 'fim' => Carbon::parse('2026-07-06 15:00'),
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome, 'cliente_id' => $cliente->id]);
        $e = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 10:00'), 'fim' => Carbon::parse('2026-07-06 11:00'),
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome, 'cliente_id' => $cliente->id]);

        // Mover para cima do "Ocupado" → conflito real detetado.
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->call('abrirEdicao')
            ->set('formInicio', '2026-07-06T14:30')
            ->set('formFim', '2026-07-06T15:30')
            ->call('criarEvento')
            ->assertHasErrors('formInicio');

        $this->assertSame('2026-07-06 10:00', $e->fresh()->inicio->format('Y-m-d H:i')); // intacto
    }

    public function test_preventiva_e_relatorio_finalizado_nao_sao_editaveis(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);

        $preventiva = EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'Preventiva', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 09:00'), 'fim' => Carbon::parse('2026-07-06 10:00'), 'cliente_id' => $cliente->id]);

        // Evento convertido cujo relatório JÁ FOI FINALIZADO — documento oficial, evento trancado.
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        $interv->relatorio()->create(['numero' => '2026/0100', 'data' => now(), 'estado' => 'finalizado']);
        $finalizado = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Finalizado', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 11:00'), 'fim' => Carbon::parse('2026-07-06 12:00'),
            'cliente_id' => $cliente->id, 'intervencao_id' => $interv->id]);

        // Nenhum dos dois abre o modal de edição (guard defensivo do abrirEdicao).
        $admin = $this->admin();
        foreach ([$preventiva, $finalizado] as $evento) {
            Livewire::actingAs($admin)->test(Calendario::class)
                ->call('selecionar', $evento->id)
                ->call('abrirEdicao')
                ->assertSet('modalCriar', false)
                ->assertSet('editandoId', null);
        }
    }

    public function test_editar_evento_convertido_com_rascunho_propaga_datas_a_intervencao(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);

        // Evento convertido com relatório em RASCUNHO (o caso normal: criado com equipamento).
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada',
            'data_inicio' => '2026-07-06', 'hora_inicio' => '10:00', 'hora_fim' => '11:00']);
        $interv->relatorio()->create(['data' => now(), 'estado' => 'rascunho']);
        $e = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 10:00'), 'fim' => Carbon::parse('2026-07-06 11:00'),
            'cliente_id' => $cliente->id, 'equipamento_id' => $equip->id, 'intervencao_id' => $interv->id]);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->call('abrirEdicao')
            ->assertSet('editandoId', $e->id)
            ->assertSet('editandoConvertido', true)
            ->set('formTitulo', 'Serviço (adiado)')
            ->set('formInicio', '2026-07-07T14:00')
            ->set('formFim', '2026-07-07T15:30')
            ->call('criarEvento')
            ->assertHasNoErrors();

        // Evento atualizado E intervenção sincronizada (datas/horas iguais dos dois lados).
        $e->refresh();
        $interv->refresh();
        $this->assertSame('Serviço (adiado)', $e->titulo);
        $this->assertSame('2026-07-07 14:00', $e->inicio->format('Y-m-d H:i'));
        $this->assertSame('2026-07-07', $interv->data_inicio->format('Y-m-d'));
        $this->assertSame('14:00', substr($interv->hora_inicio, 0, 5));
        $this->assertSame('15:30', substr($interv->hora_fim, 0, 5));
        // Equipamento e contrato intactos (geridos no relatório).
        $this->assertSame($equip->id, $e->equipamento_id);
        $this->assertSame($equip->id, $interv->equipamento_id);
    }

    public function test_tecnicos_diferentes_tem_cores_diferentes(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        // Exatamente o par que colidia no esquema por hash (ambos caíam no índice 1 = azul).
        foreach (['Davide Fonseca', 'Rui Moreira'] as $nome) {
            EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'X', 'estado' => 'planeado',
                'inicio' => now(), 'fim' => now()->addHour(), 'tecnico_nome' => $nome, 'cliente_id' => $cliente->id]);
        }

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->assertViewHas('nomesTecnicos', function ($nomes) {
                $cores = array_column($nomes, 'cor', 'nome');

                return count($cores) === 2 && $cores['Davide Fonseca'] !== $cores['Rui Moreira'];
            });
    }
}
