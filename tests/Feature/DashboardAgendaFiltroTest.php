<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\DashboardGestao;
use App\Models\Cliente;
use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Dashboard → cartão "Agenda — próximos 7 dias": filtro por técnico (pedido da equipa). Conta
// tanto o técnico PRINCIPAL do evento como os ADICIONAIS.
class DashboardAgendaFiltroTest extends TestCase
{
    use RefreshDatabase;

    // Um evento sai do cartão assim que acaba; o que ainda decorre fica.
    public function test_evento_de_hoje_ja_terminado_sai_do_cartao(): void
    {
        \Illuminate\Support\Carbon::setTestNow(now()->setTime(17, 0));
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $base = ['tipo' => 'outro', 'estado' => 'planeado', 'cliente_id' => $cliente->id, 'tecnico_id' => $admin->id]; // 'outro': uma visita passada viraria alerta "em atraso" no outro cartão
        EventoAgenda::create($base + ['titulo' => 'REUNIAO-TERMINADA', 'inicio' => now()->setTime(9, 0), 'fim' => now()->setTime(11, 0)]);
        EventoAgenda::create($base + ['titulo' => 'A-DECORRER', 'inicio' => now()->setTime(16, 0), 'fim' => now()->setTime(18, 0)]);
        EventoAgenda::create($base + ['titulo' => 'LOGO-A-SEGUIR', 'inicio' => now()->setTime(18, 0), 'fim' => now()->setTime(19, 0)]);

        Livewire::actingAs($admin)->test(DashboardGestao::class)
            ->assertDontSee('REUNIAO-TERMINADA')
            ->assertSee('A-DECORRER')
            ->assertSee('LOGO-A-SEGUIR');
    }

    public function test_filtro_por_tecnico_mostra_so_os_eventos_dele_incluindo_como_adicional(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $rui = User::create(['nome' => 'Rui Pereira', 'email' => 'r@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $julio = User::create(['nome' => 'Julio Santos', 'email' => 'j@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);

        $base = ['tipo' => 'visita_preventiva', 'estado' => 'planeado', 'cliente_id' => $cliente->id];
        EventoAgenda::create($base + ['titulo' => 'EVENTO-RUI', 'tecnico_id' => $rui->id, 'inicio' => now()->addDay()->setTime(9, 0), 'fim' => now()->addDay()->setTime(11, 0)]);
        EventoAgenda::create($base + ['titulo' => 'EVENTO-JULIO', 'tecnico_id' => $julio->id, 'inicio' => now()->addDays(2)->setTime(9, 0), 'fim' => now()->addDays(2)->setTime(11, 0)]);
        $ambos = EventoAgenda::create($base + ['titulo' => 'EVENTO-AMBOS', 'tecnico_id' => $julio->id, 'inicio' => now()->addDays(3)->setTime(9, 0), 'fim' => now()->addDays(3)->setTime(11, 0)]);
        $ambos->tecnicosAdicionais()->attach($rui->id); // Rui vai como adicional

        $c = Livewire::actingAs($admin)->test(DashboardGestao::class);

        // Sem filtro: tudo; o seletor lista as contas da equipa.
        $c->assertSee('EVENTO-RUI')->assertSee('EVENTO-JULIO')->assertSee('EVENTO-AMBOS')
            ->assertSee('Todos os técnicos')->assertSee('Rui Pereira')->assertSee('Julio Santos');

        // Rui: o dele + aquele em que vai como adicional; o do Júlio não.
        $c->set('agendaTecnico', (string) $rui->id)
            ->assertSee('EVENTO-RUI')->assertSee('EVENTO-AMBOS')->assertDontSee('EVENTO-JULIO');

        // Júlio: os dois dele.
        $c->set('agendaTecnico', (string) $julio->id)
            ->assertSee('EVENTO-JULIO')->assertSee('EVENTO-AMBOS')->assertDontSee('EVENTO-RUI');

        // Técnico sem eventos → mensagem própria; valor forjado (não numérico) = sem filtro.
        $c->set('agendaTecnico', (string) $admin->id)->assertSee('Sem eventos deste técnico');
        $c->set('agendaTecnico', 'abc')->assertSee('EVENTO-RUI')->assertSee('EVENTO-JULIO');
    }
}
