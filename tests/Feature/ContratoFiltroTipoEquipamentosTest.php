<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Editor;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Seletor de equipamentos do contrato com filtro por TIPO (UPS / Deteção de incêndio / …):
// a lista mostra só o tipo escolhido e o "Selecionar todos" respeita o filtro ativo,
// juntando à seleção existente (nunca desmarca — para isso há o "Limpar").
class ContratoFiltroTipoEquipamentosTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Cliente, 2: Equipamento, 3: Equipamento, 4: Equipamento} */
    private function contexto(): array
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $ups1 = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-UPS-1']);
        $ups2 = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-UPS-2']);
        $sadi = Equipamento::create(['local_id' => $local->id, 'tipo' => 'incendio', 'estado' => 'operacional', 'numero_serie' => 'SN-SADI']);

        return [$admin, $cliente, $ups1, $ups2, $sadi];
    }

    public function test_filtro_por_tipo_mostra_so_esse_tipo(): void
    {
        [$admin, $cliente] = $this->contexto();

        $c = Livewire::actingAs($admin)->test(Editor::class)->set('cliente_id', $cliente->id);

        // Sem filtro: chips visíveis (2 tipos) e tudo listado.
        $c->assertSee('SN-UPS-1')->assertSee('SN-SADI')->assertSee('Deteção de incêndio');

        // Filtro UPS: os equipamentos de incêndio saem da lista.
        $c->set('filtroTipoEquipamento', 'ups')
            ->assertSee('SN-UPS-1')->assertSee('SN-UPS-2')
            ->assertDontSee('SN-SADI');

        // Filtro incêndio: só o SADI.
        $c->set('filtroTipoEquipamento', 'incendio')
            ->assertSee('SN-SADI')
            ->assertDontSee('SN-UPS-1');
    }

    public function test_selecionar_todos_respeita_o_filtro_e_junta_a_selecao(): void
    {
        [$admin, $cliente, $ups1, $ups2, $sadi] = $this->contexto();

        // Com o SADI já marcado à mão, "Selecionar todos" com filtro UPS junta as UPS
        // sem desmarcar o SADI.
        Livewire::actingAs($admin)->test(Editor::class)
            ->set('cliente_id', $cliente->id)
            ->set('equipamentoIds', [$sadi->id])
            ->set('filtroTipoEquipamento', 'ups')
            ->call('selecionarTodosEquipamentos')
            ->assertSet('equipamentoIds', fn ($ids) => count($ids) === 3
                && in_array($ups1->id, $ids) && in_array($ups2->id, $ids) && in_array($sadi->id, $ids));

        // Sem filtro, continua a marcar tudo.
        Livewire::actingAs($admin)->test(Editor::class)
            ->set('cliente_id', $cliente->id)
            ->call('selecionarTodosEquipamentos')
            ->assertSet('equipamentoIds', fn ($ids) => count($ids) === 3);
    }

    public function test_mudar_de_cliente_limpa_um_filtro_que_ja_nao_se_aplica(): void
    {
        [$admin, $cliente] = $this->contexto();
        $so_ups = Cliente::create(['nome' => 'Beta', 'ativo' => true]);
        $localB = Local::create(['cliente_id' => $so_ups->id, 'designacao' => 'Sala']);
        Equipamento::create(['local_id' => $localB->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-B-UPS']);

        // Filtro "incendio" ativo no cliente A; o cliente B não tem incêndio → filtro cai
        // para "Todos" (senão a lista ficava vazia sem razão visível).
        Livewire::actingAs($admin)->test(Editor::class)
            ->set('cliente_id', $cliente->id)
            ->set('filtroTipoEquipamento', 'incendio')
            ->set('cliente_id', $so_ups->id)
            ->assertSet('filtroTipoEquipamento', '')
            ->assertSee('SN-B-UPS');
    }
}
