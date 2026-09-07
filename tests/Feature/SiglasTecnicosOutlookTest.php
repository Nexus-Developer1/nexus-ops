<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Services\Agenda\GeradorIcs;
use App\Services\Agenda\NotificadorAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// No Outlook (convites, feed e calendário partilhado) o título leva as SIGLAS dos técnicos à
// frente — inicial do nome + inicial do apelido — para se ver de quem é o evento sem o abrir.
// Na agenda da própria app o bloco mantém-se sem siglas (lá a cor identifica o técnico).
class SiglasTecnicosOutlookTest extends TestCase
{
    use RefreshDatabase;

    // O iCalendar dobra as linhas a 75 octetos (CRLF + espaço): junta-as antes de procurar texto.
    private static function desdobrar(string $ics): string
    {
        // Aspas simples: os escapes ficam para o PCRE (com aspas duplas o Pint reescreve-os).
        return preg_replace('/\r?\n[ \t]/', '', $ics);
    }

    public function test_sigla_e_inicial_do_nome_e_do_apelido(): void
    {
        $this->assertSame('PB', EventoAgenda::siglas('Paulo Bento'));
        $this->assertSame('RM', EventoAgenda::siglas('Rui Pedro Moreira')); // primeiro + último
        $this->assertSame('PB/DR', EventoAgenda::siglas('Paulo Bento, Daniel Ribeiro'));
        $this->assertSame('É', EventoAgenda::siglas('évora')); // um só nome, maiúscula com acento
        $this->assertNull(EventoAgenda::siglas(null));
        $this->assertNull(EventoAgenda::siglas('   '));
    }

    public function test_titulo_do_outlook_leva_as_siglas_a_frente(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        $paulo = User::create(['nome' => 'Paulo Bento', 'email' => 'p@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $daniel = User::create(['nome' => 'Daniel Ribeiro', 'email' => 'd@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'NU BOYANA PORTUGAL LDA', 'ativo' => true]);

        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'serviço', 'estado' => 'planeado',
            'inicio' => '2026-09-08 08:00', 'fim' => '2026-09-08 17:00',
            'tecnico_id' => $paulo->id, 'tecnico_nome' => $paulo->nome, 'cliente_id' => $cliente->id]);
        $evento->tecnicosAdicionais()->sync([$daniel->id]);
        $evento->refresh()->load('cliente', 'tecnico', 'tecnicosAdicionais');

        // Calendário partilhado (Graph) e feed iCal.
        $this->assertSame('PB/DR · serviço · NU BOYANA PORTUGAL LDA · Paulo Bento, Daniel Ribeiro', $evento->resumoOutlook());
        $coord = User::create(['nome' => 'Coord', 'email' => 'c@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->assertStringContainsString('SUMMARY:PB/DR · serviço', self::desdobrar(app(GeradorIcs::class)->feed($coord)));

        // Convite (parte do instantâneo, não do modelo) — mesmo título.
        $ics = app(GeradorIcs::class)->convite(NotificadorAgenda::instantaneo($evento), 0, $paulo);
        $this->assertStringContainsString('SUMMARY:PB/DR · serviço', self::desdobrar($ics));

        // Na app o bloco continua sem siglas (a cor já diz de quem é).
        $this->assertSame('serviço · NU BOYANA PORTUGAL LDA · Paulo Bento, Daniel Ribeiro', $evento->resumoCompleto());
    }

    public function test_evento_sem_tecnicos_nao_ganha_separador_a_toa(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => '2026-09-08 09:00', 'fim' => '2026-09-08 10:00', 'cliente_id' => $cliente->id]);

        $this->assertSame('Reunião · ACME', $evento->load('cliente')->resumoOutlook());
    }
}
