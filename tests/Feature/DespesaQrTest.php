<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Models\User;
use App\Services\Despesas\QrFatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

// QR code das faturas portuguesas (Portaria 195/2020): o telemóvel lê-o da fotografia do
// recibo e o servidor preenche o DIA e o VALOR da linha — só se estiverem vazios.
class DespesaQrTest extends TestCase
{
    use RefreshDatabase;

    // O QR de uma fatura verdadeira, tal como veio (21/09/2026, 79,00 €).
    private const EXEMPLO = 'A:516520741*B:509101143*C:PT*D:FR*E:N*F:20260921*G:FR COVILHA26/41625*H:J6M3CZ6D-41625*I1:PT*I7:64.23*I8:14.77*N:14.77*O:79.00*Q:SM2T*R:192';

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_le_a_fatura_do_exemplo(): void
    {
        $this->assertSame(
            ['data' => '2026-09-21', 'total' => '79.00', 'nif' => '516520741', 'serie' => 'FR COVILHA26', 'intermedia' => false],
            QrFatura::ler(self::EXEMPLO),
        );
    }

    // IVA à taxa intermédia (restauração) em qualquer das três regiões; série só com a forma certa.
    public function test_taxa_intermedia_e_serie(): void
    {
        $this->assertTrue(QrFatura::ler('A:514038942*F:20260923*G:FS 7072/1*I5:3.98*I6:0.52*O:4.50')['intermedia']);
        $this->assertTrue(QrFatura::ler('A:514038942*F:20260923*K5:3.98*K6:0.36*O:4.34')['intermedia']);
        $this->assertFalse(QrFatura::ler('A:514038942*F:20260923*I5:0.00*I6:0.00*O:4.50')['intermedia']);
        $this->assertNull(QrFatura::ler('A:514038942*F:20260923*O:4.50')['serie']);
        $this->assertNull(QrFatura::ler('A:514038942*F:20260923*G:SEM NUMERO*O:4.50')['serie']);
    }

    public function test_recusa_o_que_nao_e_uma_fatura(): void
    {
        foreach ([
            '',
            'https://www.exemplo.pt/promo',                 // QR de publicidade no talão
            'A:123*F:20260921*O:10.00',                     // NIF que não tem 9 dígitos
            'A:516520741*F:20260231*O:10.00',               // 31 de fevereiro
            'A:516520741*F:20260921*O:dez euros',           // total que não é número
            'A:516520741*F:20260921',                       // sem total
            'A:516520741*F:20260921*O:10.00*S:'.str_repeat('x', 2000), // grande demais para ser um
        ] as $texto) {
            $this->assertNull(QrFatura::ler($texto), "devia recusar: {$texto}");
        }
    }

    public function test_preenche_o_dia_e_o_valor_que_estao_vazios(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('lerQr', 0, self::EXEMPLO)
            ->assertSet('linhas.0.dia', '2026-09-21')
            ->assertSet('linhas.0.valor', '79.00')
            ->assertSet('qrLido.0.estado', 'lido')
            ->assertSee('QR do recibo: 21/09/2026 · 79,00 €')
            ->assertDontSee('diferente do que está na linha');
    }

    // Nunca apaga o que a pessoa já escreveu — mostra a diferença para ela decidir.
    public function test_nao_apaga_o_que_ja_esta_escrito(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('linhas.0.dia', '2026-09-20')
            ->set('linhas.0.valor', '70')
            ->call('lerQr', 0, self::EXEMPLO)
            ->assertSet('linhas.0.dia', '2026-09-20')
            ->assertSet('linhas.0.valor', '70')
            ->assertSee('diferente do que está na linha');
    }

    public function test_sem_qr_ou_qr_que_nao_e_fatura_nao_mexe_na_linha(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('lerQr', 0, '')
            ->assertSet('linhas.0.dia', '')
            ->assertSet('linhas.0.valor', '')
            ->assertSee('Sem QR code legível no recibo')
            ->call('lerQr', 0, 'https://www.exemplo.pt/promo')
            ->assertSet('linhas.0.valor', '')
            ->assertSee('não é de uma fatura portuguesa');
    }

    public function test_linha_que_nao_existe_e_ignorada(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('lerQr', 7, self::EXEMPLO)
            ->assertSet('qrLido', [])
            ->assertCount('linhas', 1);
    }

    // O que foi lido só o servidor o escreve — o browser não o pode forjar.
    public function test_o_que_foi_lido_nao_se_escreve_a_partir_do_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('qrLido', [0 => ['estado' => 'lido', 'data' => '2026-01-01', 'total' => '1.00']]);
    }

    // «Tirar foto» manda UMA foto (sem `multiple`), não uma lista. A validação exigia lista e
    // recusava-a com «The recibos linha upload.0 field must be an array» — o botão estava
    // partido desde 05/08. A galeria continua a mandar várias.
    public function test_tirar_foto_com_uma_so_foto_fica_como_recibo_da_linha(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('recibosLinhaUpload.0', UploadedFile::fake()->image('camara.jpg', 800, 600))
            ->assertHasNoErrors()
            ->assertCount('recibosPendentes.0', 1)
            ->set('recibosLinhaUpload.0', [UploadedFile::fake()->image('g1.jpg', 800, 600), UploadedFile::fake()->image('g2.jpg', 800, 600)])
            ->assertHasNoErrors()
            ->assertCount('recibosPendentes.0', 3);
    }

    // Uma foto única que não é imagem continua a ser recusada — a correção não abre a porta.
    // O HTML já nem passa o upload temporário do Livewire; o .txt passa esse (é o formato do
    // ficheiro do teste de descarga das baterias) e tem de ser a regra de imagem a apanhá-lo.
    public function test_foto_unica_que_nao_e_imagem_continua_recusada(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('recibosLinhaUpload.0', UploadedFile::fake()->create('pagina.html', 3, 'text/html'))
            ->assertHasErrors('recibosLinhaUpload.0')
            ->assertSet('recibosPendentes', []);

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('recibosLinhaUpload.0', UploadedFile::fake()->create('notas.txt', 1, 'text/plain'))
            ->assertHasErrors('recibosLinhaUpload.0.0')
            ->assertSet('recibosPendentes', []);
    }

    // Remover uma linha puxa as seguintes uma casa para trás, com os recibos e o QR delas.
    // Antes, um buraco (linha sem recibos antes de uma com recibos) desalinhava-os.
    public function test_remover_uma_linha_mantem_recibos_e_qr_na_linha_certa(): void
    {
        $editor = Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('adicionarLinha')
            ->call('adicionarLinha')
            ->set('recibosLinhaUpload.2', [UploadedFile::fake()->image('da-terceira.jpg', 800, 600)])
            ->call('lerQr', 2, self::EXEMPLO)
            ->call('removerLinha', 0);

        $this->assertCount(2, $editor->get('linhas'));
        $this->assertSame([1], array_keys($editor->get('recibosPendentes')), 'os recibos da antiga 3.ª linha estão agora na 2.ª');
        $this->assertSame('da-terceira.jpg', $editor->get('recibosPendentes')[1][0]->getClientOriginalName());
        $this->assertSame([1], array_keys($editor->get('qrLido')));
        $this->assertSame('2026-09-21', $editor->get('linhas.1.dia'));
    }
}
