<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\User;
use App\Notifications\AcessoAgendaOutlook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

// Botão "Acesso no Outlook" na página da Agenda: manda para o PRÓPRIO o endereço de subscrição
// do seu feed. O URL é o segredo (quem o tem vê a agenda dessa pessoa), por isso nunca se
// pergunta o destinatário — vai sempre para o email da conta autenticada.
class AgendaAcessoOutlookTest extends TestCase
{
    use RefreshDatabase;

    private function tecnico(string $email = 'rui@nexus.pt'): User
    {
        return User::create(['nome' => 'Rui Moreira', 'email' => $email, 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_envia_so_para_o_proprio_e_cria_o_token_se_faltar(): void
    {
        $rui = $this->tecnico();
        $outro = $this->tecnico('outro@nexus.pt');
        $this->assertNull($rui->agenda_feed_token);

        Livewire::actingAs($rui)->test(Calendario::class)
            ->assertSee('Acesso no Outlook')
            ->call('enviarAcessoOutlook')
            ->assertHasNoErrors();

        $rui->refresh();
        $this->assertNotNull($rui->agenda_feed_token);       // gerado na hora
        $this->assertSame(48, strlen($rui->agenda_feed_token));

        Notification::assertSentTo($rui, AcessoAgendaOutlook::class, function (AcessoAgendaOutlook $n) use ($rui) {
            return $n->url === route('agenda.feed', $rui->agenda_feed_token);
        });
        Notification::assertNotSentTo($outro, AcessoAgendaOutlook::class);   // só o próprio
        $this->assertDatabaseHas('auditoria', ['acao' => 'agenda.acesso_enviado']);
    }

    public function test_reenviar_mantem_o_token_que_ja_existe(): void
    {
        $rui = $this->tecnico();
        $rui->forceFill(['agenda_feed_token' => str_repeat('a', 48)])->save();

        Livewire::actingAs($rui)->test(Calendario::class)->call('enviarAcessoOutlook');

        $this->assertSame(str_repeat('a', 48), $rui->fresh()->agenda_feed_token); // não regenera
        Notification::assertSentTo($rui, AcessoAgendaOutlook::class);
    }

    public function test_cliques_repetidos_sao_travados(): void
    {
        $rui = $this->tecnico();
        $c = Livewire::actingAs($rui)->test(Calendario::class);

        for ($i = 0; $i < 3; $i++) {
            $c->call('enviarAcessoOutlook');
        }
        Notification::assertSentToTimes($rui, AcessoAgendaOutlook::class, 3);

        $c->call('enviarAcessoOutlook')->assertSee('Já enviámos o acesso há pouco');
        Notification::assertSentToTimes($rui, AcessoAgendaOutlook::class, 3); // o 4.º não sai

        RateLimiter::clear('acesso-agenda:'.$rui->id);
        $c->call('enviarAcessoOutlook');
        Notification::assertSentToTimes($rui, AcessoAgendaOutlook::class, 4);
    }

    public function test_email_leva_o_url_de_subscricao_e_vai_a_fila(): void
    {
        $rui = $this->tecnico();
        $url = route('agenda.feed', str_repeat('b', 48));
        $mail = (new AcessoAgendaOutlook($url))->toMail($rui);
        $html = (string) $mail->render();

        $this->assertStringContainsString($url, $html);
        $this->assertStringContainsString('Rui Moreira', $html);
        $this->assertStringContainsString('Subscrever a partir da Web', $html);
        $this->assertStringContainsString('v:roundrect', $html); // botão à prova de Outlook
        $this->assertContains(\Illuminate\Contracts\Queue\ShouldQueue::class, class_implements(AcessoAgendaOutlook::class));
    }
}
