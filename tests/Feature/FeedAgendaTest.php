<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Feeds;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\EventoAgenda;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Feed ICS da agenda para o Outlook (Parte B) e gestão dos URLs de subscrição (Parte C):
// token validado na BD (revogar invalida já), janela [-30, +90], apagados recentes como
// CANCELLED, exclusão dos eventos em que o subscritor é convidado, campos filtrados, cabeçalhos
// de refresh, ETag/304, Gate só admin, gerar/regenerar/revogar com auditoria.
class FeedAgendaTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKL';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 10:00:00');
    }

    private function admin(?string $token = self::TOKEN): User
    {
        // O token NÃO é mass-assignable de propósito (é o segredo do URL) — só por forceFill.
        $u = User::create(['nome' => 'Chefe', 'email' => 'chefe@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $u->forceFill(['agenda_feed_token' => $token])->save();

        return $u;
    }

    private function tecnico(string $nome = 'Paulo Bento', string $email = 'paulo@nexus.pt'): User
    {
        return User::create(['nome' => $nome, 'email' => $email, 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private function evento(string $titulo, string $inicio, ?User $tecnico = null, array $extra = []): EventoAgenda
    {
        return EventoAgenda::create(['tipo' => 'outro', 'titulo' => $titulo, 'estado' => 'planeado',
            'inicio' => Carbon::parse($inicio), 'fim' => Carbon::parse($inicio)->addHour(),
            'tecnico_id' => $tecnico?->id, 'tecnico_nome' => $tecnico?->nome] + $extra);
    }

    private function feed(string $token = self::TOKEN)
    {
        return $this->get(route('agenda.feed', ['token' => $token]));
    }

    // ---- Token ----

    public function test_token_desconhecido_curto_ou_de_conta_inativa_da_404(): void
    {
        $this->admin();
        $this->feed(str_repeat('z', 48))->assertNotFound();
        $this->get('/agenda/feed/curto.ics')->assertNotFound();

        User::where('email', 'chefe@nexus.pt')->update(['ativo' => false]);
        $this->feed()->assertNotFound();
    }

    public function test_revogar_invalida_o_url_de_imediato(): void
    {
        $chefe = $this->admin();
        $this->feed()->assertOk();

        Livewire::actingAs($chefe)->test(Feeds::class)->call('revogar', $chefe->id);

        $this->feed()->assertNotFound();
    }

    // ---- Conteúdo ----

    public function test_cabecalhos_uid_estavel_tzid_e_campos_filtrados(): void
    {
        $this->admin();
        $paulo = $this->tecnico();
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'geral@acme.pt', 'telefone' => '919999999', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede', 'morada' => 'Rua A, Porto', 'notas_acesso' => 'código do portão 1234']);
        $e = $this->evento('Preventiva UPS', '2026-09-10 09:00', $paulo, ['cliente_id' => $cliente->id, 'local_id' => $local->id]);

        $ics = $this->feed()->assertOk()->getContent();

        $this->assertStringContainsString('X-WR-CALNAME:Nexus Infra', $ics);
        $this->assertStringContainsString('REFRESH-INTERVAL;VALUE=DURATION:PT1H', $ics);
        $this->assertStringContainsString('X-PUBLISHED-TTL:PT1H', $ics);
        $this->assertStringContainsString("UID:agenda-{$e->id}@infra.nexus-solutions.pt", $ics);
        $this->assertStringContainsString('DTSTART;TZID=Europe/Lisbon:20260910T090000', $ics);
        $this->assertStringContainsString('SUMMARY:Preventiva UPS · ACME · Paulo Bento', $ics); // técnicos no título
        $this->assertStringContainsString('LOCATION:Rua A', $ics);
        $this->assertStringContainsString('Paulo Bento', $ics);
        // Só o essencial: sem contactos nem notas internas.
        $this->assertStringNotContainsString('919999999', $ics);
        $this->assertStringNotContainsString('geral@acme.pt', $ics);
        $this->assertStringNotContainsString('portão', $ics);
    }

    public function test_janela_temporal_menos_30_mais_90_dias(): void
    {
        $this->admin();
        $this->evento('Dentro passado', '2026-08-05 09:00');   // -27 dias
        $this->evento('Fora passado', '2026-07-25 09:00');     // -38 dias
        $this->evento('Dentro futuro', '2026-11-25 09:00');    // +85 dias
        $this->evento('Fora futuro', '2026-12-10 09:00');      // +100 dias

        $ics = $this->feed()->getContent();

        $this->assertStringContainsString('Dentro passado', $ics);
        $this->assertStringContainsString('Dentro futuro', $ics);
        $this->assertStringNotContainsString('Fora passado', $ics);
        $this->assertStringNotContainsString('Fora futuro', $ics);
    }

    public function test_apagados_saem_como_cancelled_durante_30_dias_e_depois_desaparecem(): void
    {
        $this->admin();
        $recente = $this->evento('Apagado há pouco', '2026-09-10 09:00');
        $antigo = $this->evento('Apagado há muito', '2026-09-11 09:00');
        $recente->delete();
        $antigo->delete();
        EventoAgenda::withTrashed()->whereKey($antigo->id)->update(['deleted_at' => now()->subDays(40)]);

        $ics = $this->feed()->getContent();

        $this->assertStringContainsString('Apagado há pouco', $ics);
        $this->assertMatchesRegularExpression('/SUMMARY:Apagado há pouco.*?STATUS:CANCELLED/s', $ics);
        $this->assertStringNotContainsString('Apagado há muito', $ics);
    }

    public function test_feed_exclui_os_eventos_em_que_o_subscritor_e_convidado(): void
    {
        // O Paulo recebe convites; o feed dele mostra a agenda dos OUTROS, não os seus.
        $paulo = $this->tecnico();
        $paulo->forceFill(['agenda_feed_token' => self::TOKEN])->save();
        $daniel = $this->tecnico('Daniel Ribeiro', 'daniel@nexus.pt');

        $this->evento('Do Paulo (principal)', '2026-09-10 09:00', $paulo);
        $adicional = $this->evento('Do Daniel com Paulo adicional', '2026-09-11 09:00', $daniel);
        $adicional->tecnicosAdicionais()->sync([$paulo->id]);
        $this->evento('Só do Daniel', '2026-09-12 09:00', $daniel);
        $this->evento('Sem técnico', '2026-09-13 09:00');

        $ics = $this->feed()->getContent();

        $this->assertStringNotContainsString('Do Paulo (principal)', $ics);
        $this->assertStringNotContainsString('Do Daniel com Paulo adicional', $ics);
        $this->assertStringContainsString('Só do Daniel', $ics);
        $this->assertStringContainsString('Sem técnico', $ics);
    }

    public function test_etag_e_304_no_pedido_condicional(): void
    {
        $this->admin();
        $this->evento('Reunião', '2026-09-10 09:00');

        $primeira = $this->feed()->assertOk();
        $etag = $primeira->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertNotEmpty($primeira->headers->get('Last-Modified'));

        $this->withHeader('If-None-Match', $etag)->get(route('agenda.feed', ['token' => self::TOKEN]))->assertStatus(304);

        // Mudou algo → ETag novo, 200 outra vez.
        Carbon::setTestNow('2026-09-01 10:05:00');
        $this->evento('Nova', '2026-09-12 09:00');
        $this->withHeader('If-None-Match', $etag)->get(route('agenda.feed', ['token' => self::TOKEN]))->assertOk();
    }

    // ---- Página de gestão (Parte C) ----

    public function test_pagina_so_admin_via_gate(): void
    {
        $chefe = $this->admin(null);
        $paulo = $this->tecnico();

        $this->actingAs($chefe)->get(route('agenda.feeds'))->assertOk()->assertSee('Feeds da agenda');
        $this->actingAs($paulo)->get(route('agenda.feeds'))->assertForbidden();
        Livewire::actingAs($paulo)->test(Feeds::class)->assertForbidden();
    }

    public function test_gerar_regenerar_e_revogar_com_auditoria(): void
    {
        $chefe = $this->admin(null);
        $paulo = $this->tecnico();

        $c = Livewire::actingAs($chefe)->test(Feeds::class)
            ->assertSee('Sem feed')
            ->call('gerar', $paulo->id);
        $token1 = $paulo->fresh()->agenda_feed_token;
        $this->assertSame(48, strlen($token1));
        $c->assertSee(route('agenda.feed', ['token' => $token1]));
        $this->assertDatabaseHas('auditoria', ['acao' => 'agenda.feed_gerado', 'entidade_id' => $paulo->id]);

        $c->call('gerar', $paulo->id);
        $token2 = $paulo->fresh()->agenda_feed_token;
        $this->assertNotSame($token1, $token2);
        $this->feed($token1)->assertNotFound(); // o antigo morreu na hora
        $this->feed($token2)->assertOk();
        $this->assertDatabaseHas('auditoria', ['acao' => 'agenda.feed_regenerado']);

        $c->call('revogar', $paulo->id);
        $this->assertNull($paulo->fresh()->agenda_feed_token);
        $this->feed($token2)->assertNotFound();
        $this->assertDatabaseHas('auditoria', ['acao' => 'agenda.feed_revogado']);
        $this->assertSame(3, Auditoria::where('acao', 'like', 'agenda.feed_%')->count());
    }

    public function test_token_nao_sai_em_json_do_utilizador(): void
    {
        $chefe = $this->admin();
        $this->assertArrayNotHasKey('agenda_feed_token', $chefe->toArray());
    }
}
