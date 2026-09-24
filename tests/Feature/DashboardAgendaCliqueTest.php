<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\DashboardGestao;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Dashboard → cartão «Agenda — próximos 7 dias»: clicar num serviço abre o RELATÓRIO dele; sem
// relatório (marcado sem equipamento), abre o serviço na agenda (pedido da equipa, set. 2026).
class DashboardAgendaCliqueTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function evento(array $mais = []): EventoAgenda
    {
        return EventoAgenda::create($mais + [
            'tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => now()->addDay()->setTime(9, 0), 'fim' => now()->addDay()->setTime(11, 0),
        ]);
    }

    public function test_servico_com_relatorio_abre_o_relatorio(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $equip = Equipamento::create(['local_id' => Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1'])->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);
        $evento = $this->evento(['cliente_id' => $cliente->id, 'equipamento_id' => $equip->id]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada', 'data_inicio' => now()->addDay()->toDateString(), 'evento_agenda_id' => $evento->id]);
        $evento->update(['intervencao_id' => $interv->id]);
        $relatorio = $interv->relatorio()->create(['estado' => 'rascunho', 'data' => now()]);

        Livewire::actingAs($this->admin())->test(DashboardGestao::class)
            ->assertSeeHtml('href="'.route('relatorios.editar', $relatorio).'"')
            ->assertSeeHtml('title="Abrir o relatório"')
            ->assertDontSeeHtml('href="'.route('agenda', ['evento' => $evento->id]).'"');
    }

    public function test_servico_sem_relatorio_abre_o_servico_na_agenda(): void
    {
        $evento = $this->evento(['cliente_id' => Cliente::create(['nome' => 'ACME', 'ativo' => true])->id]);

        Livewire::actingAs($this->admin())->test(DashboardGestao::class)
            ->assertSeeHtml('href="'.route('agenda', ['evento' => $evento->id]).'"')
            ->assertSeeHtml('title="Sem relatório — abrir o serviço na agenda"');
    }

    public function test_agenda_abre_o_detalhe_do_servico_pelo_endereco(): void
    {
        $admin = $this->admin();
        $evento = $this->evento(['titulo' => 'SERVICO-DO-PAINEL']);

        Livewire::withQueryParams(['evento' => (string) $evento->id])
            ->actingAs($admin)->test(Calendario::class)
            ->assertSet('eventoSelecionadoId', $evento->id)
            ->assertSee('SERVICO-DO-PAINEL');

        // Pela página inteira, como o link do painel a abre.
        $this->actingAs($admin)->get(route('agenda', ['evento' => $evento->id]))
            ->assertOk()
            ->assertSee('SERVICO-DO-PAINEL');
    }

    // Id que não existe, apagado ou que não é número: abre a agenda normal, sem detalhe.
    public function test_evento_invalido_no_endereco_e_ignorado(): void
    {
        $admin = $this->admin();
        $apagado = $this->evento();
        $apagado->delete();

        foreach (['999999', (string) $apagado->id, 'abc', '1 OR 1=1'] as $valor) {
            Livewire::withQueryParams(['evento' => $valor])
                ->actingAs($admin)->test(Calendario::class)
                ->assertSet('eventoSelecionadoId', null);
        }
    }
}
