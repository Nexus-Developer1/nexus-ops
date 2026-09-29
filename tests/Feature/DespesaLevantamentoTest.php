<?php

namespace Tests\Feature;

use App\Enums\EstadoDespesa;
use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Livewire\Despesas\Ficha;
use App\Models\Anexo;
use App\Models\Despesa;
use App\Models\LevantamentoDespesa;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Services\Despesas\PdfRegistoDespesas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Levantamentos de dinheiro do cartão do técnico (set. 2026), dentro do registo de despesas:
// dia, valor e o talão do multibanco (obrigatório). O registo faz as contas: levantado, gasto em
// dinheiro (linhas «Dinheiro levantado») e o que sobra (a devolver).
class DespesaLevantamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 10:00:00');
        Storage::fake();
    }

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private function talao(): UploadedFile
    {
        return UploadedFile::fake()->image('talao.jpg', 600, 900);
    }

    // Editor com uma linha paga com dinheiro levantado e um levantamento de 100 €.
    private function editorComLevantamento(User $quem)
    {
        return Livewire::actingAs($quem)->test(Editor::class)
            ->set('linhas.0.dia', '2026-09-22')
            ->set('linhas.0.descricao', 'BNP PARIBAS, S.A.')
            ->set('linhas.0.categoria', 'Refeições')
            ->set('linhas.0.refeicao_tipo', 'A')
            ->set('linhas.0.pago_por', 'cartao_tecnico')
            ->set('linhas.0.cartao_forma', 'dinheiro')
            ->set('linhas.0.valor', '12.50')
            ->set('recibosLinhaUpload.0', [UploadedFile::fake()->image('r.jpg', 800, 600)])
            ->set('levantamentos.0.dia', '2026-09-22') // o levantamento nasceu com o «Dinheiro levantado»
            ->set('levantamentos.0.valor', '100');
    }

    public function test_grava_o_levantamento_com_o_talao_e_faz_as_contas(): void
    {
        $tecnico = $this->tecnico();

        $this->editorComLevantamento($tecnico)
            ->assertSee('Sobra (a devolver)')               // contas ao vivo no editor
            ->set('talaoLevantamentoUpload.0', [$this->talao()])
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('despesas'));

        $registo = RegistoDespesa::firstOrFail();
        $levantamento = $registo->levantamentos()->firstOrFail();
        $this->assertSame('2026-09-22', $levantamento->data->toDateString());
        $this->assertSame(100.0, (float) $levantamento->valor);
        $this->assertSame(1, $levantamento->anexos()->count());
        Storage::assertExists($levantamento->anexos()->first()->storage_key);

        $this->assertSame(['levantado' => 100.0, 'gasto' => 12.5, 'saldo' => 87.5], $registo->contasDoDinheiro());
        // O levantamento não é uma despesa: o total do registo não muda.
        $this->assertSame(12.5, $registo->total());

        Livewire::actingAs($tecnico)->test(Ficha::class, ['registo' => $registo])
            ->assertSee('Levantamentos do cartão')
            ->assertSee('Sobra (a devolver)')
            ->assertSee('87,50 €');
    }

    // «Pago por» tem só 3 opções; com o Cartão Técnico aparece «Multibanco / Dinheiro levantado»
    // (nasce Multibanco). A secção dos levantamentos só aparece com Dinheiro levantado, já com um
    // levantamento por preencher, e esconde-se se se mudar de ideias antes de escrever nele.
    public function test_cartao_tecnico_multibanco_ou_dinheiro_levantado(): void
    {
        Livewire::actingAs($this->tecnico())->test(Editor::class)
            ->assertDontSee('Dinheiro levantado')
            ->assertDontSee('Levantamentos do cartão')
            ->set('linhas.0.pago_por', 'cartao_tecnico')
            ->assertSet('linhas.0.cartao_forma', 'multibanco')
            ->assertSee('Dinheiro levantado')                 // a escolha aparece
            ->assertDontSee('Levantamentos do cartão')
            ->set('linhas.0.cartao_forma', 'dinheiro')
            ->assertSee('Levantamentos do cartão')
            ->assertCount('levantamentos', 1)
            ->set('linhas.0.pago_por', 'tecnico')
            ->assertSet('linhas.0.cartao_forma', '')
            ->assertDontSee('Levantamentos do cartão')
            ->set('linhas.0.pago_por', 'cartao_tecnico')
            ->set('linhas.0.cartao_forma', 'dinheiro')
            ->assertCount('levantamentos', 1)             // não duplica
            ->set('levantamentos.0.valor', '50')
            ->set('linhas.0.cartao_forma', 'multibanco')
            ->assertSee('Levantamentos do cartão');        // com dados, não se esconde
    }

    // Grava-se como antes (multibanco = «cartao_tecnico», dinheiro = «dinheiro_levantado») e, ao
    // editar, volta a aparecer como Cartão Técnico + a forma certa.
    public function test_grava_e_reabre_a_forma_do_cartao(): void
    {
        $tecnico = $this->tecnico();
        $this->editorComLevantamento($tecnico)
            ->call('adicionarLinha')
            ->set('linhas.1.dia', '2026-09-23')
            ->set('linhas.1.descricao', 'BNP PARIBAS, S.A.')
            ->set('linhas.1.categoria', 'Combustíveis')
            ->set('linhas.1.pago_por', 'cartao_tecnico')      // fica Multibanco
            ->set('linhas.1.valor', '40')
            ->set('recibosLinhaUpload.1', [UploadedFile::fake()->image('r2.jpg', 800, 600)])
            ->set('talaoLevantamentoUpload.0', [$this->talao()])
            ->call('guardar')
            ->assertHasNoErrors();

        $registo = RegistoDespesa::firstOrFail();
        $this->assertEqualsCanonicalizing([Despesa::DINHEIRO_LEVANTADO, 'cartao_tecnico'], $registo->despesas()->pluck('pago_por')->all());
        $this->assertSame(12.5, $registo->contasDoDinheiro()['gasto']); // o multibanco não conta como dinheiro

        Livewire::actingAs($tecnico)->test(Editor::class, ['registo' => $registo])
            ->assertSet('linhas.0.pago_por', 'cartao_tecnico')
            ->assertSet('linhas.0.cartao_forma', 'dinheiro')
            ->assertSet('linhas.1.pago_por', 'cartao_tecnico')
            ->assertSet('linhas.1.cartao_forma', 'multibanco');

        Livewire::actingAs($tecnico)->test(Ficha::class, ['registo' => $registo])
            ->assertSee('Cartão Técnico — dinheiro levantado');
    }

    // Já não se pode mandar «dinheiro_levantado» direto no «Pago por» (não é uma opção).
    public function test_dinheiro_levantado_nao_e_opcao_do_pago_por(): void
    {
        $this->editorComLevantamento($this->tecnico())
            ->set('linhas.0.pago_por', Despesa::DINHEIRO_LEVANTADO)
            ->set('talaoLevantamentoUpload.0', [$this->talao()])
            ->call('guardar')
            ->assertHasErrors('linhas.0.pago_por');
    }

    public function test_o_talao_do_multibanco_e_obrigatorio(): void
    {
        $this->editorComLevantamento($this->tecnico())
            ->call('guardar')
            ->assertHasErrors('levantamentos.0.talao');

        $this->assertSame(0, RegistoDespesa::count());
    }

    public function test_dia_e_valor_obrigatorios_e_levantamento_em_branco_ignorado(): void
    {
        $tecnico = $this->tecnico();

        $this->editorComLevantamento($tecnico)
            ->set('levantamentos.0.valor', '')
            ->set('talaoLevantamentoUpload.0', [$this->talao()])
            ->call('guardar')
            ->assertHasErrors('levantamentos.0.valor');

        // Um levantamento acrescentado e deixado em branco não impede de gravar.
        $this->editorComLevantamento($tecnico)
            ->set('talaoLevantamentoUpload.0', [$this->talao()])
            ->call('adicionarLevantamento')
            ->call('guardar')
            ->assertHasNoErrors();
        $this->assertSame(1, LevantamentoDespesa::count());
    }

    public function test_gastar_mais_do_que_o_levantado_avisa(): void
    {
        $this->editorComLevantamento($this->tecnico())
            ->set('linhas.0.valor', '130')
            ->assertSee('Gasto a mais')
            ->assertSee('30,00 €');
    }

    // Editar: o levantamento gravado volta a aparecer com o talão; remover a linha apaga o
    // levantamento e o ficheiro do talão ao guardar.
    public function test_editar_e_remover_um_levantamento(): void
    {
        $tecnico = $this->tecnico();
        $this->editorComLevantamento($tecnico)
            ->set('talaoLevantamentoUpload.0', [$this->talao()])
            ->call('guardar')
            ->assertHasNoErrors();
        $registo = RegistoDespesa::firstOrFail();
        $chave = Anexo::where('anexavel_type', LevantamentoDespesa::class)->value('storage_key');

        Livewire::actingAs($tecnico)->test(Editor::class, ['registo' => $registo])
            ->assertSet('levantamentos.0.valor', '100.00')
            ->call('guardar')                     // já tem o talão gravado — não pede outro
            ->assertHasNoErrors();

        Livewire::actingAs($tecnico)->test(Editor::class, ['registo' => $registo])
            ->call('removerLevantamento', 0)
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertSame(0, LevantamentoDespesa::count());
        $this->assertSame(0, Anexo::where('anexavel_type', LevantamentoDespesa::class)->count());
        Storage::assertMissing($chave);
    }

    // O id do levantamento vem do browser: um id de OUTRO registo não é tocado (cria-se um novo).
    public function test_levantamento_de_outro_registo_nao_e_alterado(): void
    {
        $tecnico = $this->tecnico();
        $outro = RegistoDespesa::create(['criado_por' => $tecnico->id]);
        $alheio = $outro->levantamentos()->create(['data' => '2026-09-01', 'valor' => 50]);

        $this->editorComLevantamento($tecnico)
            ->set('levantamentos.0.levantamento_id', $alheio->id)
            ->set('talaoLevantamentoUpload.0', [$this->talao()])
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertSame(50.0, (float) $alheio->fresh()->valor);
        $this->assertSame('2026-09-01', $alheio->fresh()->data->toDateString());
    }

    public function test_aprovada_nao_deixa_remover_o_talao(): void
    {
        $tecnico = $this->tecnico();
        $this->editorComLevantamento($tecnico)
            ->set('talaoLevantamentoUpload.0', [$this->talao()])
            ->call('guardar');
        $registo = RegistoDespesa::firstOrFail();
        $editor = Livewire::actingAs($tecnico)->test(Editor::class, ['registo' => $registo]);
        $registo->update(['estado' => EstadoDespesa::Aprovada]);

        $editor->call('removerTalaoGravado', Anexo::where('anexavel_type', LevantamentoDespesa::class)->value('id'))
            ->assertForbidden();
        $this->assertSame(1, Anexo::where('anexavel_type', LevantamentoDespesa::class)->count());
    }

    public function test_o_talao_abre_pela_rota_dos_recibos_e_vai_no_pdf_completo(): void
    {
        $tecnico = $this->tecnico();
        $this->editorComLevantamento($tecnico)
            ->set('talaoLevantamentoUpload.0', [$this->talao()])
            ->call('guardar');
        $registo = RegistoDespesa::firstOrFail();
        $talao = Anexo::where('anexavel_type', LevantamentoDespesa::class)->firstOrFail();

        $this->actingAs($tecnico)->get(route('despesas.recibos.ver', $talao))->assertOk();

        $pdf = app(PdfRegistoDespesas::class);
        $html = $pdf->html($registo);
        $this->assertStringContainsString('Dinheiro levantado do cartão', $html);
        $this->assertStringContainsString('Talão do multibanco', $html);
        $this->assertStringContainsString('87,50 €', $html);
        // O PDF só dos recibos (para a faturação) não leva o talão do multibanco.
        $this->assertStringNotContainsString('Talão do multibanco', $pdf->html($registo, PdfRegistoDespesas::RECIBOS));
    }
}
