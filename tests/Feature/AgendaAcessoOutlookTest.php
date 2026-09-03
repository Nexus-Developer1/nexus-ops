<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\User;
use App\Services\Agenda\CalendarioGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

// Botão "Acesso no Outlook" na página da Agenda: (re)envia ao PRÓPRIO o convite NATIVO de
// partilha do calendário ("You're invited to share this calendar") — um link para o feed ICS
// não dá acesso. Como o Outlook só envia o convite quando a permissão é criada, reenviar
// obriga a apagar a permissão existente e a criá-la de novo.
class AgendaAcessoOutlookTest extends TestCase
{
    use RefreshDatabase;

    private const CAL = 'CAL-1';

    private const PERM = 'PERM-9';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.microsoft_graph.calendario_ativo' => true,
            'services.microsoft_graph.sender' => 'suporte@nxs.pt',
            'services.microsoft_graph.calendario_agenda' => 'Agenda Nexus Infra',
            'services.microsoft_graph.tenant' => 't', 'services.microsoft_graph.client_id' => 'c',
            'services.microsoft_graph.client_secret' => 's',
        ]);
    }

    private function tecnico(string $email = 'rui@nxs.pt'): User
    {
        return User::create(['nome' => 'Rui Moreira', 'email' => $email, 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    /** @param array<string, mixed> $extra */
    private function fakeGraph(array $extra = []): void
    {
        $token = 'eyJhbGciOiJub25lIn0.'.rtrim(strtr(base64_encode(json_encode([
            'roles' => ['Mail.Send', 'Calendars.ReadWrite'], 'exp' => time() + 3600,
        ])), '+/', '-_'), '=').'.';

        Http::fake($extra + [
            'login.microsoftonline.com/*' => Http::response(['access_token' => $token, 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/calendars?*' => Http::response(['value' => [['id' => self::CAL, 'name' => 'Agenda Nexus Infra']]]),
            'graph.microsoft.com/*/calendarPermissions' => Http::response(['value' => []], 201),
            'graph.microsoft.com/*' => Http::response([]),
        ]);
    }

    public function test_reenviar_apaga_a_permissao_e_cria_de_novo_para_o_proprio(): void
    {
        $rui = $this->tecnico();
        // O calendário já está partilhado com ele: sem apagar, o Outlook não manda convite nenhum.
        $this->fakeGraph([
            'graph.microsoft.com/*/calendarPermissions' => Http::sequence()
                ->push(['value' => [['id' => self::PERM, 'emailAddress' => ['address' => 'RUI@nxs.pt']]]])
                ->push(['id' => 'NOVA'], 201),
        ]);

        Livewire::actingAs($rui)->test(Calendario::class)
            ->assertSee('Acesso no Outlook')
            ->call('enviarAcessoOutlook')
            ->assertSee('Convite de partilha enviado para rui@nxs.pt');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/calendarPermissions/'.self::PERM));
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/calendarPermissions')
            && $r['emailAddress']['address'] === 'rui@nxs.pt'   // só o próprio
            && $r['role'] === 'read');
        $this->assertDatabaseHas('auditoria', ['acao' => 'agenda.acesso_enviado']);
    }

    public function test_sem_permissao_previa_apenas_cria(): void
    {
        $this->fakeGraph();

        Livewire::actingAs($this->tecnico())->test(Calendario::class)->call('enviarAcessoOutlook');

        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calendarPermissions'));
    }

    public function test_recusa_do_outlook_e_mostrada_sem_rebentar(): void
    {
        $this->fakeGraph([
            'graph.microsoft.com/*/calendarPermissions' => Http::sequence()
                ->push(['value' => []])
                ->push(['error' => ['message' => 'The user is external.']], 400),
        ]);

        Livewire::actingAs($this->tecnico('rui@gmail.com'))->test(Calendario::class)
            ->call('enviarAcessoOutlook')
            ->assertSee('O Outlook recusou o convite');
    }

    public function test_integracao_desligada_avisa(): void
    {
        config(['services.microsoft_graph.calendario_ativo' => false]);
        Http::fake();

        Livewire::actingAs($this->tecnico())->test(Calendario::class)
            ->call('enviarAcessoOutlook')
            ->assertSee('ligação ao calendário do Outlook está desligada');

        Http::assertNothingSent();
    }

    public function test_cliques_repetidos_sao_travados(): void
    {
        $rui = $this->tecnico();
        $this->fakeGraph();
        $c = Livewire::actingAs($rui)->test(Calendario::class);

        for ($i = 0; $i < 3; $i++) {
            $c->call('enviarAcessoOutlook');
        }
        $c->call('enviarAcessoOutlook')->assertSee('Já enviámos o acesso há pouco');

        RateLimiter::clear('acesso-agenda:'.$rui->id);
    }

    public function test_a_mailbox_dona_nao_se_convida_a_si_mesma(): void
    {
        $this->fakeGraph();
        $dono = User::create(['nome' => 'Admin Nexus', 'email' => 'suporte@nxs.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        $r = app(CalendarioGraph::class)->reenviarConvitePartilha($dono);

        $this->assertSame('dono', $r['estado']);
        Http::assertNotSent(fn ($req) => $req->method() === 'POST' && str_ends_with($req->url(), '/calendarPermissions'));
    }
}
