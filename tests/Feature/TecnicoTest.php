<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// CLAUDE.md §7: o técnico vê apenas as SUAS intervenções, agenda e relatórios,
// e não tem acesso às áreas de gestão (dashboard, contratos, alertas).
class TecnicoTest extends TestCase
{
    use RefreshDatabase;

    private function tecnico(string $email): User
    {
        return User::create(['nome' => $email, 'email' => $email, 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    /** Cria um conjunto agenda+intervenção+relatório atribuído a um técnico. */
    private function trabalhoDe(User $tecnico): array
    {
        $cliente = Cliente::create(['nome' => 'Cliente', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $evento = EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'V', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 09:00'), 'fim' => Carbon::parse('2026-07-06 10:00'),
            'tecnico_id' => $tecnico->id, 'cliente_id' => $cliente->id, 'equipamento_id' => $equip->id]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tecnico_id' => $tecnico->id,
            'tipo' => 'preventiva', 'estado' => 'concluida']);
        $rel = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/' . $tecnico->id, 'data' => now(), 'estado' => 'finalizado']);

        return compact('evento', 'interv', 'rel');
    }

    public function test_tecnico_so_ve_o_seu_trabalho(): void
    {
        $a = $this->tecnico('a@nexus.pt');
        $b = $this->tecnico('b@nexus.pt');
        $this->trabalhoDe($a);
        $this->trabalhoDe($b);

        $this->actingAs($a);
        $this->assertSame(1, EventoAgenda::count());
        $this->assertSame(1, Intervencao::count());
        $this->assertSame(1, Relatorio::count());
        $this->assertSame($a->id, EventoAgenda::first()->tecnico_id);
    }

    public function test_tecnico_nao_acede_a_areas_de_gestao(): void
    {
        $tec = $this->tecnico('t@nexus.pt');

        $this->actingAs($tec)->get('/dashboard')->assertRedirect(route('painel'));
        $this->actingAs($tec)->get('/contratos')->assertRedirect(route('painel'));
        $this->actingAs($tec)->get('/alertas')->assertRedirect(route('painel'));
        $this->actingAs($tec)->get('/ativos')->assertRedirect(route('painel'));
    }

    public function test_tecnico_acede_a_sua_operacao(): void
    {
        $tec = $this->tecnico('t@nexus.pt');

        $this->actingAs($tec)->get('/painel')->assertOk();
        $this->actingAs($tec)->get('/agenda')->assertOk();
        $this->actingAs($tec)->get('/relatorios')->assertOk();
    }

    public function test_admin_nao_aterra_no_painel_do_tecnico(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'admin@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->actingAs($admin)->get('/painel')->assertRedirect(route('dashboard'));
    }

    public function test_login_de_tecnico_aterra_no_painel(): void
    {
        $tec = $this->tecnico('t@nexus.pt');
        $tec->update(['password' => 'segredo123']);

        \Livewire\Livewire::test(\App\Livewire\Auth\Login::class)
            ->set('email', 't@nexus.pt')
            ->set('password', 'segredo123')
            ->call('autenticar')
            ->assertRedirect(route('painel'));
    }
}
