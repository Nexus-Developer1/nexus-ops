<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Listagem;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Apagar um relatório também o tira da agenda (o evento ligado é removido). Espelha o
// reverso (remover evento apaga o rascunho + intervenção).
class EliminarRelatorioAgendaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function equipamento(): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);
    }

    /** Evento + intervenção + relatório ligados (como deixa a camada 2). */
    private function trioLigado(string $estadoRelatorio, ?string $numero = null): Relatorio
    {
        $equip = $this->equipamento();
        $evento = EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'Visita', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id,
        ]);
        $interv = Intervencao::create([
            'equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada',
            'data_inicio' => now()->addWeek()->toDateString(), 'evento_agenda_id' => $evento->id,
        ]);
        $evento->update(['intervencao_id' => $interv->id]);

        return $interv->relatorio()->create(['estado' => $estadoRelatorio, 'numero' => $numero, 'data' => now()]);
    }

    public function test_eliminar_rascunho_ligado_remove_evento_e_intervencao(): void
    {
        $relatorio = $this->trioLigado('rascunho');
        $interv = $relatorio->intervencao;
        $eventoId = $interv->evento_agenda_id;

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->call('eliminar', $relatorio->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('relatorios', ['id' => $relatorio->id]);
        $this->assertSoftDeleted('intervencoes', ['id' => $interv->id]);
        $this->assertSoftDeleted('eventos_agenda', ['id' => $eventoId]); // saiu da agenda
    }

    public function test_eliminar_finalizado_ligado_apaga_tudo_incluindo_intervencao(): void
    {
        $relatorio = $this->trioLigado('finalizado', '2026/0001');
        $interv = $relatorio->intervencao;
        $eventoId = $interv->evento_agenda_id;

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->call('eliminar', $relatorio->id)
            ->assertHasNoErrors();

        // Finalizado comporta-se igual ao rascunho: apaga a unidade toda, sem deixar órfãos.
        $this->assertSoftDeleted('relatorios', ['id' => $relatorio->id]);
        $this->assertSoftDeleted('intervencoes', ['id' => $interv->id]);
        $this->assertSoftDeleted('eventos_agenda', ['id' => $eventoId]);
    }

    public function test_eliminar_enviado_e_rejeitado(): void
    {
        $relatorio = $this->trioLigado('enviado', '2026/0002');
        $interv = $relatorio->intervencao;
        $eventoId = $interv->evento_agenda_id;

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->call('eliminar', $relatorio->id)
            ->assertHasNoErrors(); // não lança exceção — rejeita com flash de erro

        // Nada é apagado: documento entregue ao cliente é imutável.
        $this->assertNotSoftDeleted('relatorios', ['id' => $relatorio->id]);
        $this->assertNotSoftDeleted('intervencoes', ['id' => $interv->id]);
        $this->assertNotSoftDeleted('eventos_agenda', ['id' => $eventoId]);
    }

    public function test_botao_eliminar_escondido_para_enviado(): void
    {
        $enviado = $this->trioLigado('enviado', '2026/0003');
        $rascunho = $this->trioLigado('rascunho');

        $html = Livewire::actingAs($this->admin())->test(Listagem::class)->html();

        $this->assertStringNotContainsString('eliminar(' . $enviado->id . ')', $html);  // enviado → sem botão
        $this->assertStringContainsString('eliminar(' . $rascunho->id . ')', $html);     // rascunho → com botão
    }

    public function test_eliminar_individual_sem_evento_so_remove_relatorio(): void
    {
        $equip = $this->equipamento();
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'em_curso']);
        $relatorio = $interv->relatorio()->create(['estado' => 'rascunho', 'data' => now()]);

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->call('eliminar', $relatorio->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('relatorios', ['id' => $relatorio->id]);
        $this->assertNotSoftDeleted('intervencoes', ['id' => $interv->id]); // sem evento → intervenção fica
    }
}
