<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Caderno;
use App\Livewire\Clientes\Detalhe;
use App\Models\Anexo;
use App\Models\CadernoPagina;
use App\Models\CadernoSeparador;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use App\Services\Caderno\LimpezaHtmlCaderno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// CADERNO do cliente (out. 2026) — o OneNote da equipa dentro da aplicação: separadores (um por
// cliente final) → páginas com texto rico e imagens. Só a equipa; HTML sempre limpo no servidor;
// imagens como anexos; ids vindos do browser confirmados contra o cliente; duas pessoas na mesma
// página não se apagam uma à outra.
class CadernoClienteTest extends TestCase
{
    use RefreshDatabase;

    private User $rui;

    private Cliente $bbs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rui = User::create(['nome' => 'Rui Pereira', 'email' => 'rpereira@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->bbs = Cliente::create(['nome' => 'BBS - Cabling', 'ativo' => true]);
    }

    private function caderno(?Cliente $cliente = null)
    {
        return Livewire::actingAs($this->rui)->test(Caderno::class, ['cliente' => $cliente ?? $this->bbs]);
    }

    public function test_cria_separadores_e_paginas_como_no_onenote(): void
    {
        $c = $this->caderno()
            ->assertSee('Ainda não há separadores.')
            ->set('novoSeparador', 'Graphicleader')->call('criarSeparador')
            ->assertSee('Graphicleader')
            ->call('criarSeparador', 'OOCL');

        $graphic = CadernoSeparador::where('nome', 'Graphicleader')->firstOrFail();
        $this->assertSame($this->bbs->id, $graphic->cliente_id);
        $this->assertNotSame($graphic->cor, CadernoSeparador::where('nome', 'OOCL')->value('cor')); // cores em rotação

        $c->call('selecionarSeparador', $graphic->id)
            ->call('criarPagina')
            ->set('titulo', 'Equipamento 1')
            ->call('criarPagina')
            ->set('titulo', 'Dados CCTV');

        $this->assertSame(['Equipamento 1', 'Dados CCTV'], $graphic->paginas()->pluck('titulo')->all());
    }

    public function test_conteudo_grava_limpo_e_sobe_a_versao(): void
    {
        $p = CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'SPI'])
            ->paginas()->create(['titulo' => 'UPS S3T 20']);

        $html = '<div>P/N <strong>DS3TK20BA000RUA</strong></div>'
            .'<script>alert(1)</script>'
            .'<img src="x" onerror="alert(2)">'
            .'<img src="https://espiao.example/pixel.gif">'
            .'<a href="javascript:alert(3)">mau</a>'
            .'<a href="https://riello-ups.com">manual</a>'
            .'<figure><img src="/anexos/12" width="800" height="600" onload="x()"><figcaption>chapa</figcaption></figure>';

        $r = $this->caderno()->call('guardarConteudo', $p->id, $html, 0)->get('pagina'); // (sem retorno útil no get)
        $p->refresh();

