<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Livewire\Despesas\Ficha;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Services\Despesas\PdfRegistoDespesas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

// Quem pagou cada despesa (set. 2026): Cartão Técnico, Financeiro ou Pago pelo técnico —
// obrigatório em cada linha, e mostrado a negrito na ficha e no PDF.
class DespesaPagoPorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    /** Editor com uma linha completa, menos o «pago por». */
    private function linhaPreenchida(User $quem)
    {
        return Livewire::actingAs($quem)->test(Editor::class)
            ->set('linhas.0.dia', '2026-09-20')
            ->set('linhas.0.descricao', 'ACME - Porto')
            ->set('linhas.0.categoria', 'Combustíveis')
            ->set('linhas.0.valor', '30')
            ->set('recibosLinhaUpload.0', [UploadedFile::fake()->image('r.jpg', 800, 600)]);
    }

    public function test_sem_indicar_quem_pagou_nao_grava(): void
    {
        $this->linhaPreenchida($this->admin())
            ->call('guardar')
            ->assertHasErrors('linhas.0.pago_por');

        $this->assertSame(0, RegistoDespesa::count());
    }

    public function test_grava_quem_pagou(): void
    {
        $this->linhaPreenchida($this->admin())
            ->set('linhas.0.pago_por', 'cartao_tecnico')
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('despesas', ['descricao' => 'ACME - Porto', 'pago_por' => 'cartao_tecnico']);
    }

    // O valor vem do browser: só as três chaves conhecidas entram.
    public function test_valor_forjado_e_recusado(): void
    {
        $this->linhaPreenchida($this->admin())
            ->set('linhas.0.pago_por', 'empresa_xpto')
            ->call('guardar')
            ->assertHasErrors('linhas.0.pago_por');

        $this->assertSame(0, RegistoDespesa::count());
    }

    public function test_ficha_e_pdf_mostram_quem_pagou_a_negrito(): void
    {
        $admin = $this->admin();
        $registo = RegistoDespesa::create(['criado_por' => $admin->id, 'estado' => 'pendente']);
        $registo->despesas()->create(['data' => '2026-09-20', 'categoria' => 'Hotel', 'descricao' => 'Hotel Mar', 'valor' => 80, 'faturavel' => false, 'pago_por' => 'tecnico']);

        Livewire::actingAs($admin)->test(Ficha::class, ['registo' => $registo])
            ->assertSeeHtml('font-bold text-texto-forte">Pago pelo técnico</td>');

        $html = app(PdfRegistoDespesas::class)->html($registo->fresh());
        $this->assertStringContainsString('<strong>Pago pelo técnico</strong>', $html);
    }

    // Linhas antigas (anteriores ao campo) não rebentam: a ficha mostra um traço.
    public function test_linha_antiga_sem_quem_pagou_mostra_traco(): void
    {
        $admin = $this->admin();
        $registo = RegistoDespesa::create(['criado_por' => $admin->id, 'estado' => 'pendente']);
        $registo->despesas()->create(['data' => '2026-09-01', 'categoria' => 'Hotel', 'descricao' => 'Antiga', 'valor' => 10, 'faturavel' => false]);

        Livewire::actingAs($admin)->test(Ficha::class, ['registo' => $registo])
            ->assertSeeHtml('font-bold text-texto-forte">—</td>');

        $this->assertStringNotContainsString('<strong>', app(PdfRegistoDespesas::class)->html($registo->fresh()));
    }
}
