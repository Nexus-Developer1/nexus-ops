<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private function clienteComRelatorio(string $nome): array
    {
        $cliente = Cliente::create(['nome' => $nome, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        // ENVIADO: desde a 11.ª revisão o portal só mostra relatórios enviados (rascunhos e
        // finalizados são trabalho interno — podem estar a meio de uma reedição).
        $rel = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/' . $cliente->id, 'data' => now(), 'estado' => 'enviado', 'enviado_em' => now()]);
        $user = User::create(['nome' => 'C' . $cliente->id, 'email' => 'c' . $cliente->id . '@x.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $cliente->id, 'ativo' => true]);

        return [$cliente, $user, $rel, $equip];
    }

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'admin@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_cliente_e_encaminhado_da_app_para_o_portal(): void
    {
        [, $user] = $this->clienteComRelatorio('A');

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('portal.dashboard'));
        $this->actingAs($user)->get('/ativos')->assertRedirect(route('portal.dashboard'));
        $this->actingAs($user)->get('/contratos')->assertRedirect(route('portal.dashboard'));
    }

    public function test_admin_e_encaminhado_do_portal_para_a_app(): void
    {
        $this->actingAs($this->admin())->get('/portal')->assertRedirect(route('dashboard'));
    }

    public function test_cliente_ve_o_seu_portal(): void
    {
        [$cliente, $user] = $this->clienteComRelatorio('Central Norte');

        $this->actingAs($user)->get('/portal')->assertOk()->assertSee('Central Norte');
        $this->actingAs($user)->get('/portal/equipamentos')->assertOk();
        $this->actingAs($user)->get('/portal/relatorios')->assertOk()->assertSee('2026/' . $cliente->id);
    }

    public function test_portal_so_mostra_relatorios_enviados(): void
    {
        [$cliente, $user, , $equip] = $this->clienteComRelatorio('A');

        // Um FINALIZADO (trabalho interno — ex.: enviado reaberto para edição) do PRÓPRIO cliente.
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        $interno = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/9999', 'data' => now(), 'estado' => 'finalizado']);

        // A listagem e o dashboard do portal só mostram o enviado.
        $this->actingAs($user)->get('/portal/relatorios')
            ->assertOk()
            ->assertSee('2026/' . $cliente->id)
            ->assertDontSee('2026/9999');
        $this->actingAs($user)->get('/portal')
            ->assertOk()
            ->assertDontSee('2026/9999');

        // E o PDF de um não-enviado dá 404 MESMO sendo do próprio cliente (não revela
        // trabalho em curso — versões a meio da edição nunca chegam ao portal).
        $this->actingAs($user)->get(route('portal.relatorios.pdf', $interno))->assertNotFound();
    }

    public function test_cliente_nao_acede_a_relatorio_de_outro_cliente(): void
    {
        [, $userA] = $this->clienteComRelatorio('A');
        [, , $relatorioB] = $this->clienteComRelatorio('B');

        // O relatório do cliente B não é resolúvel pelo cliente A (global scope → 404).
        $this->actingAs($userA)->get(route('portal.relatorios.pdf', $relatorioB))->assertNotFound();
    }

    public function test_cliente_sem_cliente_id_nao_ve_nada(): void
    {
        // Existem dados reais de um cliente.
        $this->clienteComRelatorio('A');

        // Conta de cliente mal formada (sem cliente_id): NÃO pode ver tudo — fail-closed → vê nada.
        $orfao = User::create(['nome' => 'Órfão', 'email' => 'orfao@x.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => null, 'ativo' => true]);

        $this->actingAs($orfao);

        $this->assertSame(0, Relatorio::count());
        $this->assertSame(0, Equipamento::count());
    }

    public function test_login_de_cliente_aterra_no_portal(): void
    {
        [, $user] = $this->clienteComRelatorio('A');
        $user->update(['password' => 'segredo123']);

        $this->loginComMfa($user->email, 'segredo123')
            ->assertRedirect(route('portal.dashboard'));
    }
}
