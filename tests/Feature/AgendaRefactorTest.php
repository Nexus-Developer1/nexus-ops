<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use App\Services\Agenda\SincronizadorAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Fase 2 do refactor da agenda: guardas explícitas do SincronizadorAgenda (ponto único
// das camadas 2/3) e backfill do tecnico_id nos eventos legados (nome → conta).
class AgendaRefactorTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Cliente, 1: Equipamento} */
    private function contexto(): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40', 'numero_serie' => 'SN-1']);

        return [$cliente, $equip];
    }

    public function test_sincronizador_gera_rascunho_de_evento_futuro_com_equipamento(): void
    {
        [$cliente, $equip] = $this->contexto();
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Visita', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);

        $relatorio = app(SincronizadorAgenda::class)->eventoGravado($evento);

        $this->assertNotNull($relatorio);
        $this->assertSame('rascunho', $relatorio->estado->value);
        $this->assertSame($evento->fresh()->intervencao_id, $relatorio->intervencao_id); // ligado dos dois lados
    }

    public function test_sincronizador_guarda_anti_loop_evento_ja_convertido(): void
    {
        [$cliente, $equip] = $this->contexto();
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Visita', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada',
            'data_inicio' => now()->addWeek()->toDateString(), 'evento_agenda_id' => $evento->id]);
        $evento->update(['intervencao_id' => $interv->id]);

        // Evento já convertido (como os criados pela camada 3) → NUNCA gera segundo rascunho.
        $this->assertNull(app(SincronizadorAgenda::class)->eventoGravado($evento->fresh()));
        $this->assertSame(1, Intervencao::count());
    }

    public function test_sincronizador_ignora_evento_sem_ambito_ou_passado(): void
    {
        [$cliente, $equip] = $this->contexto();

        // Sem equipamento nem contrato → nada.
        $semAmbito = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0), 'cliente_id' => $cliente->id]);
        $this->assertNull(app(SincronizadorAgenda::class)->eventoGravado($semAmbito));

        // Passado → registo histórico, não gera trabalho.
        $passado = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Antiga', 'estado' => 'planeado',
            'inicio' => now()->subWeek()->setTime(9, 0), 'fim' => now()->subWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);
        $this->assertNull(app(SincronizadorAgenda::class)->eventoGravado($passado));

        $this->assertSame(0, Intervencao::count());
    }

    public function test_backfill_liga_eventos_legados_a_conta_do_tecnico(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        $joao = User::create(['nome' => 'João Silva', 'email' => 'j@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Nome AMBÍGUO: duas contas de técnico com o mesmo nome — não deve ser tocado.
        User::create(['nome' => 'Rui Costa', 'email' => 'r1@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        User::create(['nome' => 'Rui Costa', 'email' => 'r2@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        $legado = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'L', 'estado' => 'planeado',
            'inicio' => now(), 'fim' => now()->addHour(), 'tecnico_nome' => '  joão silva ', 'cliente_id' => $cliente->id]);
        $ambiguo = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'A', 'estado' => 'planeado',
            'inicio' => now(), 'fim' => now()->addHour(), 'tecnico_nome' => 'Rui Costa', 'cliente_id' => $cliente->id]);

        // Reexecuta o backfill (a migração corre no arranque do RefreshDatabase, antes dos dados).
        $migracao = require database_path('migrations/2026_07_23_000002_backfill_tecnico_id_eventos_agenda.php');
        $migracao->up();

        $this->assertSame($joao->id, $legado->fresh()->tecnico_id);   // casado apesar de caixa/espaços
        $this->assertNull($ambiguo->fresh()->tecnico_id);             // ambíguo fica como está
    }
}
