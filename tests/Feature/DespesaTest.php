<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Livewire\Despesas\Listagem;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Despesa;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
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
            ->set('categoria', 'Outras despesas')
            ->set('descricao', 'Baterias 12V x4')
            ->set('valor', '320')
            ->set('faturavel', true)
            ->set('cliente_id', $cliente->id)
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('despesas'));

        $this->assertDatabaseHas('despesas', [
            'descricao' => 'Baterias 12V x4',
            'categoria' => 'Outras despesas',
            'valor' => 320.00,
            'faturavel' => true,
            'cliente_id' => $cliente->id,
            'criado_por' => $admin->id,
        ]);
    }

    // As categorias passaram a ser FIXAS (as colunas da folha de despesas) — texto livre é recusado.
    public function test_categoria_fora_das_colunas_fixas_e_recusada(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('data', now()->toDateString())
            ->set('categoria', 'Material')
            ->set('descricao', 'Cabo')
            ->set('valor', '10')
            ->call('guardar')
            ->assertHasErrors('categoria');
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

    // ---- Associação a intervenção ----

    /** @return array{0: Cliente, 1: Equipamento, 2: Contrato, 3: Intervencao, 4: Relatorio} */
    private function cenarioIntervencao(): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-999']);
        $modeloId = ModeloFaturacao::query()->value('id') ?? ModeloFaturacao::create(['nome' => 'Avença'])->id;
        $contrato = Contrato::create(['numero' => '2026/8001', 'cliente_id' => $cliente->id, 'data_inicio' => now()->subMonth(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => $modeloId]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/0042', 'data' => now(), 'estado' => 'finalizado']);

        return [$cliente, $equip, $contrato, $interv, $relatorio];
    }

    public function test_associar_intervencao_herda_cliente_equipamento_e_contrato(): void
    {
        $admin = $this->admin();
        [$cliente, $equip, $contrato, $interv] = $this->cenarioIntervencao();

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('data', now()->toDateString())
            ->set('categoria', 'Combustíveis')
            ->set('descricao', 'Baterias')
            ->set('valor', '100')
            ->call('selecionarIntervencao', $interv->id)
            ->assertSet('cliente_id', $cliente->id)     // herdados da intervenção
            ->assertSet('equipamento_id', $equip->id)
            ->assertSet('contrato_id', $contrato->id)
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('despesas'));

        $this->assertDatabaseHas('despesas', [
            'descricao' => 'Baterias',
            'intervencao_id' => $interv->id,
            'equipamento_id' => $equip->id,
            'contrato_id' => $contrato->id,
            'cliente_id' => $cliente->id,
        ]);
    }

    public function test_pesquisa_intervencao_por_relatorio_serie_e_cliente(): void
    {
        $admin = $this->admin();
        [, , , $interv] = $this->cenarioIntervencao();

        $c = Livewire::actingAs($admin)->test(Editor::class);

        // Sem texto → vazio (não carrega tudo).
        $c->assertViewHas('intervencoesFiltradas', fn ($r) => $r->isEmpty());

        // Por nº de relatório, por nº de série e por nome de cliente → encontra a intervenção.
        foreach (['2026/0042', 'SN-999', 'ACME'] as $termo) {
            $c->set('intervencaoBusca', $termo)
                ->assertViewHas('intervencoesFiltradas', fn ($r) => $r->contains('id', $interv->id));
        }
    }

    public function test_limpar_intervencao_remove_os_herdados(): void
    {
        $admin = $this->admin();
        [, , , $interv] = $this->cenarioIntervencao();

        Livewire::actingAs($admin)->test(Editor::class)
            ->call('selecionarIntervencao', $interv->id)
            ->assertSet('intervencao_id', $interv->id)
            ->call('limparIntervencao')
            ->assertSet('intervencao_id', null)
            ->assertSet('equipamento_id', null)
            ->assertSet('contrato_id', null);
    }

    public function test_editar_despesa_carrega_a_intervencao(): void
    {
        $admin = $this->admin();
        [$cliente, $equip, $contrato, $interv] = $this->cenarioIntervencao();
        $despesa = Despesa::create(['data' => now(), 'categoria' => 'Material', 'descricao' => 'X', 'valor' => 10, 'faturavel' => false,
            'cliente_id' => $cliente->id, 'equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'intervencao_id' => $interv->id]);

        Livewire::actingAs($admin)->test(Editor::class, ['despesa' => $despesa])
            ->assertSet('intervencao_id', $interv->id)
            ->assertSet('equipamento_id', $equip->id)
            ->assertSet('contrato_id', $contrato->id)
            ->assertSee('2026/0042'); // o rótulo mostra o nº do relatório
    }
}
