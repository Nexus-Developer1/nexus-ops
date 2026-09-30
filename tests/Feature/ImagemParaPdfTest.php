<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Models\User;
use App\Rules\ImagemParaPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

// 27.ª revisão de segurança: imagens que vão para PDF — fora do JPEG (que o gerador mete tal
// como está), no máximo 25 megapíxeis; um PNG enorme rebentava a geração do PDF.
class ImagemParaPdfTest extends TestCase
{
    use RefreshDatabase;

    private function passa(UploadedFile $f): bool
    {
        return Validator::make(['f' => $f], ['f' => [new ImagemParaPdf]])->passes();
    }

    public function test_png_acima_de_25_megapixeis_e_recusado_e_o_jpeg_nao(): void
    {
        $this->assertFalse($this->passa(UploadedFile::fake()->image('bomba.png', 6000, 5000))); // 30 MP
        $this->assertTrue($this->passa(UploadedFile::fake()->image('foto.jpg', 6000, 5000)));   // JPEG: passa
        $this->assertTrue($this->passa(UploadedFile::fake()->image('ecra.png', 1920, 1080)));   // captura de ecrã
    }

    public function test_o_recibo_das_despesas_aplica_a_regra(): void
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        Livewire::actingAs($tecnico)->test(Editor::class)
            ->set('recibosLinhaUpload.0', [UploadedFile::fake()->image('bomba.png', 6000, 5000)])
            ->assertHasErrors('recibosLinhaUpload.0.0')
            ->assertSet('recibosPendentes', []);
    }
}
