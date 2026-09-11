<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Notifications\EventoAgendaNotificacao;
use App\Services\Agenda\CalendarioGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Assunto/motivo do evento (pedido da equipa, set. 2026): o tipo diz O QUE é, o assunto diz
// PARA QUÊ. Opcional — umas férias não precisam dele. Grava-se, reabre-se na edição, aparece
// no detalhe, no email e no convite aos técnicos (mudá-lo conta como alteração) e no
// calendário partilhado do Outlook.
class AgendaMotivoEventoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $paulo;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->paulo = User::create(['nome' => 'Paulo Bento', 'email' => 'p@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private static function desdobrar(string $ics): string
    {
        return preg_replace("/\r?\n[ \t]/", '', $ics);
    }

    private function criar(string $titulo, string $motivo, ?User $tecnico = null)
    {
        return Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-08', '2026-09-08')
            ->set('formTitulo', $titulo)
            ->set('formMotivo', $motivo)
            ->set('formInicio', '2026-09-08T09:00')->set('formFim', '2026-09-08T11:00')
            ->set('formTecnicoIds', [($tecnico ?? $this->tecnicoDeTeste())->id])
            ->call('criarEvento');
    }

    public function test_e_opcional_e_umas_ferias_gravam_sem_assunto(): void
    {
        $this->criar('Férias', '')->assertHasNoErrors();

        $this->assertNull(EventoAgenda::where('titulo', 'Férias')->firstOrFail()->motivo);
    }

    public function test_grava_reabre_na_edicao_e_aparece_no_detalhe(): void
    {
        $this->criar('Serviço', '  Substituição de baterias da UPS  ')->assertHasNoErrors();

        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();
        $this->assertSame('Substituição de baterias da UPS', $e->motivo); // aparado

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->assertSee('Assunto')
            ->assertSee('Substituição de baterias da UPS')
            ->call('abrirEdicao')
            ->assertSet('formMotivo', 'Substituição de baterias da UPS')
            ->set('formMotivo', '')
            ->call('criarEvento')->assertHasNoErrors();

        $this->assertNull($e->fresh()->motivo); // vazio grava null
    }

    public function test_limite_de_255_caracteres(): void
    {
        $this->criar('Serviço', str_repeat('x', 256))->assertHasErrors(['formMotivo' => 'max']);
    }

    public function test_vai_no_email_e_no_convite_e_mudar_o_assunto_avisa(): void
    {
        $this->criar('Serviço', 'Manutenção preventiva anual', $this->paulo)->assertHasNoErrors();
        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();

        $mail = Notification::sent($this->paulo, EventoAgendaNotificacao::class)->first()->toMail($this->paulo);
        $this->assertStringContainsString('Manutenção preventiva anual', (string) $mail->render());
        $this->assertStringContainsString('Assunto: Manutenção preventiva anual', self::desdobrar($mail->rawAttachments[0]['data']));

        // Só o assunto muda → conta como alteração, e o email diz o que mudou.
        Notification::fake();
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)->call('abrirEdicao')
            ->set('formMotivo', 'Avaria no bypass')
            ->call('criarEvento')->assertHasNoErrors();

        Notification::assertSentTo($this->paulo, EventoAgendaNotificacao::class,
            fn ($n) => $n->tipo === 'alterado' && $n->evento['motivo'] === 'Avaria no bypass');
        $html = (string) Notification::sent($this->paulo, EventoAgendaNotificacao::class)->first()->toMail($this->paulo)->render();
        $this->assertStringContainsString('Avaria no bypass', $html);
    }

    public function test_vai_no_corpo_do_evento_do_calendario_partilhado(): void
    {
        $this->criar('Serviço', 'Substituição de baterias da UPS')->assertHasNoErrors();
        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();

        $corpo = (new \ReflectionMethod(CalendarioGraph::class, 'corpo'))->invoke(app(CalendarioGraph::class), $e);

        $this->assertStringContainsString('Assunto: <strong>Substituição de baterias da UPS</strong>', $corpo['body']['content']);
    }
}
