<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Notifications\EventoAgendaNotificacao;
use App\Services\Agenda\GeradorIcs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Notas livres do evento (morada, contactos, indicações de acesso…): gravam-se, reabrem-se na
// edição, aparecem no detalhe, no email/convite aos técnicos (e mudá-las conta como alteração),
// no feed iCal e num evento já convertido continuam editáveis pela agenda.
class AgendaNotasEventoTest extends TestCase
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

    // O iCalendar dobra as linhas a 75 octetos (CRLF + espaço): junta-as antes de procurar texto.
    private static function desdobrar(string $ics): string
    {
        // Quebra CRLF (RFC 5545) ou LF: o padrão é escrito com escapes para não depender das
        // quebras de linha do checkout (com LF literal no ficheiro só casava em checkouts CRLF).
        return preg_replace("/\r?\n[ \t]/", '', $ics);
    }

    private const NOTAS = "Rua das Flores 12, Évora\nContacto: Sr. Silva 912 345 678\nPortão das traseiras";

    public function test_grava_reabre_e_mostra_no_detalhe(): void
    {
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-08', '2026-09-08')
            ->set('formTitulo', 'Serviço')
            ->set('formInicio', '2026-09-08T09:00')->set('formFim', '2026-09-08T11:00')
            ->set('formNotas', '  '.self::NOTAS.'  ')
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();

        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();
        $this->assertSame(self::NOTAS, $e->notas); // aparado

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->assertSee('912 345 678')
            ->call('abrirEdicao')
            ->assertSet('formNotas', self::NOTAS)
            ->set('formNotas', '')
            ->call('criarEvento')->assertHasNoErrors();
        $this->assertNull($e->fresh()->notas); // vazio grava null
    }

    public function test_vao_no_email_no_convite_e_mudar_as_notas_avisa(): void
    {
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-08', '2026-09-08')
            ->set('formTitulo', 'Serviço')
            ->set('formTecnicoIds', [$this->paulo->id])
            ->set('formInicio', '2026-09-08T09:00')->set('formFim', '2026-09-08T11:00')
            ->set('formNotas', self::NOTAS)
            ->call('criarEvento')->assertHasNoErrors();
        $e = EventoAgenda::where('titulo', 'Serviço')->firstOrFail();

        $n = Notification::sent($this->paulo, EventoAgendaNotificacao::class)->first();
        $mail = $n->toMail($this->paulo);
        $this->assertStringContainsString('912 345 678', (string) $mail->render());
        $this->assertStringContainsString('Notas:', self::desdobrar($mail->rawAttachments[0]['data'])); // convite .ics

        // Só as notas mudam → conta como alteração (é informação que o técnico usa no local).
        Notification::fake();
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)->call('abrirEdicao')
            ->set('formNotas', 'Levar baterias novas')
            ->call('criarEvento')->assertHasNoErrors();
        Notification::assertSentTo($this->paulo, EventoAgendaNotificacao::class, fn ($n) => $n->tipo === 'alterado' && $n->evento['notas'] === 'Levar baterias novas');

        // Feed iCal de quem não é convidado leva as notas.
        $coord = User::create(['nome' => 'Coord', 'email' => 'c@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->assertStringContainsString('Levar baterias novas', self::desdobrar(app(GeradorIcs::class)->feed($coord)));
    }

    public function test_num_evento_convertido_as_notas_continuam_editaveis(): void
    {
        $ups = $this->equipamentoDeTeste();
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-08', '2026-09-08')
            ->set('formTitulo', 'Preventiva')
            ->set('formInicio', '2026-09-08T09:00')->set('formFim', '2026-09-08T11:00')
            ->call('selecionarEquipamento', $ups->id)
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();
        $e = EventoAgenda::where('titulo', 'Preventiva')->firstOrFail();
        $this->assertNotNull($e->intervencao_id);

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)->call('abrirEdicao')
            ->assertSet('editandoConvertido', true)
            ->set('formNotas', 'Chave no segurança')
            ->call('criarEvento')->assertHasNoErrors();
        $this->assertSame('Chave no segurança', $e->fresh()->notas);
    }

    public function test_limite_de_tamanho(): void
    {
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-08', '2026-09-08')
            ->set('formTitulo', 'Serviço')
            ->set('formInicio', '2026-09-08T09:00')->set('formFim', '2026-09-08T11:00')
            ->set('formNotas', str_repeat('x', 5001))
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasErrors(['formNotas' => 'max']);
    }
}
