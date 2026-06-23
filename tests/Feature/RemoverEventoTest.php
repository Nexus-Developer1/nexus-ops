<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Remoção de eventos a partir do modal de detalhe, com regras quanto ao relatório ligado.
class RemoverEventoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function equipamento(): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40']);
    }

    /** Evento + intervenção + relatório ligados (como deixa a camada 2/3). */
    private function eventoComRelatorio(string $estadoRelatorio, ?string $numero = null): EventoAgenda
    {
        $equip = $this->equipamento();
        $evento = EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'Inspeção', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id,
        ]);
        $intervencao = Intervencao::create([
            'equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'planeada',
            'data_inicio' => now()->addWeek()->toDateString(), 'evento_agenda_id' => $evento->id,
        ]);
        $evento->update(['intervencao_id' => $intervencao->id]);
        $intervencao->relatorio()->create(['estado' => $estadoRelatorio, 'numero' => $numero, 'data' => now()]);

        return $evento;
    }

    private function remover(EventoAgenda $evento): void
    {
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->call('removerEvento');
    }

    public function test_evento_sem_relatorio_e_removido(): void
    {
        $evento = EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
        ]);

        $this->remover($evento);

        $this->assertSoftDeleted('eventos_agenda', ['id' => $evento->id]);
    }

    public function test_evento_com_rascunho_apaga_evento_intervencao_e_relatorio(): void
    {
        $evento = $this->eventoComRelatorio('rascunho');
        $intervencaoId = $evento->intervencao_id;
        $relatorioId = $evento->intervencao->relatorio->id;

        $this->remover($evento);

        $this->assertSoftDeleted('eventos_agenda', ['id' => $evento->id]);
        $this->assertSoftDeleted('intervencoes', ['id' => $intervencaoId]);
        $this->assertSoftDeleted('relatorios', ['id' => $relatorioId]);
    }

    public function test_evento_com_relatorio_finalizado_e_bloqueado(): void
    {
        $evento = $this->eventoComRelatorio('finalizado', '2026/0001');
        $intervencaoId = $evento->intervencao_id;
        $relatorioId = $evento->intervencao->relatorio->id;

        $this->remover($evento);

        // Nada é apagado — o relatório finalizado é preservado.
        $this->assertNotSoftDeleted('eventos_agenda', ['id' => $evento->id]);
        $this->assertNotSoftDeleted('intervencoes', ['id' => $intervencaoId]);
        $this->assertNotSoftDeleted('relatorios', ['id' => $relatorioId]);
    }

    public function test_visita_preventiva_nao_e_removivel(): void
    {
        $equip = $this->equipamento();
        $evento = EventoAgenda::create([
            'tipo' => 'visita_preventiva', 'titulo' => 'Preventiva · APC X40', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 30),
            'equipamento_id' => $equip->id,
        ]);

        $this->remover($evento);

        $this->assertNotSoftDeleted('eventos_agenda', ['id' => $evento->id]);
    }
}
