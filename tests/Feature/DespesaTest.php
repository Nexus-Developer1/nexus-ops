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

// Módulo de despesas: REGISTOS com linhas 1:1 — cada linha é uma despesa (dia escrito à
// mão, descrição, "o que é", tipo, valor) com os recibos anexados à própria linha. O
// registo aparece na listagem como UMA só entrada e tem PDF transferível.
class DespesaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    // #[Locked]: o registo em edição define-se só na rota — um payload forjado a apontar o
    // editor a OUTRO registo a meio da sessão é recusado (15.ª revisão de segurança).
    public function test_registo_em_edicao_e_trancado_ao_browser(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin())->test(\App\Livewire\Despesas\Editor::class)
            ->set('registoId', 999);
    }

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    // Registo multi-linha → UMA entrada; cada linha vira uma despesa com o seu tipo/detalhe.
    public function test_registo_multilinha_aparece_como_uma_so_entrada(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('matricula', 'BD-71-VI')
            ->set('linhas.0.dia', '2026-08-04')
            ->set('linhas.0.descricao', 'ACME - Porto')
            ->set('linhas.0.detalhe', 'Gasóleo A1')
            ->set('linhas.0.categoria', 'Combustíveis')
            ->set('linhas.0.valor', '20.50')
            ->call('adicionarLinha')
            ->set('linhas.1.dia', '2026-08-05')
            ->set('linhas.1.descricao', 'Beta - Lisboa')
            ->set('linhas.1.detalhe', 'Almoço com cliente')
            ->set('linhas.1.categoria', 'Refeições')
            ->set('linhas.1.refeicao_tipo', 'A')
            ->set('linhas.1.valor', '12')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('despesas'));

        $this->assertSame(1, RegistoDespesa::count());
        $registo = RegistoDespesa::firstOrFail();
        $this->assertSame('BD-71-VI', $registo->matricula);
        $this->assertSame(2, $registo->despesas()->count());
        $this->assertDatabaseHas('despesas', ['categoria' => 'Combustíveis', 'valor' => 20.50, 'detalhe' => 'Gasóleo A1', 'data' => '2026-08-04 00:00:00']);
        $this->assertDatabaseHas('despesas', ['categoria' => 'Refeições', 'valor' => 12.00, 'refeicao_tipo' => 'A', 'detalhe' => 'Almoço com cliente']);
        $this->assertSame(32.5, $registo->total());

        Livewire::actingAs($admin)->test(Listagem::class)
            ->assertViewHas('registos', fn ($p) => $p->total() === 1);
    }

    // Dia: calendário SEM pré-seleção — nasce vazio e é obrigatório.
    public function test_dia_nasce_vazio_e_e_obrigatorio(): void
    {
        $admin = $this->admin();

        // Linha nova sem dia pré-selecionado.
        Livewire::actingAs($admin)->test(Editor::class)
            ->assertSet('linhas.0.dia', '')
            // Sem escolher o dia → recusado.
            ->set('linhas.0.descricao', 'X')
            ->set('linhas.0.categoria', 'Hotel')
            ->set('linhas.0.valor', '10')
            ->call('guardar')
            ->assertHasErrors('linhas.0.dia');

        $this->assertSame(0, RegistoDespesa::count());
    }

    // Nota a) da folha: tipo Refeições exige A/J.
    public function test_refeicoes_exigem_a_ou_j(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('linhas.0.dia', now()->toDateString())
            ->set('linhas.0.descricao', 'Almoço ACME')
            ->set('linhas.0.categoria', 'Refeições')
            ->set('linhas.0.valor', '12')
            ->call('guardar')
            ->assertHasErrors('linhas.0.refeicao_tipo');

        $this->assertSame(0, RegistoDespesa::count());
    }

    // Linha com descrição mas sem valor é recusada; tudo em branco também.
    public function test_sem_valores_nao_grava(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('linhas.0.descricao', 'Vazio')
            ->call('guardar')
            ->assertHasErrors('linhas.0.valor');

        Livewire::actingAs($admin)->test(Editor::class)
            ->call('guardar')
            ->assertHasErrors('linhas');

        $this->assertSame(0, RegistoDespesa::count());
    }

    // Editar: as linhas pré-carregam; guardar atualiza a MESMA despesa (id preservado —
    // os recibos da linha sobrevivem) e apaga as linhas removidas.
    public function test_editar_atualiza_a_mesma_despesa(): void
    {
        $admin = $this->admin();
        $registo = RegistoDespesa::create(['criado_por' => $admin->id, 'matricula' => 'AA-00-BB']);
        $despesa = $registo->despesas()->create(['data' => '2026-08-03', 'categoria' => 'Hotel', 'descricao' => 'Estadia Beta', 'detalhe' => 'Hotel Mar', 'valor' => 80, 'faturavel' => false]);

        Livewire::actingAs($admin)->test(Editor::class, ['registo' => $registo])
            ->assertSet('linhas.0.dia', '2026-08-03')
            ->assertSet('linhas.0.detalhe', 'Hotel Mar')
            ->assertSet('linhas.0.categoria', 'Hotel')
            ->set('linhas.0.valor', '95')
            ->call('guardar')
            ->assertHasNoErrors();

        $registo->refresh();
        $this->assertSame(1, $registo->despesas()->count());
        $despesa->refresh();
        $this->assertSame('95.00', (string) $despesa->valor); // MESMA despesa, atualizada
    }

    // Recibos POR LINHA: pendentes gravam-se na despesa da linha; removem-se na edição.
    // Asserções sobre metadados (o upload fake do Livewire não aterra no disco que o
    // Storage lê neste ambiente — limitação conhecida, igual às fotos dos relatórios).
    public function test_recibos_anexam_a_linha_respetiva(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('linhas.0.dia', now()->toDateString())
            ->set('linhas.0.descricao', 'Almoço ACME')
            ->set('linhas.0.categoria', 'Refeições')
            ->set('linhas.0.refeicao_tipo', 'A')
            ->set('linhas.0.valor', '14.20')
            ->set('recibosLinhaUpload.0', [\Illuminate\Http\UploadedFile::fake()->image('recibo.jpg', 800, 600)])
            ->call('guardar')
            ->assertHasNoErrors();

        $despesa = Despesa::firstOrFail();
        $recibo = $despesa->anexos()->firstOrFail();
        $this->assertSame('recibo.jpg', $recibo->nome_ficheiro);

        Livewire::actingAs($admin)->test(Editor::class, ['registo' => $despesa->registo])
            ->call('removerReciboGravado', $recibo->id);

        $this->assertSame(0, $despesa->anexos()->count());
        \Illuminate\Support\Facades\Storage::disk()->delete($recibo->storage_key); // limpeza best-effort
    }

    // PDF do registo: transferível, no layout da folha (logótipo, detalhe e A/J).
    public function test_pdf_do_registo_e_transferivel(): void
    {
        $admin = $this->admin();
        $registo = RegistoDespesa::create(['criado_por' => $admin->id, 'matricula' => 'BD-71-VI']);
        $registo->despesas()->create(['data' => '2026-08-03', 'categoria' => 'Refeições', 'descricao' => 'ACME - Porto', 'detalhe' => 'Almoço com cliente', 'valor' => 12.5, 'refeicao_tipo' => 'A', 'faturavel' => false]);

        $html = view('pdf.registo-despesas', ['registo' => $registo])->render();
        $this->assertStringContainsString('BD-71-VI', $html);
        $this->assertStringContainsString('ACME - Porto — Almoço com cliente', $html);
        $this->assertStringContainsString('12,50', $html);
        $this->assertStringContainsString('(A)', $html);
        $this->assertStringContainsString('data:image/png;base64', $html);

        $resp = $this->actingAs($admin)->get(route('despesas.registo.pdf', $registo));
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $resp->getContent());
        $this->assertStringContainsString('attachment', $resp->headers->get('Content-Disposition'));
    }

    // Os recibos anexados às linhas saem no PDF (imagens embebidas em base64, agrupadas por
    // linha); um ficheiro em falta no storage é saltado sem rebentar a geração.
    public function test_pdf_inclui_os_recibos_das_linhas(): void
    {
        $admin = $this->admin();
        $registo = RegistoDespesa::create(['criado_por' => $admin->id]);
        $despesa = $registo->despesas()->create(['data' => '2026-08-04', 'categoria' => 'Hotel', 'descricao' => 'BNP - Lisboa', 'valor' => 120, 'faturavel' => false]);

        // Recibo real no storage + um com o ficheiro em falta (não pode rebentar).
        $chave = 'anexos/despesas/' . $despesa->id . '/recibo-teste.jpg';
        \Illuminate\Support\Facades\Storage::disk()->put($chave, 'conteudo-jpg-de-teste');
        $despesa->anexos()->create(['nome_ficheiro' => 'recibo.jpg', 'storage_key' => $chave, 'mime' => 'image/jpeg', 'tamanho' => 21, 'criado_por' => $admin->id]);
        $despesa->anexos()->create(['nome_ficheiro' => 'perdido.jpg', 'storage_key' => 'anexos/despesas/' . $despesa->id . '/nao-existe.jpg', 'mime' => 'image/jpeg', 'tamanho' => 1, 'criado_por' => $admin->id]);

        $html = view('pdf.registo-despesas', ['registo' => $registo])->render();

        $this->assertStringContainsString('Recibos', $html);
        $this->assertStringContainsString('BNP - Lisboa', $html);
        $this->assertStringContainsString(base64_encode('conteudo-jpg-de-teste'), $html); // imagem embebida

        // Sem recibos, a secção nem aparece.
        $registoVazio = RegistoDespesa::create(['criado_por' => $admin->id]);
        $registoVazio->despesas()->create(['data' => '2026-08-04', 'categoria' => 'Hotel', 'descricao' => 'Sem recibos', 'valor' => 10, 'faturavel' => false]);
        // (class="..." e não o nome solto — o seletor CSS vive sempre no <head>.)
        $this->assertStringNotContainsString('class="recibos-titulo"', view('pdf.registo-despesas', ['registo' => $registoVazio])->render());

        \Illuminate\Support\Facades\Storage::disk()->delete($chave); // limpeza best-effort
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
