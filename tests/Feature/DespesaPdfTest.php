<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Services\Despesas\PdfRegistoDespesas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// PDF de um registo de despesas em DUAS versões (set. 2026): a folha da empresa com os
// recibos atrás, e outro só com as digitalizações, uma por página e a ocupar a página toda.
class DespesaPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-10 10:00:00');
        Storage::fake();
    }

    // firstOrCreate: cada ensaio chama isto mais do que uma vez (guardar o registo e depois
    // abrir a ficha), e uma segunda conta com o mesmo email não passava.
    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@nexus.pt'],
            ['nome' => 'Admin', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true],
        );
    }

    /** Guarda um registo com `$recibos` linhas, cada uma com a sua digitalização. */
    private function registoCom(int $recibos, int $largura = 900, int $altura = 1600): RegistoDespesa
    {
        $componente = Livewire::actingAs($this->admin())->test(Editor::class);

        for ($i = 0; $i < $recibos; $i++) {
            if ($i > 0) {
                $componente->call('adicionarLinha');
            }
            $componente->set("linhas.$i.dia", '2026-08-0'.($i + 1))
                ->set("linhas.$i.descricao", 'Almoço '.($i + 1))
                ->set("linhas.$i.categoria", 'Refeições')
                ->set("linhas.$i.refeicao_tipo", 'A')
                ->set("linhas.$i.valor", '12.50')
                ->set("recibosLinhaUpload.$i", [UploadedFile::fake()->image('talao.jpg', $largura, $altura)]);
        }

        $componente->call('guardar')->assertHasNoErrors();

        return RegistoDespesa::latest('id')->firstOrFail();
    }

    /** Quantas páginas tem o PDF (conta os objetos de página do ficheiro). */
    private function paginas(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_o_pdf_completo_leva_a_folha_e_os_recibos_atras(): void
    {
        $registo = $this->registoCom(2);
        $pdf = app(PdfRegistoDespesas::class)->gerar($registo, PdfRegistoDespesas::COMPLETO);

        $this->assertStringStartsWith('%PDF-', $pdf);
        // Folha + um recibo por página.
        $this->assertSame(3, $this->paginas($pdf));
    }

    public function test_o_pdf_dos_recibos_leva_so_as_digitalizacoes(): void
    {
        $registo = $this->registoCom(2);
        $pdf = app(PdfRegistoDespesas::class)->gerar($registo, PdfRegistoDespesas::RECIBOS);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(2, $this->paginas($pdf));
    }

    // Um talão é mais alto do que largo: encosta-se à ALTURA da página, que é o que o faz
    // ocupar a folha toda. Antes iam quatro por linha, do tamanho de um selo.
    public function test_a_digitalizacao_ocupa_a_pagina(): void
    {
        $pdf = app(PdfRegistoDespesas::class);

        // A4 de pé, 8 mm de margem: 1062px de altura útil, menos 34 da legenda.
        $talao = $this->registoCom(1, largura: 900, altura: 1600);
        $this->assertStringContainsString(
            'height: 1028px; width: auto;',
            $pdf->html($talao, PdfRegistoDespesas::RECIBOS),
        );

        // Uma fotografia tirada ao comprido encosta-se à largura (733,2px = 194 mm).
        $deitado = $this->registoCom(1, largura: 1600, altura: 900);
        $this->assertStringContainsString(
            'width: 733.2px; height: auto;',
            $pdf->html($deitado, PdfRegistoDespesas::RECIBOS),
        );

        // No PDF completo a página é deitada, por isso a conta é a dessa página.
        $this->assertStringContainsString(
            'height: 663.7px; width: auto;',
            $pdf->html($talao, PdfRegistoDespesas::COMPLETO),
        );
    }

    public function test_sem_recibos_o_pdf_das_digitalizacoes_da_404(): void
    {
        $registo = $this->registoSemRecibos();

        $this->actingAs($this->admin())
            ->get(route('despesas.registo.pdf.recibos', $registo))
            ->assertNotFound();

        // O completo continua a sair (é a folha).
        $this->actingAs($this->admin())
            ->get(route('despesas.registo.pdf', $registo))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_as_duas_ligacoes_aparecem_na_ficha(): void
    {
        $registo = $this->registoCom(1);

        $this->actingAs($this->admin())
            ->get(route('despesas.registo.ficha', $registo))
            ->assertOk()
            ->assertSee('PDF completo')
            ->assertSee('PDF do recibo')
            ->assertSee(route('despesas.registo.pdf.recibos', $registo), escape: false);
    }

    private function registoSemRecibos(): RegistoDespesa
    {
        $registo = $this->registoCom(1);
        foreach ($registo->linhasOrdenadas() as $linha) {
            $linha->anexos()->delete();
        }

        return $registo->fresh();
    }
}
