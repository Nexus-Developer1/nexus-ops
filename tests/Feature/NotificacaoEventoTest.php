<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Notifications\EventoAgendaNotificacao;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Email aos técnicos associados a um evento: ao criar, ao alterar (formulário e arrasto) e ao
// remover — só quando a checkbox "Avisar os técnicos" está marcada (guardada no evento). Quem
// faz a ação não recebe; quem sai do evento recebe "removido", quem entra "criado"; um arrasto
// que não muda nada visível não avisa. As notificações vão pela fila (ShouldQueue).
class NotificacaoEventoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $paulo;

    private User $daniel;

    private Equipamento $ups;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-09-01 10:00:00');

        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->paulo = User::create(['nome' => 'Paulo Bento', 'email' => 'paulo@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->ups = $this->equipamentoDeTeste();
        $this->daniel = User::create(['nome' => 'Daniel Ribeiro', 'email' => 'daniel@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    /** @param list<int> $tecnicos */
    private function criar(array $tecnicos, bool $notificar = true, string $titulo = 'Reunião'): EventoAgenda
    {
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-04', '2026-09-04')
            ->set('formTitulo', $titulo)
            ->set('formEquipamentoId', $this->ups->id)
            ->set('formTecnicoIds', $tecnicos)
            ->set('formInicio', '2026-09-04T08:00')
            ->set('formFim', '2026-09-04T09:00')
            ->set('formNotificar', $notificar)
            ->call('criarEvento')
            ->assertHasNoErrors();

        return EventoAgenda::latest('id')->firstOrFail();
    }

    public function test_checkbox_ligada_por_defeito_ao_criar(): void
    {
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-04', '2026-09-04')
            ->assertSet('formNotificar', true);
    }

    public function test_criar_avisa_os_tecnicos_marcados_e_guarda_a_escolha(): void
    {
        $evento = $this->criar([$this->paulo->id, $this->daniel->id]);

        $this->assertTrue($evento->notificar_tecnicos);
        Notification::assertSentTo([$this->paulo, $this->daniel], EventoAgendaNotificacao::class, function (EventoAgendaNotificacao $n) {
            return $n->tipo === 'criado'
                && $n->evento['titulo'] === 'Reunião'
                && $n->autor === 'Admin'
                && str_contains($n->evento['tecnicos_nomes'], 'Paulo Bento');
        });
        // O admin não é técnico do evento — só recebem os técnicos associados.
        Notification::assertNotSentTo($this->admin, EventoAgendaNotificacao::class);
        // Vai pela fila (o Graph nunca entra no caminho do clique).
        $this->assertContains(ShouldQueue::class, class_implements(EventoAgendaNotificacao::class));
    }

    public function test_sem_checkbox_nao_envia_nada(): void
    {
        $evento = $this->criar([$this->paulo->id], notificar: false);

        $this->assertFalse($evento->notificar_tecnicos);
        Notification::assertNothingSent();
    }

    // O Outlook (motor do Word) ignora border-radius/padding do <a>: o botao e desenhado em
    // VML so para ele, e em HTML normal para os restantes clientes.
    // O convite que fica no calendario de cada tecnico vem do ICS, e o DESCRIPTION do ICS e
    // texto simples. O Outlook mostra o X-ALT-DESC (HTML) quando existe: e ai que os tecnicos
    // saem a negrito no evento do calendario pessoal.
    public function test_convite_leva_descricao_html_com_os_tecnicos_a_negrito(): void
    {
        $this->criar([$this->paulo->id, $this->daniel->id]);
        $n = \Illuminate\Support\Facades\Notification::sent($this->paulo, EventoAgendaNotificacao::class)->first();
        $ics = $n->toMail($this->paulo)->rawAttachments[0]['data'];

        $this->assertStringContainsString('X-ALT-DESC;FMTTYPE=text/html:', $ics);
        // As linhas do ICS sao dobradas aos 75 octetos: junta-as antes de procurar o negrito.
        $junto = str_replace(["\r\n ", "\n "], '', $ics);
        $this->assertStringContainsString('<strong>Daniel Ribeiro\, Paulo Bento</strong>', $junto);
        $this->assertStringContainsString('DESCRIPTION:', $ics);   // continua a haver a versao em texto
        // A propria X-ALT-DESC (e as suas continuacoes) tem de respeitar os 75 octetos do RFC.
        $linhas = explode("\r\n", $ics);
        $i = 0;
        while ($i < count($linhas) && ! str_starts_with($linhas[$i], 'X-ALT-DESC')) {
            $i++;
        }
        $this->assertLessThan(count($linhas), $i);
        for (; $i < count($linhas); $i++) {
            if ($i > 0 && ! str_starts_with($linhas[$i], 'X-ALT-DESC') && ! str_starts_with($linhas[$i], ' ')) {
                break;
            }
            $this->assertLessThanOrEqual(75, strlen($linhas[$i]), 'Linha do ICS acima dos 75 octetos: '.$linhas[$i]);
        }
    }

    public function test_botao_do_email_tem_versao_para_outlook(): void
    {
        $this->criar([$this->paulo->id]);
        $n = \Illuminate\Support\Facades\Notification::sent($this->paulo, EventoAgendaNotificacao::class)->first();
        $html = (string) $n->toMail($this->paulo)->render();

        $this->assertStringContainsString('<!--[if mso]>', $html);
        $this->assertStringContainsString('v:roundrect', $html);
        $this->assertStringContainsString('<!--[if !mso]>', $html);   // versao normal para os outros
        $this->assertSame(2, substr_count($html, 'Abrir a agenda'));  // uma em cada versao
        $this->assertStringContainsString('fillcolor="#16a34a"', $html);
    }

    public function test_quem_faz_a_acao_tambem_recebe(): void
    {
        // O Paulo cria um evento para si e para o Daniel: os DOIS são avisados (o autor
        // também quer o convite no Outlook) — pedido da equipa, set. 2026.
        Livewire::actingAs($this->paulo)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-04', '2026-09-04')
            ->set('formTitulo', 'Visita')
            ->set('formEquipamentoId', $this->ups->id)
            ->set('formTecnicoIds', [$this->paulo->id, $this->daniel->id])
            ->set('formInicio', '2026-09-04T08:00')
            ->set('formFim', '2026-09-04T09:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        Notification::assertSentTo([$this->daniel, $this->paulo], EventoAgendaNotificacao::class);
    }

    public function test_editar_avisa_alterado_a_quem_fica_criado_a_quem_entra_removido_a_quem_sai(): void
    {
        $evento = $this->criar([$this->paulo->id]);
        Notification::fake(); // limpa o "criado"

        // Muda a hora, mantém o Paulo, tira ninguém e junta o Daniel.
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->call('abrirEdicao')
            ->assertSet('formNotificar', true)
            ->set('formTecnicoIds', [$this->paulo->id, $this->daniel->id])
            ->set('formInicio', '2026-09-04T10:00')
            ->set('formFim', '2026-09-04T11:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        Notification::assertSentTo($this->paulo, EventoAgendaNotificacao::class, fn ($n) => $n->tipo === 'alterado'
            && $n->antes !== null
            && str_contains($n->antes['inicio'], '08:00')
            && str_contains($n->evento['inicio'], '10:00'));
        Notification::assertSentTo($this->daniel, EventoAgendaNotificacao::class, fn ($n) => $n->tipo === 'criado');

        Notification::fake();
        // Agora tira o Paulo: recebe "removido"; o Daniel fica e recebe "alterado" — a equipa do
        // evento mudou, e isso conta como alteração visível (o email mostra Técnicos: antes → depois).
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->call('abrirEdicao')
            ->set('formTecnicoIds', [$this->daniel->id])
            ->call('criarEvento')
            ->assertHasNoErrors();

        Notification::assertSentTo($this->paulo, EventoAgendaNotificacao::class, fn ($n) => $n->tipo === 'removido');
        Notification::assertSentTo($this->daniel, EventoAgendaNotificacao::class, fn ($n) => $n->tipo === 'alterado'
            && str_contains($n->antes['tecnicos_nomes'], 'Paulo')
            && ! str_contains($n->evento['tecnicos_nomes'], 'Paulo'));
    }

    public function test_arrastar_na_agenda_avisa_alterado(): void
    {
        $evento = $this->criar([$this->paulo->id]);
        Notification::fake();

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('reagendar', $evento->id, '2026-09-07 14:00:00', '2026-09-07 15:00:00', null);

        Notification::assertSentTo($this->paulo, EventoAgendaNotificacao::class, fn ($n) => $n->tipo === 'alterado'
            && str_contains($n->evento['inicio'], '2026-09-07T14:00'));
    }

    public function test_arrastar_sem_mudar_nada_nao_avisa(): void
    {
        $evento = $this->criar([$this->paulo->id]);
        Notification::fake();

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('reagendar', $evento->id, '2026-09-04 08:00:00', '2026-09-04 09:00:00', null);

        Notification::assertNothingSent();
    }

    public function test_remover_avisa_removido_com_os_dados_do_evento(): void
    {
        $evento = $this->criar([$this->paulo->id, $this->daniel->id], titulo: 'Formação');
        Notification::fake();

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->call('removerEvento');

        $this->assertSoftDeleted('eventos_agenda', ['id' => $evento->id]);
        Notification::assertSentTo([$this->paulo, $this->daniel], EventoAgendaNotificacao::class, fn ($n) => $n->tipo === 'removido'
            && $n->evento['titulo'] === 'Formação');
    }

    public function test_evento_sem_aviso_nao_avisa_ao_arrastar_nem_ao_remover(): void
    {
        $evento = $this->criar([$this->paulo->id], notificar: false);

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('reagendar', $evento->id, '2026-09-07 14:00:00', '2026-09-07 15:00:00', null)
            ->call('selecionar', $evento->id)
            ->call('removerEvento');

        Notification::assertNothingSent();
    }

    // ---- Convite iCalendar (Outlook): REQUEST/CANCEL, UID estável, SEQUENCE, ORGANIZER/ATTENDEE ----

    public function test_convite_ics_request_e_cancel_com_uid_estavel_e_sequence(): void
    {
        $evento = $this->criar([$this->paulo->id]);
        $this->assertSame(0, $evento->ical_sequence);

        // Criado: REQUEST com SEQUENCE:0, UID derivado do id, TZID, organizer = mailbox de serviço, attendee = o técnico.
        $criado = Notification::sent($this->paulo, EventoAgendaNotificacao::class)->first();
        $mail = $criado->toMail($this->paulo);
        $anexo = $mail->rawAttachments[0];
        $ics = $anexo['data'];
        $this->assertSame('convite.ics', $anexo['name']);
        $this->assertStringContainsString('method=REQUEST', $anexo['options']['mime']);
        $this->assertStringContainsString('METHOD:REQUEST', $ics);
        $this->assertStringContainsString("UID:agenda-{$evento->id}@infra.nexus-solutions.pt", $ics);
        $this->assertStringContainsString('SEQUENCE:0', $ics);
        $this->assertStringContainsString('DTSTART;TZID=Europe/Lisbon:20260904T080000', $ics);
        $this->assertStringContainsString('ORGANIZER;CN=Nexus Infra:mailto:', $ics);
        $this->assertStringContainsString('ATTENDEE;CN=Paulo Bento;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:paulo@nexus.pt', $ics);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);

        // Alterado: SEQUENCE incrementado no evento e no convite.
        Notification::fake();
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $evento->id)->call('abrirEdicao')
            ->set('formInicio', '2026-09-04T10:00')->set('formFim', '2026-09-04T11:00')
            ->call('criarEvento')->assertHasNoErrors();
        $this->assertSame(1, $evento->fresh()->ical_sequence);
        $alterado = Notification::sent($this->paulo, EventoAgendaNotificacao::class)->first();
        $this->assertStringContainsString('SEQUENCE:1', $alterado->toMail($this->paulo)->rawAttachments[0]['data']);
        $this->assertStringContainsString("UID:agenda-{$evento->id}@", $alterado->toMail($this->paulo)->rawAttachments[0]['data']);

        // Removido: CANCEL, mesmo UID, SEQUENCE seguinte (2), STATUS:CANCELLED.
        Notification::fake();
        Livewire::actingAs($this->admin)->test(Calendario::class)->call('selecionar', $evento->id)->call('removerEvento');
        $removido = Notification::sent($this->paulo, EventoAgendaNotificacao::class)->first();
        $anexo = $removido->toMail($this->paulo)->rawAttachments[0];
        $this->assertStringContainsString('method=CANCEL', $anexo['options']['mime']);
        $this->assertStringContainsString('METHOD:CANCEL', $anexo['data']);
        $this->assertStringContainsString("UID:agenda-{$evento->id}@infra.nexus-solutions.pt", $anexo['data']);
        $this->assertStringContainsString('SEQUENCE:2', $anexo['data']);
        $this->assertStringContainsString('STATUS:CANCELLED', $anexo['data']);
    }

    public function test_arrasto_sem_mudanca_nao_gasta_sequence(): void
    {
        $evento = $this->criar([$this->paulo->id]);
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('reagendar', $evento->id, '2026-09-04 08:00:00', '2026-09-04 09:00:00', null);

        $this->assertSame(0, $evento->fresh()->ical_sequence);
    }

    public function test_email_tem_assunto_e_o_que_mudou(): void
    {
        $antes = ['id' => 1, 'titulo' => 'Reunião', 'inicio' => '2026-09-04T08:00:00+01:00', 'fim' => '2026-09-04T09:00:00+01:00',
            'segmentos' => [], 'tecnico_ids' => [1], 'tecnicos_nomes' => 'Paulo Bento', 'cliente' => null, 'equipamento' => null, 'contrato' => null, 'notificar' => true];
        $depois = ['inicio' => '2026-09-04T10:00:00+01:00', 'fim' => '2026-09-04T11:00:00+01:00', 'cliente' => 'ACME'] + $antes;

        $mail = (new EventoAgendaNotificacao('alterado', $depois, $antes, 'Admin'))->toMail($this->paulo);
        $html = (string) $mail->render();

        $this->assertSame('Evento alterado: Reunião — 04/09/2026 10:00–11:00', $mail->subject);
        $this->assertStringContainsString('O que mudou', $html);
        $this->assertStringContainsString('04/09/2026 08:00–09:00', $html); // antes
        $this->assertStringContainsString('ACME', $html);
        $this->assertStringContainsString('por <strong', $html);

        $removido = (new EventoAgendaNotificacao('removido', $antes, null, 'Admin'))->toMail($this->paulo);
        $this->assertStringStartsWith('Evento removido: Reunião', $removido->subject);
        $this->assertStringNotContainsString('Abrir a agenda', (string) $removido->render());
    }
}
