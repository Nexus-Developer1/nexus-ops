<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Models\Anexo;
use App\Models\Despesa;
use App\Models\RegistoDespesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// 22.ª revisão de segurança — recibos das despesas como vetor de stored XSS. O caminho era:
// (1) o upload temporário do Livewire aceitava qualquer ficheiro; (2) $recibosPendentes é
// propriedade pública, logo um HTML já carregado podia ser posto lá sem passar pelo botão
// que valida «é imagem»; (3) guardar() gravava sem revalidar; (4) /anexos/{id} servia-o
// inline como text/html — o script corria na sessão de quem abrisse o recibo (o aprovador).
// Três camadas fecham-no: revalidar ao guardar, upload temporário só de tipos usados, e a
// rota só abre imagens/PDF no browser (tudo o resto vai como download opaco).
class RecibosSegurosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-10 10:00:00');
        Storage::fake();
    }

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    // Camada 1 (revalidação ao guardar), testada com a camada 2 desligada: sem o filtro do
    // upload temporário o HTML chega aos pendentes — e guardar() tem de o recusar na mesma.
    public function test_guardar_recusa_recibo_pendente_que_nao_seja_imagem(): void
    {
        config(['livewire.temporary_file_upload.rules' => ['required', 'file']]);
        $html = UploadedFile::fake()->createWithContent('recibo.html', '<script>alert(document.cookie)</script>');

        Livewire::actingAs($this->tecnico())->test(Editor::class)
            ->set('linhas.0.dia', '2026-08-04')
            ->set('linhas.0.descricao', 'ACME - Porto')
            ->set('linhas.0.categoria', 'Combustíveis')
            ->set('linhas.0.valor', '20.50')
            // Salta o botão «adicionar recibo» e põe o ficheiro diretamente nos pendentes.
            ->set('recibosPendentes.0', [$html])
            ->assertCount('recibosPendentes.0', 1) // chegou lá (camada 2 desligada)
            ->call('guardar')
            ->assertHasErrors(['recibosPendentes.0.0']);

        $this->assertSame(0, Despesa::count());
        $this->assertSame(0, Anexo::count());
    }

    // Camada 2: com a configuração real, o upload temporário nem aceita o HTML — o ficheiro
    // nunca chega aos pendentes.
    public function test_upload_temporario_nem_aceita_o_html(): void
    {
        $html = UploadedFile::fake()->createWithContent('recibo.html', '<script>alert(1)</script>');

        Livewire::actingAs($this->tecnico())->test(Editor::class)
            ->set('recibosPendentes.0', [$html])
            ->assertCount('recibosPendentes', 0);
    }

    public function test_guardar_continua_a_aceitar_imagens(): void
    {
        Livewire::actingAs($this->tecnico())->test(Editor::class)
            ->set('linhas.0.dia', '2026-08-04')
            ->set('linhas.0.descricao', 'ACME - Porto')
            ->set('linhas.0.categoria', 'Combustíveis')
            ->set('linhas.0.valor', '20.50')
            ->set('recibosPendentes.0', [UploadedFile::fake()->image('r.jpg', 800, 600)])
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertSame(1, Anexo::count());
    }

    public function test_upload_temporario_so_aceita_os_tipos_usados(): void
    {
        $regras = implode('|', config('livewire.temporary_file_upload.rules'));

        $this->assertStringContainsString('mimes:', $regras);
        foreach (['jpg', 'png', 'webp', 'txt', 'csv', 'log'] as $ext) {
            $this->assertMatchesRegularExpression('/mimes:[^|]*\b'.$ext.'\b/', $regras);
        }
        $this->assertDoesNotMatchRegularExpression('/mimes:[^|]*\b(html|svg|php|js)\b/', $regras);
    }

    // Um anexo que, por qualquer via, tenha ficado com mime HTML/SVG nunca é aberto no
    // browser: vai como download opaco. Imagens e PDF continuam a abrir inline.
    public function test_rota_de_anexos_so_abre_imagens_e_pdf_no_browser(): void
    {
        $tecnico = $this->tecnico();
        $registo = RegistoDespesa::create(['data' => '2026-08-04', 'criado_por' => $tecnico->id]);
        $despesa = $registo->despesas()->create(['data' => '2026-08-04', 'descricao' => 'x', 'categoria' => 'Combustíveis', 'valor' => 1, 'faturavel' => false, 'criado_por' => $tecnico->id]);

        $anexo = function (string $nome, string $mime, string $conteudo) use ($despesa): Anexo {
            Storage::disk()->put("anexos/despesas/{$despesa->id}/$nome", $conteudo);

            return $despesa->anexos()->create(['nome_ficheiro' => $nome, 'storage_key' => "anexos/despesas/{$despesa->id}/$nome", 'mime' => $mime, 'tamanho' => strlen($conteudo)]);
        };

        $html = $anexo('mau.html', 'text/html', '<script>alert(1)</script>');
        $svg = $anexo('mau.svg', 'image/svg+xml', '<svg onload="alert(1)"/>');
        $jpg = $anexo('bom.jpg', 'image/jpeg', 'xx');
        $pdf = $anexo('bom.pdf', 'application/pdf', '%PDF');

        foreach ([$html, $svg] as $perigoso) {
            $this->actingAs($tecnico)->get(route('anexos.ver', $perigoso))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/octet-stream')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Disposition', 'attachment; filename="'.$perigoso->nome_ficheiro.'"');
        }

        $this->actingAs($tecnico)->get(route('anexos.ver', $jpg))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Content-Disposition', 'inline; filename="bom.jpg"');

        $this->actingAs($tecnico)->get(route('anexos.ver', $pdf))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="bom.pdf"');
    }
}
