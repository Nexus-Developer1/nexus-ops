<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Models\MemoriaFornecedor;
use App\Models\User;
use App\Services\Despesas\LeitorTalao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

// O TEXTO do talão (OCR no telemóvel) preenche o que o QR não traz: descrição (loja - terra),
// tipo e almoço/jantar. A memória de fornecedores lembra o que se confirmou da última vez.
class DespesaTalaoTest extends TestCase
{
    use RefreshDatabase;

    // O que o OCR do browser leu da fotografia inteira do talão (23/09/2026), tal e qual — com os
    // erros dele («TRMADONA», «GR CODE», o lixo do QR no fim). Sem as linhas do cartão.
    private const MERCADONA_OCR = <<<'TXT'
        MERCADONA
        R ENG. FREDERICO ULRICH, 3621
        MOREIRA
        TELEFONE: 221202924
        TRMADONA SUPERMERCADOS, UNIPESSOAL, LDA.
        AV. PADRE JORGE DUARTE 123
        4430-946 VILA NOVA DE GAIA - PORTO
        NIF: 514038942
        CAPITAL SOCIAL: 330.200.000 e
        Registro produtor - Embalagens: PTO1106524
        EEE: PTI01236, Baterias: PTOGO01394
        Copos de Plástico: PT10000001
        Descrição = P. Unit Valor (€)
        1 LS MACARRÃO, BOLONHES 4,50 C
        TOTAL 4,50
        Taxa Valor s/IVA Valor IVA Valor c/IVA
        L 13% 3,98 DUDA 4,50
        Terminal Pagamento Automático: 01542742
        26-09-23 13:11:14 Per097 Tri30 Msg770
        COMPRA 4,50 €
        Getnet
        GR CODE MB WAY
        PROCESSADO POR SIBS
        FATURA SIMPLIFICADA
        FS 70720232026001/12341]
        Data de emissão: 23-09-2026 13:11
        NIF: Consumidor final
        ATCUD: J6JFBAF9I- 12341]
        Elise)
        RUN Ne
        ABA Th rt a CAI
        Aga! -Processado por programa certifica
        Nº 2794 / AT o
        TXT;

    // O QR do mesmo talão (IVA só à taxa intermédia, 13 %).
    private const MERCADONA_QR = 'A:514038942*B:999999990*C:PT*D:FS*E:N*F:20260923*G:FS 70720232026001/123411*H:J6JFB6F9-123411*I1:PT*I5:3.98*I6:0.52*N:0.52*O:4.50*Q:AqQZ*R:2794';

    private const GALP_OCR = <<<'TXT'
        GALP
        Posto de Abastecimento Maia Norte
        EN 13 KM 5
        4470-123 MAIA
        Contribuinte: 504499777
        Gasoleo Simples 38,20 L 65,00
        Total 65,00
        Data: 22/09/2026 08:02
        TXT;

    private const GALP_QR = 'A:504499777*B:999999990*C:PT*D:FS*E:N*F:20260922*G:FS 0412/9981*H:ABCD1234-9981*I1:PT*I7:52.85*I8:12.15*N:12.15*O:65.00*Q:x1*R:1';

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    // ---- O que se tira do texto ----------------------------------------------------------

    public function test_talao_verdadeiro_da_mercadona(): void
    {
        // A terra é a da loja (Moreira), não a da sede que vem por baixo (Vila Nova de Gaia).
        $this->assertSame(
            ['descricao' => 'Mercadona - Moreira', 'categoria' => null, 'hora' => '13:11'],
            LeitorTalao::ler(self::MERCADONA_OCR),
        );
    }

    public function test_posto_de_combustivel(): void
    {
        $this->assertSame(
            ['descricao' => 'Galp - Maia', 'categoria' => 'Combustíveis', 'hora' => '08:02'],
            LeitorTalao::ler(self::GALP_OCR),
        );
    }

    public function test_restaurante_ao_jantar(): void
    {
        $lido = LeitorTalao::ler("RESTAURANTE O CANTINHO\nRua da Liberdade, 45\n3000-120 Coimbra\nNIF 123456789\nPrato do dia 9,50\n2 Imperial 3,00\n21/09/2026 20:45");

        $this->assertSame('Restaurante O Cantinho - Coimbra', $lido['descricao']);
        $this->assertSame('Refeições', $lido['categoria']);
        $this->assertSame('J', LeitorTalao::refeicao($lido['hora']));
    }

    public function test_tipos_pelas_palavras_do_talao(): void
    {
        $this->assertSame('Outros (veículos)', LeitorTalao::categoria("BRISA\nPortagem A1 Coimbra Norte 7,85"));
        $this->assertSame('Hotel', LeitorTalao::categoria("HOTEL D. LUIS\nAlojamento 1 noite 65,00\nTaxa turistica 2,00"));
        $this->assertSame('Táxi / Comboio / Avião', LeitorTalao::categoria("CP - COMBOIOS DE PORTUGAL\nIntercidades Lisboa-Porto"));
        // Posto que também vendeu um café: ganha o combustível.
        $this->assertSame('Combustíveis', LeitorTalao::categoria("REPSOL\nCafe 0,80\nGasolina 95 40,00"));
        $this->assertNull(LeitorTalao::categoria("LOJA X\nArtigo 1 2,00"));
    }

    public function test_texto_sem_nada_que_se_aproveite(): void
    {
        $this->assertSame(['descricao' => null, 'categoria' => null, 'hora' => null], LeitorTalao::ler("12 34\n-- ;;\n5,00"));
        $this->assertSame(['descricao' => null, 'categoria' => null, 'hora' => null], LeitorTalao::ler(''));
    }

    public function test_almoco_e_jantar_pela_hora(): void
    {
        $this->assertSame('A', LeitorTalao::refeicao('11:00'));
        $this->assertSame('A', LeitorTalao::refeicao('16:59'));
        $this->assertNull(LeitorTalao::refeicao('17:30'));
        $this->assertSame('J', LeitorTalao::refeicao('18:00'));
        $this->assertSame('J', LeitorTalao::refeicao('01:15'));
        $this->assertNull(LeitorTalao::refeicao('08:30')); // pequeno-almoço: não é A nem J
        $this->assertNull(LeitorTalao::refeicao(null));
    }

    // ---- No editor ------------------------------------------------------------------------

    public function test_foto_do_talao_preenche_a_linha_toda(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('lerQr', 0, self::MERCADONA_QR)
            ->assertSet('linhas.0.categoria', 'Refeições') // já pelo QR: IVA a 13 %
            ->call('lerTalao', 0, self::MERCADONA_OCR)
            ->assertSet('linhas.0.dia', '2026-09-23')
            ->assertSet('linhas.0.valor', '4.50')
            ->assertSet('linhas.0.descricao', 'Mercadona - Moreira')
            ->assertSet('linhas.0.categoria', 'Refeições')
            ->assertSet('linhas.0.refeicao_tipo', 'A')
            ->assertSet('linhas.0.pago_por', '') // à mão
            ->assertSee('Sugerido pelo recibo: descrição, tipo, almoço/jantar — confirme.');
    }

    public function test_nunca_troca_o_que_a_pessoa_escreveu(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('linhas.0.descricao', 'Almoço com cliente ACME')
            ->set('linhas.0.categoria', 'Outras despesas')
            ->call('lerQr', 0, self::MERCADONA_QR)
            ->call('lerTalao', 0, self::MERCADONA_OCR)
            ->assertSet('linhas.0.descricao', 'Almoço com cliente ACME')
            ->assertSet('linhas.0.categoria', 'Outras despesas')
            ->assertDontSee('Sugerido pelo recibo');
    }

    // O QR chega primeiro e sugere pela taxa de IVA; o texto, que chega depois, sabe mais e pode
    // trocar o que o QR pôs — mas não o que a pessoa entretanto mudou.
    public function test_o_texto_melhora_o_que_o_qr_sugeriu_mas_nao_o_que_a_pessoa_mudou(): void
    {
        $qrPostoComCafe = 'A:504499777*F:20260922*G:FS 0412/9981*I5:0.71*I6:0.09*O:65.80';

        Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('lerQr', 0, $qrPostoComCafe)
            ->assertSet('linhas.0.categoria', 'Refeições')
            ->call('lerTalao', 0, self::GALP_OCR)
            ->assertSet('linhas.0.categoria', 'Combustíveis');

        Livewire::actingAs(User::first())->test(Editor::class)
            ->call('lerQr', 0, $qrPostoComCafe)
            ->set('linhas.0.categoria', 'Hotel')
            ->call('lerTalao', 0, self::GALP_OCR)
            ->assertSet('linhas.0.categoria', 'Hotel');
    }

    public function test_sem_qr_o_texto_preenche_na_mesma(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('lerQr', 0, '')
            ->call('lerTalao', 0, self::GALP_OCR)
            ->assertSet('linhas.0.descricao', 'Galp - Maia')
            ->assertSet('linhas.0.categoria', 'Combustíveis')
            ->assertSet('linhas.0.dia', ''); // o dia só do QR
    }

    public function test_linha_que_nao_existe_e_texto_vazio_sao_ignorados(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('lerTalao', 5, self::GALP_OCR)
            ->call('lerTalao', 0, '   ')
            ->assertSet('talaoLido', [])
            ->assertSet('linhas.0.descricao', '');
    }

    public function test_o_que_o_recibo_sugeriu_nao_se_escreve_a_partir_do_browser(): void
    {
        $admin = $this->admin();

        foreach (['talaoLido' => [0 => ['descricao' => 'x', 'categoria' => null, 'hora' => null]], 'autoPreenchido' => [0 => ['descricao' => 'y']]] as $propriedade => $valor) {
            try {
                Livewire::actingAs($admin)->test(Editor::class)->set($propriedade, $valor);
                $this->fail("{$propriedade} devia estar trancada");
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_remover_uma_linha_leva_o_que_o_talao_deu_com_ela(): void
    {
        $editor = Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('adicionarLinha')
            ->call('lerTalao', 1, self::GALP_OCR)
            ->call('removerLinha', 0);

        $this->assertSame([0], array_keys($editor->get('talaoLido')));
        $this->assertSame([0], array_keys($editor->get('autoPreenchido')));
        $this->assertSame('Galp - Maia', $editor->get('linhas.0.descricao'));
    }

    // ---- Memória de fornecedores ----------------------------------------------------------

    private function gravarLinha(User $quem, string $qr, string $descricao, string $categoria): void
    {
        Livewire::actingAs($quem)->test(Editor::class)
            ->call('lerQr', 0, $qr)
            ->set('linhas.0.descricao', $descricao)
            ->set('linhas.0.categoria', $categoria)
            ->set('linhas.0.refeicao_tipo', 'A')
            ->set('linhas.0.pago_por', 'tecnico')
            ->set('recibosLinhaUpload.0', [UploadedFile::fake()->image('r.jpg', 800, 600)])
            ->call('guardar')
            ->assertHasNoErrors();
    }

    // O OCR leu «Mercadona - Moreira», a pessoa corrigiu e gravou: da próxima vez, o talão
    // desta loja vem com a correção — ganha ao OCR, porque foi uma pessoa que a confirmou.
    public function test_aprende_ao_gravar_e_sugere_da_vez_seguinte(): void
    {
        $admin = $this->admin();
        $this->gravarLinha($admin, self::MERCADONA_QR, 'Mercadona - Moreira da Maia', 'Refeições');

        $this->assertDatabaseHas('memoria_fornecedores', ['nif' => '514038942', 'serie' => 'FS 70720232026001', 'descricao' => 'Mercadona - Moreira da Maia']);

        Livewire::actingAs($admin)->test(Editor::class)
            ->call('lerQr', 0, self::MERCADONA_QR)
            ->assertSet('linhas.0.descricao', 'Mercadona - Moreira da Maia') // logo pelo QR, sem esperar pelo texto
            ->call('lerTalao', 0, self::MERCADONA_OCR)
            ->assertSet('linhas.0.descricao', 'Mercadona - Moreira da Maia');
    }

    // Numa cadeia (o mesmo NIF em várias lojas), a descrição de uma loja não serve para outra:
    // uma loja nova vai pelo texto do talão. O tipo do NIF continua a valer.
    public function test_cadeia_com_varias_lojas_nao_usa_a_descricao_de_outra_loja(): void
    {
        $admin = $this->admin();
        $this->gravarLinha($admin, self::MERCADONA_QR, 'Mercadona - Moreira', 'Refeições');
        $this->gravarLinha($admin, str_replace('70720232026001', '70990012026001', self::MERCADONA_QR), 'Mercadona - Braga', 'Refeições');

        $this->assertTrue(MemoriaFornecedor::where(['nif' => '514038942', 'serie' => ''])->first()->varias_lojas);

        $outraLoja = str_replace(['70720232026001', 'I5:3.98*I6:0.52'], ['71110012026001', 'I7:3.66*I8:0.84'], self::MERCADONA_QR);
        Livewire::actingAs($admin)->test(Editor::class)
            ->call('lerQr', 0, $outraLoja)
            ->assertSet('linhas.0.descricao', '')
            ->assertSet('linhas.0.categoria', 'Refeições') // do NIF, mesmo sem IVA a 13 %
            ->call('lerTalao', 0, str_replace('MOREIRA', 'AVEIRO', self::MERCADONA_OCR))
            ->assertSet('linhas.0.descricao', 'Mercadona - Aveiro');
    }

    // Corrigir a descrição da MESMA loja não a transforma numa cadeia.
    public function test_corrigir_a_mesma_loja_nao_conta_como_outra_loja(): void
    {
        $admin = $this->admin();
        $this->gravarLinha($admin, self::GALP_QR, 'Galp - Maia', 'Combustíveis');
        $this->gravarLinha($admin, self::GALP_QR, 'Galp Maia Norte', 'Combustíveis');

        $this->assertFalse(MemoriaFornecedor::where(['nif' => '504499777', 'serie' => ''])->first()->varias_lojas);
        $this->assertSame(
            ['descricao' => 'Galp Maia Norte', 'categoria' => 'Combustíveis'],
            MemoriaFornecedor::sugestao('504499777', 'outra série do ano seguinte'),
        );
    }

    // Só aprende de linhas cujo QR foi lido — sem QR não se sabe de que vendedor é.
    public function test_sem_qr_nao_aprende_nada(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('lerTalao', 0, self::GALP_OCR)
            ->set('linhas.0.dia', '2026-09-22')
            ->set('linhas.0.valor', '65')
            ->set('linhas.0.pago_por', 'tecnico')
            ->set('recibosLinhaUpload.0', [UploadedFile::fake()->image('r.jpg', 800, 600)])
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertSame(0, MemoriaFornecedor::count());
    }
}
