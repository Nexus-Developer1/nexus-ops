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
// relatório (marcado sem equipamento), abre o serviço na agenda. E o cartão «Relatórios por
// preencher», com os rascunhos (pedidos da equipa, set. 2026).
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

    // ---- Cartão «Relatórios por preencher» (os rascunhos; substituiu as renovações) ----------

    public function test_relatorios_por_preencher_mostra_os_rascunhos_pela_data_e_abre_o_relatorio(): void
    {
        $equip = Equipamento::create(['local_id' => Local::create(['cliente_id' => Cliente::create(['nome' => 'CLIENTE-X', 'ativo' => true])->id, 'designacao' => 'DC1'])->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);
        $relatorio = function (string $estado, string $data, ?string $numero = null) use ($equip) {
            $i = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada', 'data_inicio' => $data]);

            return $i->relatorio()->create(['estado' => $estado, 'numero' => $numero, 'data' => $data]);
        };
        $depois = $relatorio('rascunho', now()->addDays(3)->toDateString());
        $atrasado = $relatorio('rascunho', now()->subDays(2)->toDateString());
        $finalizado = $relatorio('finalizado', now()->addDay()->toDateString(), '2026/0001');
        $enviado = $relatorio('enviado', now()->addDay()->toDateString(), '2026/0002');

        $html = Livewire::actingAs($this->admin())->test(DashboardGestao::class)
            ->assertSee('Relatórios por preencher')
            ->assertSeeHtml('href="'.route('relatorios.editar', $depois).'"')
            ->assertSeeHtml('href="'.route('relatorios.editar', $atrasado).'"')
            ->assertSeeHtml('O serviço já foi feito — falta preencher o relatório') // o atrasado, a laranja
            ->assertDontSeeHtml('href="'.route('relatorios.editar', $finalizado).'"')  // finalizado sai
            ->assertDontSeeHtml('href="'.route('relatorios.editar', $enviado).'"')
            ->assertDontSee('Sem renovações próximas.') // o cartão antigo (o número em cima fica)
            ->html();

        // O mais antigo (serviço já feito) primeiro.
        $this->assertLessThan(
            strpos($html, route('relatorios.editar', $depois)),
            strpos($html, route('relatorios.editar', $atrasado)),
        );
    }

    public function test_relatorios_por_preencher_mostra_no_maximo_sete(): void
    {
        $equip = Equipamento::create(['local_id' => Local::create(['cliente_id' => Cliente::create(['nome' => 'ACME', 'ativo' => true])->id, 'designacao' => 'DC1'])->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);
        foreach (range(1, 9) as $n) {
            Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'planeada', 'data_inicio' => now()->addDays($n)->toDateString()])
                ->relatorio()->create(['estado' => 'rascunho', 'data' => now()]);
        }

        $this->assertCount(7, Livewire::actingAs($this->admin())->test(DashboardGestao::class)->viewData('rascunhos'));
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
