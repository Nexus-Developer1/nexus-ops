<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Listagem;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Barra de filtros da listagem de equipamentos (reorganizada em set. 2026): pesquisa + Tipo,
// Família, Banco de baterias e Ordenar, cada um com rótulo, e "Limpar filtros" só quando há
// alguma coisa filtrada.
class EquipamentosFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function equipamento(string $serie, string $tipo = 'ups', ?string $familia = null): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME '.$serie, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => $tipo, 'estado' => 'operacional',
            'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => $serie, 'faminome' => $familia]);
    }

    public function test_barra_mostra_os_rotulos_e_os_filtros_funcionam(): void
    {
        $this->equipamento('SN-UPS', 'ups', 'UPS');
        $this->equipamento('SN-INC', 'incendio', 'Incêndio');

        $c = Livewire::actingAs($this->admin())->test(Listagem::class);

        // Rótulos por cima de cada controlo (era o que faltava para se perceber o que filtra o quê).
        $c->assertSee('Tipo')->assertSee('Família')->assertSee('Banco de baterias')->assertSee('Ordenar')
            ->assertSee('SN-UPS')->assertSee('SN-INC')
            ->assertDontSee('Limpar filtros'); // sem filtros → não aparece

        $c->set('tipo', 'incendio')->assertSee('SN-INC')->assertDontSee('SN-UPS')->assertSee('Limpar filtros');
    }

    public function test_limpar_filtros_repoe_a_lista_e_mantem_a_ordenacao(): void
    {
        $this->equipamento('SN-UPS', 'ups', 'UPS');
        $this->equipamento('SN-INC', 'incendio', 'Incêndio');

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->set('pesquisa', 'SN-INC')
            ->set('tipo', 'incendio')
            ->set('familia', 'Incêndio')
            ->set('banco', 'sem')
            ->set('ordenar', 'serie_asc')
            ->assertSee('Limpar filtros')
            ->call('limparFiltros')
            ->assertSet('pesquisa', '')
            ->assertSet('tipo', '')
            ->assertSet('familia', '')
            ->assertSet('banco', '')
            ->assertSet('ordenar', 'serie_asc')   // ordenação não é filtro — fica
            ->assertSee('SN-UPS')->assertSee('SN-INC')
            ->assertDontSee('Limpar filtros');
    }

    public function test_filtro_de_familia_so_aparece_com_familias_do_phc(): void
    {
        $this->equipamento('SN-SEM-FAM'); // sem faminome

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->assertDontSee('Família')
            ->assertSee('Tipo');
    }
}
