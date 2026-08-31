<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Jobs\SincronizarEventoGraph;
use App\Models\Cliente;
use App\Models\EventoAgenda;
use App\Models\Local;
use App\Models\User;
use App\Services\Agenda\CalendarioGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Calendário partilhado da agenda no M365 (Graph) — a 2.ª via para o Outlook. Graph SIMULADO
// (Http::fake): token, calendário encontrado/criado, POST na criação (id guardado), PATCH na
// alteração, DELETE na remoção, 404 no PATCH → recria, 403 → desiste sem retries, observer a
// despachar após commit (e só com a via ligada), partilha idempotente com a equipa, comando.
class CalendarioGraphTest extends TestCase
{
    use RefreshDatabase;

    private const SENDER = 'Suporte@nxs.pt';

    private const CAL = 'AAMkCal001';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 10:00:00');
        Cache::flush();
        config([
            'services.microsoft_graph.tenant_id' => 'tenant', 'services.microsoft_graph.client_id' => 'cid',
            'services.microsoft_graph.client_secret' => 'sec', 'services.microsoft_graph.sender' => self::SENDER,
            'services.microsoft_graph.calendario_ativo' => true, 'services.microsoft_graph.calendario_agenda' => 'Agenda Nexus Infra',
        ]);
    }

    // Token com a permissão pedida (JWT de brincar: header.payload.assinatura).
    private function token(array $roles = ['Mail.Send', 'Calendars.ReadWrite']): string
    {
        $b64 = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $b64(['alg' => 'none']).'.'.$b64(['roles' => $roles]).'.x';
    }

    private function fakeGraph(array $extra = [], array $roles = ['Mail.Send', 'Calendars.ReadWrite']): void
    {
        // Os padrões avaliam-se por ordem: os específicos ($extra) têm de vir ANTES dos genéricos —
        // e com chaves iguais o `+` mantém o de $extra (o array_merge deixava o genérico ganhar).
        Http::fake($extra + [
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->token($roles), 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/calendars?*' => Http::response(['value' => [['id' => self::CAL, 'name' => 'Agenda Nexus Infra']]]),
            'graph.microsoft.com/v1.0/users/*/calendars/'.self::CAL.'/events' => Http::response(['id' => 'EV-NOVO'], 201),
            'graph.microsoft.com/v1.0/users/*/events/*' => Http::response(['id' => 'EV-NOVO']),
        ]);
    }

    private function evento(string $titulo = 'Preventiva UPS'): EventoAgenda
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede', 'morada' => 'Rua A, Porto']);
        $paulo = User::create(['nome' => 'Paulo Bento', 'email' => 'paulo@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        return EventoAgenda::withoutEvents(fn () => EventoAgenda::create(['tipo' => 'outro', 'titulo' => $titulo, 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-09-10 09:00'), 'fim' => Carbon::parse('2026-09-10 11:00'),
            'tecnico_id' => $paulo->id, 'tecnico_nome' => $paulo->nome, 'cliente_id' => $cliente->id, 'local_id' => $local->id]));
    }

    // ---- Espelho ----

    public function test_criar_faz_post_com_tzid_e_guarda_o_id(): void
    {
        $this->fakeGraph();
        $e = $this->evento();

        $id = app(CalendarioGraph::class)->espelhar($e);

        $this->assertSame('EV-NOVO', $id);
        $this->assertSame('EV-NOVO', $e->fresh()->graph_event_id);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/calendars/'.self::CAL.'/events')
            && $r['subject'] === 'Preventiva UPS · ACME'
            && $r['start']['timeZone'] === 'Europe/Lisbon'
            && $r['start']['dateTime'] === '2026-09-10T09:00:00'
            && $r['location']['displayName'] === 'Rua A, Porto'
            && str_contains($r['body']['content'], 'Paulo Bento')
            && $r['transactionId'] === 'agenda-'.$e->id.'@infra.nexus-solutions.pt');
    }

    public function test_alterar_faz_patch_no_mesmo_id(): void
    {
        $this->fakeGraph();
        $e = $this->evento();
        $e->forceFill(['graph_event_id' => 'EV-EXISTENTE', 'titulo' => 'Corretiva'])->saveQuietly();

        app(CalendarioGraph::class)->espelhar($e->fresh());

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/events/EV-EXISTENTE') && $r['subject'] === 'Corretiva · ACME');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/events'));
    }

    public function test_patch_404_recria_o_espelho(): void
    {
        $this->fakeGraph(['graph.microsoft.com/v1.0/users/*/events/EV-APAGADO' => Http::response(['error' => ['message' => 'not found']], 404)]);
        $e = $this->evento();
        $e->forceFill(['graph_event_id' => 'EV-APAGADO'])->saveQuietly();

        app(CalendarioGraph::class)->espelhar($e->fresh());

        $this->assertSame('EV-NOVO', $e->fresh()->graph_event_id);
    }

    public function test_remover_faz_delete_e_tolera_404(): void
    {
        $this->fakeGraph(['graph.microsoft.com/v1.0/users/*/events/EV-X' => Http::response('', 204),
            'graph.microsoft.com/v1.0/users/*/events/EV-JA-NAO' => Http::response('', 404)]);

        app(CalendarioGraph::class)->remover('EV-X');
        app(CalendarioGraph::class)->remover('EV-JA-NAO'); // não rebenta

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/EV-X'));
    }

    public function test_calendario_e_criado_quando_nao_existe_e_fica_em_cache(): void
    {
        $this->fakeGraph([
            'graph.microsoft.com/v1.0/users/*/calendars?*' => Http::response(['value' => [['id' => 'OUTRO', 'name' => 'Calendário']]]),
            'graph.microsoft.com/v1.0/users/*/calendars' => Http::response(['id' => 'CAL-CRIADO', 'name' => 'Agenda Nexus Infra'], 201),
        ]);

        $this->assertSame('CAL-CRIADO', app(CalendarioGraph::class)->calendarioId());
        $this->assertSame('CAL-CRIADO', app(CalendarioGraph::class)->calendarioId()); // 2.ª vez: cache
        Http::assertSentCount(3); // token + listar + criar (nada na 2.ª chamada)
    }

    // ---- Job ----

    public function test_job_com_403_desiste_sem_rebentar(): void
    {
        $this->fakeGraph(['graph.microsoft.com/v1.0/users/*/calendars?*' => Http::response(['error' => ['message' => 'Access is denied']], 403)]);
        $e = $this->evento();

        (new SincronizarEventoGraph('espelhar', $e->id))->handle(app(CalendarioGraph::class));

        $this->assertNull($e->fresh()->graph_event_id); // não espelhou, não rebentou
    }

    public function test_job_espelhar_de_evento_apagado_remove_o_espelho(): void
    {
        $this->fakeGraph(['graph.microsoft.com/v1.0/users/*/events/EV-X' => Http::response('', 204)]);
        $e = $this->evento();
        $e->forceFill(['graph_event_id' => 'EV-X'])->saveQuietly();
        EventoAgenda::withoutEvents(fn () => $e->delete());

        (new SincronizarEventoGraph('espelhar', $e->id))->handle(app(CalendarioGraph::class));

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/EV-X'));
        $this->assertNull(EventoAgenda::withTrashed()->find($e->id)->graph_event_id);
    }

    public function test_via_desligada_nao_chama_o_graph(): void
    {
        config(['services.microsoft_graph.calendario_ativo' => false]);
        Http::fake();
        $e = $this->evento();

        (new SincronizarEventoGraph('espelhar', $e->id))->handle(app(CalendarioGraph::class));

        Http::assertNothingSent();
    }

    // ---- Observer ----

    public function test_observer_despacha_espelhar_ao_criar_e_alterar_e_remover_ao_apagar(): void
    {
        Queue::fake();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);

        $e = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-09-10 09:00'), 'fim' => Carbon::parse('2026-09-10 10:00'), 'cliente_id' => $cliente->id]);
        Queue::assertPushed(SincronizarEventoGraph::class, fn ($j) => $j->acao === 'espelhar' && $j->eventoId === $e->id);

        $e->update(['titulo' => 'Reunião 2']);
        Queue::assertPushed(SincronizarEventoGraph::class, 2);

        $e->forceFill(['graph_event_id' => 'EV-X'])->saveQuietly();
        $e->delete();
        Queue::assertPushed(SincronizarEventoGraph::class, fn ($j) => $j->acao === 'remover' && $j->graphEventId === 'EV-X');
    }

    public function test_observer_nao_despacha_com_a_via_desligada(): void
    {
        config(['services.microsoft_graph.calendario_ativo' => false]);
        Queue::fake();

        EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-09-10 09:00'), 'fim' => Carbon::parse('2026-09-10 10:00')]);

        Queue::assertNothingPushed();
    }

    // ---- Partilha ----

    public function test_partilha_com_a_equipa_e_salta_quem_ja_tem(): void
    {
        User::create(['nome' => 'Chefe', 'email' => 'chefe@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        User::create(['nome' => 'Paulo Bento', 'email' => 'paulo@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        User::create(['nome' => 'Inativo', 'email' => 'inativo@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => false]);
        User::create(['nome' => 'Cliente', 'email' => 'cli@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Cliente, 'ativo' => true]);
        $this->fakeGraph([
            'graph.microsoft.com/v1.0/users/*/calendars/'.self::CAL.'/calendarPermissions' => Http::sequence()
                ->push(['value' => [['emailAddress' => ['address' => 'paulo@nxs.pt'], 'role' => 'read']]])
                ->push(['id' => 'perm-1'], 201),
        ]);

        $r = app(CalendarioGraph::class)->partilharComEquipa();

        $this->assertSame(['chefe@nxs.pt'], $r['partilhado']);
        $this->assertSame(['paulo@nxs.pt'], $r['ja_tinha']);
        Http::assertSent(fn ($req) => $req->method() === 'POST' && str_ends_with($req->url(), '/calendarPermissions') && $req['emailAddress']['address'] === 'chefe@nxs.pt' && $req['role'] === 'read');
        Http::assertNotSent(fn ($req) => $req->method() === 'POST' && str_ends_with($req->url(), '/calendarPermissions') && in_array($req['emailAddress']['address'], ['inativo@nxs.pt', 'cli@x.pt'], true));
    }

    // ---- Comando ----

    public function test_comando_verificar_falha_sem_permissao_e_passa_com_ela(): void
    {
        // Os stubs do Http::fake acumulam-se entre chamadas — por isso UM fake, com o token em
        // sequência: 1.º só Mail.Send (antes do consentimento), 2.º com Calendars.ReadWrite.
        Http::fake([
            'login.microsoftonline.com/*' => Http::sequence()
                ->push(['access_token' => $this->token(['Mail.Send']), 'expires_in' => 3600])
                ->push(['access_token' => $this->token(), 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/calendars?*' => Http::response(['value' => [['id' => self::CAL, 'name' => 'Agenda Nexus Infra']]]),
        ]);

        $this->artisan('agenda:graph', ['--verificar' => true])
            ->expectsOutputToContain('Calendars.ReadWrite')
            ->assertFailed();

        Cache::flush(); // esquece o token sem permissão → o comando vai buscar o 2.º da sequência
        $this->artisan('agenda:graph', ['--verificar' => true])
            ->expectsOutputToContain('OK')
            ->assertSuccessful();
    }

    public function test_comando_carga_inicial_espelha_a_janela(): void
    {
        $this->fakeGraph();
        $e = $this->evento();
        EventoAgenda::withoutEvents(fn () => EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Fora da janela', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2027-03-01 09:00'), 'fim' => Carbon::parse('2027-03-01 10:00')]));

        $this->artisan('agenda:graph')->expectsOutputToContain('1 criados, 0 atualizados, 0 erros')->assertSuccessful();

        $this->assertSame('EV-NOVO', $e->fresh()->graph_event_id);
    }
}
