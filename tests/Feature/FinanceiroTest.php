<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Despesas\Editor;
use App\Livewire\Despesas\Listagem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Papel `financeiro` (set. 2026): só o módulo de despesas. Não é técnico — não entra em
// equipamentos, contratos, agenda nem relatórios, e não aparece nas listas de técnicos.
class FinanceiroTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(PapelUtilizador $papel, string $email): User
    {
        return User::create(['nome' => ucfirst($papel->value), 'email' => $email, 'password' => 'x', 'papel' => $papel, 'ativo' => true]);
    }

    private function financeiro(): User
    {
        return $this->utilizador(PapelUtilizador::Financeiro, 'f@nexus.pt');
    }

    public function test_entra_nas_despesas_e_pode_criar_um_registo(): void
    {
        $financeiro = $this->financeiro();

        $this->actingAs($financeiro)->get(route('despesas'))->assertOk();
        $this->actingAs($financeiro)->get(route('despesas.nova'))->assertOk();

        Livewire::actingAs($financeiro)->test(Listagem::class)->assertOk();
        Livewire::actingAs($financeiro)->test(Editor::class)->assertOk();
    }

    public function test_o_resto_da_aplicacao_manda_o_de_volta_para_as_despesas(): void
    {
        $financeiro = $this->financeiro();

        foreach (['dashboard', 'ativos', 'clientes', 'relatorios', 'contratos', 'encomendas', 'agenda', 'alertas', 'relatorios.novo', 'auditoria'] as $rota) {
            $this->actingAs($financeiro)->get(route($rota))
                ->assertRedirect(route('despesas'));
        }

        // A raiz e o portal do cliente também.
        $this->actingAs($financeiro)->get('/')->assertRedirect(route('despesas'));
        $this->actingAs($financeiro)->get(route('portal.dashboard'))->assertRedirect(route('despesas'));

        $this->assertSame('despesas', $financeiro->rotaInicial());
    }

    public function test_os_componentes_da_equipa_recusam_no_pelo_servidor(): void
    {
        // Mesmo sem passar pela rota (ex.: pedido Livewire direto), o componente barra-o.
        Livewire::actingAs($this->financeiro())->test(Calendario::class)->assertForbidden();
    }

    public function test_o_cliente_continua_fora_das_despesas(): void
    {
        $cliente = User::create(['nome' => 'C', 'email' => 'c@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Cliente, 'ativo' => true, 'cliente_id' => null]);

        Livewire::actingAs($cliente)->test(Listagem::class)->assertForbidden();
    }

    public function test_a_barra_lateral_so_mostra_as_despesas(): void
    {
        $resposta = $this->actingAs($this->financeiro())->get(route('despesas'))->assertOk();

        $resposta->assertSee('Despesas');
        foreach (['Equipamentos', 'Relatórios', 'Contratos', 'Agenda', 'Alertas', 'Novo Relatório', 'Auditoria', 'Dossiers PHC'] as $item) {
            $resposta->assertDontSee($item);
        }
    }

    public function test_a_equipa_tecnica_continua_a_ver_tudo(): void
    {
        $tecnico = $this->utilizador(PapelUtilizador::Tecnico, 't@nexus.pt');

        $resposta = $this->actingAs($tecnico)->get(route('despesas'))->assertOk();
        $resposta->assertSee('Equipamentos')->assertSee('Novo Relatório');
        $this->assertSame('dashboard', $tecnico->rotaInicial());
    }

    public function test_nao_aparece_nas_listas_de_tecnicos(): void
    {
        $financeiro = $this->financeiro();
        $this->utilizador(PapelUtilizador::Tecnico, 't@nexus.pt');

        $this->assertSame(['t@nexus.pt'], User::fazServicos()->pluck('email')->all());
        $this->assertFalse($financeiro->ehEquipa());
    }
}