        $this->assertSame(1, $p->versao);
        $this->assertStringContainsString('<strong>DS3TK20BA000RUA</strong>', $p->conteudo);
        $this->assertStringContainsString('src="/anexos/12"', $p->conteudo);                 // imagem da app fica
        $this->assertStringContainsString('href="https://riello-ups.com"', $p->conteudo);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $p->conteudo);
        foreach (['<script', 'onerror', 'onload', 'javascript:', 'espiao.example', 'src="x"'] as $mau) {
            $this->assertStringNotContainsString($mau, $p->conteudo, "Ficou {$mau} no HTML.");
        }
    }

    public function test_duas_pessoas_na_mesma_pagina_nao_se_apagam(): void
    {
        $daniel = User::create(['nome' => 'Daniel Ribeiro', 'email' => 'd@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $p = CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'SPI'])->paginas()->create(['titulo' => 'CCTV']);

        // O Daniel grava primeiro (versão 0 → 1).
        Livewire::actingAs($daniel)->test(Caderno::class, ['cliente' => $this->bbs])
            ->call('guardarConteudo', $p->id, '<div>IP 192.168.1.10</div>', 0);

        // O Rui ainda tinha a versão 0 aberta: não escreve por cima e é avisado.
        $this->caderno()->call('guardarConteudo', $p->id, '<div>texto antigo</div>', 0)
            ->assertReturned(fn ($r) => $r['ok'] === false && str_contains($r['motivo'], 'Daniel Ribeiro'));

        $this->assertStringContainsString('192.168.1.10', $p->fresh()->conteudo);
        $this->assertSame(1, $p->fresh()->versao);
    }

    public function test_imagem_colada_vai_para_os_anexos_e_nao_para_a_bd(): void
    {
        Storage::fake();
        $p = CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'SPI'])->paginas()->create(['titulo' => 'Chapa']);

        $this->caderno()
            ->set('imagem', UploadedFile::fake()->image('chapa.jpg', 1200, 800))
            ->call('guardarImagem', $p->id)
            ->assertReturned(fn ($r) => $r['ok'] === true && preg_match('#^/anexos/\d+$#', $r['url']));

        $anexo = Anexo::firstOrFail();
        $this->assertSame($p->getMorphClass(), $anexo->anexavel_type);
        $this->assertSame($p->id, $anexo->anexavel_id);
        $this->assertStringStartsWith('anexos/caderno/'.$p->id.'/', $anexo->storage_key);
        Storage::disk()->assertExists($anexo->storage_key);
    }

    public function test_ficheiro_que_nao_e_imagem_e_recusado(): void
    {
        Storage::fake();
        $p = CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'SPI'])->paginas()->create(['titulo' => 'X']);

        $this->caderno()
            ->set('imagem', UploadedFile::fake()->create('virus.html', 10, 'text/html'))
            ->call('guardarImagem', $p->id)
            ->assertHasErrors('imagem');

        $this->assertSame(0, Anexo::count());
    }

    public function test_ids_de_outro_cliente_sao_ignorados(): void
    {
        $outro = Cliente::create(['nome' => 'Outro', 'ativo' => true]);
        $alheio = CadernoSeparador::create(['cliente_id' => $outro->id, 'nome' => 'Segredo']);
        $paginaAlheia = $alheio->paginas()->create(['titulo' => 'Passwords', 'conteudo' => '<div>admin/1234</div>']);

        // Pelo caderno da BBS não se lê, não se grava, não se apaga nada do outro cliente.
        $this->caderno()
            ->call('selecionarPagina', $paginaAlheia->id)->assertSet('paginaId', null)
            ->call('guardarConteudo', $paginaAlheia->id, '<div>x</div>', 0)
            ->assertReturned(fn ($r) => $r['ok'] === false)
            ->call('apagarSeparador', $alheio->id)
            ->call('renomearSeparador', $alheio->id, 'Mudado')
            ->assertDontSee('admin/1234');

        $this->assertSame('<div>admin/1234</div>', $paginaAlheia->fresh()->conteudo);
        $this->assertSame('Segredo', $alheio->fresh()->nome);
        $this->assertNull($alheio->fresh()->deleted_at);
    }

    public function test_so_a_equipa_entra_nunca_o_portal(): void
    {
        $cliente = User::create(['nome' => 'Cliente', 'email' => 'c@bbs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Cliente, 'ativo' => true, 'cliente_id' => $this->bbs->id]);

        CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'Segredo'])
            ->paginas()->create(['titulo' => 'Passwords CCTV', 'conteudo' => '<div>admin/1234</div>']);

        // As rotas da equipa mandam embora quem não é da equipa (redirecionam para o portal).
        $resposta = $this->actingAs($cliente)->get(route('clientes.caderno', $this->bbs));
        $this->assertContains($resposta->status(), [302, 403]);
        $resposta->assertDontSee('admin/1234');

        $this->actingAs($this->rui)->get(route('clientes.caderno', $this->bbs))->assertOk()->assertSee('Segredo');
    }

    public function test_pesquisa_encontra_pelo_titulo_e_pelo_texto(): void
    {
        $s = CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'Graphicleader']);
        $s->paginas()->create(['titulo' => 'UPS S3T 20', 'conteudo' => '<div>S/N AC38UT887690001</div>']);
        $s->paginas()->create(['titulo' => 'Dados CCTV', 'conteudo' => '<div>NVR na sala técnica</div>']);

        $this->caderno()
            ->set('pesquisa', 'AC38UT')->assertSee('UPS S3T 20')->assertDontSee('Dados CCTV')
            ->set('pesquisa', 'cctv')->assertSee('Dados CCTV');
    }

    public function test_sugere_os_clientes_finais_dos_equipamentos(): void
    {
        $local = Local::create(['cliente_id' => $this->bbs->id, 'designacao' => 'Sede']);
        foreach (['Graphicleader', 'OOCL', 'OOCL'] as $k => $final) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-'.$k, 'cliente_final' => $final]);
        }
        CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'graphicleader']); // já existe (outra caixa)

        $this->caderno()
            ->assertSee('+ OOCL')->assertDontSee('+ Graphicleader')
            ->call('criarSeparador', 'OOCL')
            ->assertDontSee('+ OOCL');
    }

    public function test_apagar_separador_leva_as_paginas_e_fica_na_auditoria(): void
    {
        $s = CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'SPI']);
        $s->paginas()->create(['titulo' => 'A']);
        $s->paginas()->create(['titulo' => 'B']);

        $this->caderno()->call('apagarSeparador', $s->id);

        $this->assertSoftDeleted($s);
        $this->assertSame(0, CadernoPagina::count());               // apagadas (soft) também
        $this->assertSame(2, CadernoPagina::withTrashed()->count());
        $this->assertDatabaseHas('auditoria', ['acao' => 'caderno_separador_apagado']);
    }

    public function test_ficha_do_cliente_mostra_o_caderno(): void
    {
        $s = CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'Graphicleader']);
        $s->paginas()->create(['titulo' => 'A']);

        Livewire::actingAs($this->rui)->test(Detalhe::class, ['cliente' => $this->bbs])
            ->assertSee('Caderno')->assertSee('Graphicleader')->assertSee('Abrir caderno');
    }

    public function test_texto_simples_para_pesquisa(): void
    {
        $this->assertSame('P/N DS3 S/N AC38', LimpezaHtmlCaderno::texto('<div>P/N <strong>DS3</strong></div><div>S/N AC38</div>'));
    }
}
