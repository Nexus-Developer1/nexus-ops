<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Livewire\Despesas\Listagem;
use App\Models\Despesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Módulo de despesas: registo individual no LAYOUT da folha da empresa (cabeçalho +
// grelha de colunas fixas) com recibos digitalizados. As ligações a cliente/intervenção
// saíram do formulário (despesas antigas mantêm as suas).
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

    public function test_cria_despesa_pela_grelha_da_folha(): void
    {
        $admin = $this->admin();

        // Coluna 5 = "Outras despesas".
        Livewire::actingAs($admin)->test(Editor::class)
            ->set('data', now()->toDateString())
            ->set('descricao', 'Baterias 12V x4')
            ->set('valores.5', '320')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('despesas'));

        $this->assertDatabaseHas('despesas', [
            'descricao' => 'Baterias 12V x4',
            'categoria' => 'Outras despesas',
            'valor' => 320.00,
            'criado_por' => $admin->id,
        ]);
    }

    // Várias colunas preenchidas → uma despesa por coluna (categoria deriva do índice — não
    // há categoria forjável); cabeçalho (matrícula/departamento) partilhado.
    public function test_varias_colunas_criam_uma_despesa_por_categoria(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('data', now()->toDateString())
            ->set('descricao', 'ACME - Porto')
            ->set('matricula', 'BD-71-VI')
            ->set('valores.0', '20.50')   // Combustíveis
            ->set('valores.3', '12')      // Refeições
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('despesas', ['categoria' => 'Combustíveis', 'valor' => 20.50, 'descricao' => 'ACME - Porto', 'matricula' => 'BD-71-VI']);
        $this->assertDatabaseHas('despesas', ['categoria' => 'Refeições', 'valor' => 12.00, 'descricao' => 'ACME - Porto']);
        $this->assertSame(2, Despesa::count());
    }

    // Sem nenhuma coluna preenchida, não grava (a folha exige pelo menos um valor).
    public function test_sem_valores_nao_grava(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('data', now()->toDateString())
            ->set('descricao', 'Vazio')
            ->call('guardar')
            ->assertHasErrors('valores');

        $this->assertSame(0, Despesa::count());
    }

    // Editar: o valor aparece na coluna da categoria e pode mudar de coluna; as ligações
    // antigas (cliente/intervenção, que já não se editam aqui) são preservadas.
    public function test_editar_carrega_a_coluna_certa_e_preserva_ligacoes(): void
    {
        $admin = $this->admin();
        $cliente = \App\Models\Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $despesa = Despesa::create(['data' => now(), 'categoria' => 'Hotel', 'descricao' => 'Estadia', 'valor' => 80,
            'faturavel' => true, 'cliente_id' => $cliente->id]);

        Livewire::actingAs($admin)->test(Editor::class, ['despesa' => $despesa])
            ->assertSet('valores.2', '80.00') // Hotel = coluna 2
            ->set('valores.2', '')
            ->set('valores.4', '95')          // muda para Táxi/Comboio/Avião
            ->call('guardar')
            ->assertHasNoErrors();

        $despesa->refresh();
        $this->assertSame('Táxi / Comboio / Avião', $despesa->categoria);
        $this->assertSame('95.00', (string) $despesa->valor);
        $this->assertSame($cliente->id, $despesa->cliente_id); // ligação antiga intacta
        $this->assertTrue($despesa->faturavel);                // idem
    }

    // Recibos digitalizados: pendentes no formulário, gravam-se com a despesa; na edição
    // removem-se. Asserções sobre metadados (o upload fake do Livewire não aterra no disco
    // que o Storage lê neste ambiente — limitação conhecida, igual às fotos dos relatórios).
    public function test_recibos_gravam_com_a_despesa_e_removem_se(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('data', now()->toDateString())
            ->set('descricao', 'Almoço ACME')
            ->set('valores.3', '14.20') // Refeições
            ->set('recibosUpload', [\Illuminate\Http\UploadedFile::fake()->image('recibo.jpg', 800, 600)])
            ->call('guardar')
            ->assertHasNoErrors();

        $despesa = Despesa::where('descricao', 'Almoço ACME')->firstOrFail();
        $recibo = $despesa->anexos()->firstOrFail();
        $this->assertSame('recibo.jpg', $recibo->nome_ficheiro);
        $this->assertNotSame('', (string) $recibo->storage_key);

        Livewire::actingAs($admin)->test(Editor::class, ['despesa' => $despesa])
            ->assertViewHas('recibosGravados', fn ($r) => $r->count() === 1)
            ->call('removerReciboGravado', $recibo->id);

        $this->assertSame(0, $despesa->anexos()->count());
        \Illuminate\Support\Facades\Storage::disk()->delete($recibo->storage_key); // limpeza best-effort
    }

    public function test_kpis_separam_faturavel_de_incluido(): void
    {
        $admin = $this->admin();
        Despesa::create(['data' => now(), 'categoria' => 'Outras despesas', 'descricao' => 'A', 'valor' => 100, 'faturavel' => true]);
        Despesa::create(['data' => now(), 'categoria' => 'Combustíveis', 'descricao' => 'B', 'valor' => 50, 'faturavel' => false]);
        // Fora do mês atual → não entra no KPI por defeito (período = mês).
        Despesa::create(['data' => now()->subMonths(2), 'categoria' => 'Hotel', 'descricao' => 'C', 'valor' => 999, 'faturavel' => true]);

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
