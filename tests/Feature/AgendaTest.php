<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Enums\EstadoEvento;
use App\Enums\PapelUtilizador;
use App\Enums\TipoEvento;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Contratos\Ficha;
use App\Enums\EstadoIntervencao;
use App\Enums\TipoIntervencao;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\EventoAgenda;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\TecnicoDisponibilidade;
use App\Models\User;
use App\Notifications\EventoAtribuido;
use App\Services\Agenda\GeradorVisitasPreventivas;
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
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva', 'modelo_faturacao_id' => \App\Models\ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync([$equip->id]);
        $contrato->planosVisita()->create(['equipamento_tipo' => 'ups', 'periodicidade' => 'trimestral', 'duracao_estimada_min' => 90]);

        return [$contrato, $equip];
    }

    public function test_gera_visitas_trimestrais_para_o_periodo(): void
    {
        // Um ano, trimestral → 5 ocorrências (mês 0,3,6,9,12).
        [$contrato] = $this->contratoComUps('2026/0001', now()->startOfYear(), now()->startOfYear()->addYear());

        $criadas = app(GeradorVisitasPreventivas::class)->gerar($contrato);

        $this->assertSame(5, $criadas);
        $this->assertSame(5, $contrato->eventos()->where('tipo', TipoEvento::VisitaPreventiva)->count());
        // Sem técnico atribuído e com duração de 90 min.
        $primeiro = $contrato->eventos()->orderBy('inicio')->first();
        $this->assertNull($primeiro->tecnico_id);
        $this->assertSame(90, (int) $primeiro->inicio->diffInMinutes($primeiro->fim));
    }

    public function test_geracao_e_idempotente(): void
    {
        [$contrato] = $this->contratoComUps('2026/0002', now()->startOfYear(), now()->startOfYear()->addYear());
        $gerador = app(GeradorVisitasPreventivas::class);

        $gerador->gerar($contrato);
        $gerador->gerar($contrato); // segunda passagem não deve duplicar

        $this->assertSame(5, $contrato->eventos()->count());
    }

    public function test_ativar_contrato_gera_visitas_na_agenda(): void
    {
        // QUEUE_CONNECTION=sync nos testes → o job corre de imediato.
        [$contrato] = $this->contratoComUps('2026/0003', now(), now()->addMonths(6));

        Livewire::actingAs($this->admin())
            ->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $this->assertSame(EstadoContrato::Ativo, $contrato->fresh()->estado);
        $this->assertGreaterThan(0, $contrato->eventos()->count());
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

    public function test_reagendar_bloqueado_por_ausencia(): void
    {
        $tec = $this->tecnico();
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        TecnicoDisponibilidade::create(['tecnico_id' => $tec->id, 'tipo' => 'ferias',
            'inicio' => Carbon::parse('2026-08-01 00:00'), 'fim' => Carbon::parse('2026-08-15 23:59'), 'motivo' => 'Férias']);

        $e = EventoAgenda::create(['tipo' => 'intervencao', 'titulo' => 'X', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-20 09:00'), 'fim' => Carbon::parse('2026-07-20 10:00'), 'tecnico_id' => $tec->id, 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())
            ->test(Calendario::class)
            ->call('reagendar', $e->id, '2026-08-05T09:00:00', '2026-08-05T10:00:00', $tec->id)
            ->assertReturned(fn ($r) => $r['ok'] === false);
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

    public function test_criar_evento_proprio_e_notifica_tecnico(): void
    {
        Notification::fake();
        $tec = $this->tecnico();

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Reunião de equipa')
            ->set('formTecnicoId', $tec->id)
            ->set('formInicio', '2026-07-06T10:00')
            ->set('formFim', '2026-07-06T11:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('eventos_agenda', ['titulo' => 'Reunião de equipa', 'tipo' => 'outro', 'tecnico_id' => $tec->id]);
        $this->assertDatabaseHas('assuntos_evento', ['nome' => 'Reunião de equipa']); // tipo livre fica guardado para sugestões
        Notification::assertSentTo($tec, EventoAtribuido::class);
    }

    public function test_criar_ausencia_gera_disponibilidade(): void
    {
        $tec = $this->tecnico();

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('ausTecnicoId', $tec->id)
            ->set('ausMotivo', 'Férias')
            ->set('ausInicio', '2026-08-03T00:00')
            ->set('ausFim', '2026-08-10T23:59')
            ->call('marcarAusencia')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tecnico_disponibilidade', ['tecnico_id' => $tec->id, 'motivo' => 'Férias']);
    }

    public function test_remover_ausencia(): void
    {
        $tec = $this->tecnico();
        $aus = TecnicoDisponibilidade::create(['tecnico_id' => $tec->id, 'tipo' => 'ferias',
            'inicio' => Carbon::parse('2026-08-01'), 'fim' => Carbon::parse('2026-08-05'), 'motivo' => 'X']);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionarAusencia', $aus->id)
            ->call('removerAusencia');

        $this->assertDatabaseMissing('tecnico_disponibilidade', ['id' => $aus->id]);
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
}
