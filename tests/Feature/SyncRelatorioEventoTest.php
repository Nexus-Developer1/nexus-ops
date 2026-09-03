<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use App\Services\Agenda\GeradorEventoDeRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Camada 3 da sincronização: Relatório (data futura) → Evento de agenda. Inclui a prova
// anti-ciclo (um rascunho nascido da camada 2 não gera um segundo evento de volta).
class SyncRelatorioEventoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    /** @return array{0: Cliente, 1: Local, 2: Equipamento} */
    private function contexto(string $serie): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40', 'numero_serie' => $serie]);

        return [$cliente, $local, $equip];
    }

    public function test_relatorio_com_data_futura_cria_evento_ligado(): void
    {
        [$cliente, $local, $equip] = $this->contexto('SN-100');

        Livewire::actingAs($this->admin())->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('tipo', 'corretiva')
            ->set('data', now()->addWeek()->toDateString())
            ->set('hora_inicio', '10:00')
            ->set('hora_fim', '11:30')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $intervencao = Intervencao::firstOrFail();
        $this->assertNotNull($intervencao->evento_agenda_id); // lado da intervenção

        // Evento criado, ligado dos dois lados, com contexto herdado e horas do relatório.
        $this->assertDatabaseHas('eventos_agenda', [
            'id' => $intervencao->evento_agenda_id,
            'intervencao_id' => $intervencao->id,                 // lado do evento
            'tipo' => 'intervencao',
            'equipamento_id' => $equip->id,
            'local_id' => $local->id,
            'cliente_id' => $cliente->id,
        ]);

        $evento = EventoAgenda::find($intervencao->evento_agenda_id);
        $this->assertSame('10:00', $evento->inicio->format('H:i'));
        $this->assertSame('11:30', $evento->fim->format('H:i'));
    }

    public function test_relatorio_com_data_passada_nao_cria_evento(): void
    {
        [, , $equip] = $this->contexto('SN-101');

        Livewire::actingAs($this->admin())->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('tipo', 'corretiva')
            ->set('data', now()->subWeek()->toDateString())
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('eventos_agenda', 0);
    }

    public function test_relatorio_ja_vindo_de_evento_nao_cria_segundo_evento(): void
    {
        // Uma intervenção que já tem evento ligado (como deixaria a camada 2): mudar/gravar
        // não deve criar um segundo evento — atualiza o existente.
        [, , $equip] = $this->contexto('SN-102');
        $evento = EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'Origem', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id,
        ]);
        $intervencao = Intervencao::create([
            'equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'planeada',
            'data_inicio' => now()->addWeek()->toDateString(), 'evento_agenda_id' => $evento->id,
        ]);
        $evento->update(['intervencao_id' => $intervencao->id]);

        app(GeradorEventoDeRelatorio::class)->gerar($intervencao->fresh());

        $this->assertDatabaseCount('eventos_agenda', 1); // não duplicou
    }

    public function test_anti_ciclo_rascunho_da_camada2_nao_gera_segundo_evento(): void
    {
        Notification::fake();
        [, , $equip] = $this->contexto('SN-103');
        $admin = $this->admin();

        $inicio = now()->addWeek()->setTime(10, 0);
        $fim = (clone $inicio)->setTime(11, 0);

        // Camada 2: evento manual com equipamento + futuro → cria rascunho ligado.
        Livewire::actingAs($admin)->test(Calendario::class)
            ->set('formTitulo', 'Inspeção')
            ->set('formEquipamentoId', $equip->id)
            ->set('formInicio', $inicio->format('Y-m-d\TH:i'))
            ->set('formFim', $fim->format('Y-m-d\TH:i'))
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('eventos_agenda', 1);
        $intervencao = Intervencao::firstOrFail();
        $this->assertNotNull($intervencao->evento_agenda_id);

        // Camada 3 sobre esse rascunho: NÃO cria um segundo evento (a intervenção já tem evento).
        app(GeradorEventoDeRelatorio::class)->gerar($intervencao->fresh());
        $this->assertDatabaseCount('eventos_agenda', 1);

        // E ao gravar o rascunho no editor de relatórios também não duplica.
        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $intervencao->relatorio])
            ->call('guardarRascunho')
            ->assertHasNoErrors();
        $this->assertDatabaseCount('eventos_agenda', 1);
    }
}
