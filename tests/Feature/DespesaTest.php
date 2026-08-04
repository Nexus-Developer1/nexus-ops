<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Livewire\Despesas\Listagem;
use App\Models\Despesa;
use App\Models\RegistoDespesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Módulo de despesas: REGISTOS no layout da folha da empresa (cabeçalho + grelha com
// várias linhas). O registo aparece na listagem como UMA só entrada e tem PDF
// transferível; por baixo, cada célula é uma linha em `despesas` (KPIs por categoria).
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

    // Um registo com 2 linhas × células → UMA entrada (registo) com as despesas dentro.
    public function test_registo_multilinha_aparece_como_uma_so_entrada(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('matricula', 'BD-71-VI')
            ->set('linhas.0.data', now()->toDateString())
            ->set('linhas.0.descricao', 'ACME - Porto')
            ->set('linhas.0.valores.0', '20.50')   // Combustíveis
            ->set('linhas.0.valores.3', '12')      // Refeições
            ->set('linhas.0.refeicao_tipo', 'A')
            ->call('adicionarLinha')
            ->set('linhas.1.data', now()->addDay()->toDateString())
            ->set('linhas.1.descricao', 'Beta - Lisboa')
            ->set('linhas.1.valores.2', '80')      // Hotel
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('despesas'));

        // UM registo; 3 lançamentos por baixo (KPIs por categoria preservados).
        $this->assertSame(1, RegistoDespesa::count());
        $registo = RegistoDespesa::firstOrFail();
        $this->assertSame('BD-71-VI', $registo->matricula);
        $this->assertSame(3, $registo->despesas()->count());
        $this->assertDatabaseHas('despesas', ['categoria' => 'Combustíveis', 'valor' => 20.50, 'registo_despesa_id' => $registo->id]);
        $this->assertDatabaseHas('despesas', ['categoria' => 'Refeições', 'valor' => 12.00, 'refeicao_tipo' => 'A']);
        $this->assertDatabaseHas('despesas', ['categoria' => 'Hotel', 'valor' => 80.00, 'descricao' => 'Beta - Lisboa']);
        $this->assertSame(112.5, $registo->total());

        // A listagem mostra UMA entrada.
        Livewire::actingAs($admin)->test(Listagem::class)
            ->assertViewHas('registos', fn ($p) => $p->total() === 1);
    }

    // Nota a) da folha: refeições exigem A/J (por linha).
    public function test_refeicoes_exigem_a_ou_j(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('linhas.0.data', now()->toDateString())
            ->set('linhas.0.descricao', 'Almoço ACME')
            ->set('linhas.0.valores.3', '12')
            ->call('guardar')
            ->assertHasErrors('linhas.0.refeicao_tipo');

        $this->assertSame(0, RegistoDespesa::count());
    }

    // Sem nenhuma célula preenchida, não grava.
    public function test_sem_valores_nao_grava(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('linhas.0.descricao', 'Vazio')
            ->call('guardar')
            ->assertHasErrors('linhas');

        $this->assertSame(0, RegistoDespesa::count());
    }

    // Editar: as linhas gravadas pré-carregam a grelha; guardar substitui as linhas do registo.
    public function test_editar_pre_carrega_e_substitui_as_linhas(): void
    {
        $admin = $this->admin();
        $registo = RegistoDespesa::create(['criado_por' => $admin->id, 'matricula' => 'AA-00-BB']);
        $registo->despesas()->create(['data' => '2026-08-03', 'categoria' => 'Hotel', 'descricao' => 'Estadia Beta', 'valor' => 80, 'faturavel' => false]);

        Livewire::actingAs($admin)->test(Editor::class, ['registo' => $registo])
            ->assertSet('matricula', 'AA-00-BB')
            ->assertSet('linhas.0.descricao', 'Estadia Beta')
            ->assertSet('linhas.0.valores.2', '80.00')  // Hotel = coluna 2
            ->set('linhas.0.valores.2', '95')
            ->call('guardar')
            ->assertHasNoErrors();

        $registo->refresh();
        $this->assertSame(1, $registo->despesas()->count());
        $this->assertDatabaseHas('despesas', ['registo_despesa_id' => $registo->id, 'categoria' => 'Hotel', 'valor' => 95.00]);
    }

    // Recibos: pendentes gravam-se com o registo; na edição removem-se. Asserções sobre
    // metadados (o upload fake do Livewire não aterra no disco que o Storage lê neste
    // ambiente — limitação conhecida, igual às fotos dos relatórios).
    public function test_recibos_gravam_com_o_registo_e_removem_se(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('linhas.0.data', now()->toDateString())
            ->set('linhas.0.descricao', 'Almoço ACME')
            ->set('linhas.0.valores.3', '14.20')
            ->set('linhas.0.refeicao_tipo', 'A')
            ->set('recibosUpload', [\Illuminate\Http\UploadedFile::fake()->image('recibo.jpg', 800, 600)])
            ->call('guardar')
            ->assertHasNoErrors();

        $registo = RegistoDespesa::firstOrFail();
        $recibo = $registo->anexos()->firstOrFail();
        $this->assertSame('recibo.jpg', $recibo->nome_ficheiro);

        Livewire::actingAs($admin)->test(Editor::class, ['registo' => $registo])
            ->assertViewHas('recibosGravados', fn ($r) => $r->count() === 1)
            ->call('removerReciboGravado', $recibo->id);

        $this->assertSame(0, $registo->anexos()->count());
        \Illuminate\Support\Facades\Storage::disk()->delete($recibo->storage_key); // limpeza best-effort
    }

    // PDF do registo: transferível, no layout da folha (logótipo + valores + A/J).
    public function test_pdf_do_registo_e_transferivel(): void
    {
        $admin = $this->admin();
        $registo = RegistoDespesa::create(['criado_por' => $admin->id, 'matricula' => 'BD-71-VI']);
        $registo->despesas()->create(['data' => '2026-08-03', 'categoria' => 'Refeições', 'descricao' => 'ACME - Porto', 'valor' => 12.5, 'refeicao_tipo' => 'A', 'faturavel' => false]);

        $html = view('pdf.registo-despesas', ['registo' => $registo])->render();
        $this->assertStringContainsString('Admin', $html);
        $this->assertStringContainsString('BD-71-VI', $html);
        $this->assertStringContainsString('ACME - Porto', $html);
        $this->assertStringContainsString('12,50', $html);
        $this->assertStringContainsString('(A)', $html); // A/J junto ao valor das refeições
        $this->assertStringContainsString('data:image/png;base64', $html); // logótipo embebido

        $resp = $this->actingAs($admin)->get(route('despesas.registo.pdf', $registo));
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $resp->getContent());
        $this->assertStringContainsString('attachment', $resp->headers->get('Content-Disposition')); // transferível
    }

    // Eliminar o registo elimina as linhas (soft delete).
    public function test_eliminar_registo_elimina_as_linhas(): void
    {
        $admin = $this->admin();
        $registo = RegistoDespesa::create(['criado_por' => $admin->id]);
        $registo->despesas()->create(['data' => now(), 'categoria' => 'Hotel', 'descricao' => 'X', 'valor' => 10, 'faturavel' => false]);

        Livewire::actingAs($admin)->test(Listagem::class)->call('eliminar', $registo->id);

        $this->assertSoftDeleted('registos_despesa', ['id' => $registo->id]);
        $this->assertSame(0, Despesa::count()); // soft deleted também
    }

    // Consolidação (migração): despesas do mesmo colaborador/data/descrição espalhadas por
    // vários registos (efeito do backfill) juntam-se num só; registos esvaziados desaparecem.
    public function test_consolidacao_junta_registos_do_mesmo_lancamento(): void
    {
        $admin = $this->admin();
        $r1 = RegistoDespesa::create(['criado_por' => $admin->id]);
        $r2 = RegistoDespesa::create(['criado_por' => $admin->id]);
        $r3 = RegistoDespesa::create(['criado_por' => $admin->id]);
        $r1->despesas()->create(['data' => '2026-08-04', 'categoria' => 'Combustíveis', 'descricao' => 'BNP', 'valor' => 10, 'faturavel' => false, 'criado_por' => $admin->id]);
        $r2->despesas()->create(['data' => '2026-08-04', 'categoria' => 'Hotel', 'descricao' => 'BNP', 'valor' => 120, 'faturavel' => false, 'criado_por' => $admin->id]);
        // Registo de OUTRA descrição não é tocado.
        $r3->despesas()->create(['data' => '2026-08-04', 'categoria' => 'Hotel', 'descricao' => 'Outro sítio', 'valor' => 30, 'faturavel' => false, 'criado_por' => $admin->id]);

        $migracao = require database_path('migrations/2026_08_05_000003_consolida_registos_despesa.php');
        $migracao->up();

        // BNP juntos no registo mais antigo; o esvaziado desapareceu; o "Outro sítio" intacto.
        $this->assertSame(2, $r1->despesas()->count());
        $this->assertDatabaseMissing('registos_despesa', ['id' => $r2->id]);
        $this->assertSame(1, $r3->despesas()->count());
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
