<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Livewire\Despesas\Listagem;
use App\Models\Cliente;
use App\Models\Despesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Módulo de despesas (área de gestão — admin).
class DespesaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    public function test_admin_cria_despesa_ligada_a_cliente(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('data', now()->toDateString())
            ->set('categoria', 'Material')
            ->set('descricao', 'Baterias 12V x4')
            ->set('valor', '320')
            ->set('faturavel', true)
            ->set('cliente_id', $cliente->id)
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('despesas'));

        $this->assertDatabaseHas('despesas', [
            'descricao' => 'Baterias 12V x4',
            'categoria' => 'Material',
            'valor' => 320.00,
            'faturavel' => true,
            'cliente_id' => $cliente->id,
            'criado_por' => $admin->id,
        ]);
    }

    public function test_categoria_nova_cresce_no_lookup_e_reutiliza_existente(): void
    {
        $admin = $this->admin();

        // Categoria nova (não existe nas 4 seedadas) → fica guardada.
        Livewire::actingAs($admin)->test(Editor::class)
            ->set('data', now()->toDateString())
            ->set('categoria', 'Peças sobressalentes')
            ->set('descricao', 'Ventoinha')
            ->set('valor', '40')
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categorias_despesa', ['nome' => 'Peças sobressalentes']);

        // Escrever "material" (minúsculas) reutiliza a "Material" seedada — sem duplicar
        // e gravando a forma canónica.
        Livewire::actingAs($admin)->test(Editor::class)
            ->set('data', now()->toDateString())
            ->set('categoria', 'material')
            ->set('descricao', 'Cabo')
            ->set('valor', '10')
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertSame(1, \App\Models\CategoriaDespesa::where('nome_normalizado', 'material')->count());
        $this->assertDatabaseHas('despesas', ['descricao' => 'Cabo', 'categoria' => 'Material']);
    }

    public function test_kpis_separam_faturavel_de_incluido(): void
    {
        $admin = $this->admin();
        Despesa::create(['data' => now(), 'categoria' => 'material', 'descricao' => 'A', 'valor' => 100, 'faturavel' => true]);
        Despesa::create(['data' => now(), 'categoria' => 'deslocacao', 'descricao' => 'B', 'valor' => 50, 'faturavel' => false]);
        // Fora do mês atual → não entra no KPI por defeito (período = mês).
        Despesa::create(['data' => now()->subMonths(2), 'categoria' => 'outro', 'descricao' => 'C', 'valor' => 999, 'faturavel' => true]);

        Livewire::actingAs($admin)->test(Listagem::class)
            ->assertViewHas('kpis', fn ($k) => $k['total'] === 150.0
                && $k['faturavel'] === 100.0
                && $k['incluido'] === 50.0
                && $k['numero'] === 2);
    }

    public function test_admin_e_tecnico_acedem(): void
    {
        // Técnico passou a ter as mesmas permissões que o admin (exceto gerir utilizadores).
        $this->actingAs($this->admin())->get('/despesas')->assertOk();
        $this->actingAs($this->tecnico())->get('/despesas')->assertOk();
    }
}
