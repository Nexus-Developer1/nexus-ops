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
        $rel = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/'.$tecnico->id, 'data' => now(), 'estado' => 'finalizado']);

        return compact('evento', 'interv', 'rel');
    }

    public function test_tecnico_ve_todo_o_trabalho_como_o_admin(): void
    {
        // Técnico = espelho do admin: vê o trabalho de TODOS os técnicos (sem isolamento).
        $a = $this->tecnico('a@nexus.pt');
        $b = $this->tecnico('b@nexus.pt');
        $this->trabalhoDe($a);
        $this->trabalhoDe($b);

        $this->actingAs($a);
        $this->assertSame(2, EventoAgenda::count());  // o seu + o do outro técnico
        $this->assertSame(2, Intervencao::count());
        $this->assertSame(2, Relatorio::count());
    }

    public function test_tecnico_acede_a_gestao_menos_utilizadores(): void
    {
        $tec = $this->tecnico('t@nexus.pt');

        // Técnico = admin, EXCETO gerir utilizadores → tem acesso a estas áreas.
        $this->actingAs($tec)->get('/dashboard')->assertOk();
        $this->actingAs($tec)->get('/contratos')->assertOk();
        $this->actingAs($tec)->get('/alertas')->assertOk();
        $this->actingAs($tec)->get('/ativos')->assertOk();
        $this->actingAs($tec)->get('/despesas')->assertOk();

        // ÚNICA exceção: gestão de utilizadores → 403 (Gate 'gerir-utilizadores').
        $this->actingAs($tec)->get(route('utilizadores.adicionar'))->assertForbidden();
    }

    public function test_tecnico_acede_a_sua_operacao(): void
    {
        $tec = $this->tecnico('t@nexus.pt');

        $this->actingAs($tec)->get('/agenda')->assertOk();
        $this->actingAs($tec)->get('/relatorios')->assertOk();
    }

    public function test_login_de_tecnico_aterra_no_dashboard_como_admin(): void
    {
        // Técnico = espelho do admin → aterra no dashboard (já não no painel).
        $tec = $this->tecnico('t@nexus.pt');
        $tec->update(['password' => 'segredo123']);

        $this->loginComMfa('t@nexus.pt', 'segredo123')
            ->assertRedirect(route('dashboard'));
    }
}
