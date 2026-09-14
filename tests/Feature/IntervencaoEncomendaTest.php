<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Encomendas\Ficha;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Dossier;
use App\Models\EncomendaManual;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\Encomendas\LigadorEncomendasManuais;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Intervenção ↔ encomenda de peças (pedido da equipa, set. 2026). Liga-se no relatório: sem
// texto sugere as encomendas de peças DO CLIENTE; com texto procura em todas pelo nº ou
// cliente. N:M — a mesma encomenda serve várias visitas. Só dossiês do tipo 1 (encomenda de
// peças) entram; propostas e encomendas de produção forjadas são recusadas. A ficha da
// encomenda mostra as intervenções que a usam.
class IntervencaoEncomendaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $acme;

    private Equipamento $ups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver);
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->acme = Cliente::create(['nome' => 'ACME Lda', 'id_erp' => '148', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $this->acme->id, 'designacao' => 'Sede']);
        $this->ups = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-ENC-1']);
    }

    private function dossier(int $obrano, int $ndos = Dossier::TIPO_ENCOMENDA_PECAS, string $clienteNo = '148', string $nome = 'ACME Lda'): Dossier
    {
        return Dossier::create([
            'id_erp' => 'BO-'.$ndos.'-'.$obrano, 'ndos' => $ndos, 'nmdos' => Dossier::TIPOS[$ndos], 'obrano' => $obrano,
            'ano' => 2026, 'data' => now()->subDays($obrano % 30), 'cliente_no' => $clienteNo, 'nome' => $nome, 'fechada' => false,
        ]);
    }

    // Relatório individual novo para o cliente ACME (≤10 equipamentos → o UPS entra sozinho).
    private function editorNovo()
    {
        return Livewire::actingAs($this->admin)->test(Novo::class)
            ->call('selecionarCliente', $this->acme->id)
            ->set('data', now()->toDateString());
    }

    public function test_liga_a_encomenda_no_relatorio_grava_e_reabre(): void
    {
        $enc = $this->dossier(3408);

        $this->editorNovo()
            ->call('adicionarEncomenda', $enc->id)
            ->assertSet('encomendaIds', [$enc->id])
            ->assertSee('Nº 3408')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::firstOrFail();
        $this->assertSame([$enc->id], $interv->encomendas()->pluck('dossiers.id')->all());

        // Reabrir o rascunho traz a ligação; desligar e gravar tira-a.
        Livewire::actingAs($this->admin)->test(Novo::class, ['relatorio' => $interv->relatorio])
            ->assertSet('encomendaIds', [$enc->id])
            ->call('removerEncomenda', $enc->id)
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $this->assertSame(0, $interv->encomendas()->count());
    }

    public function test_sugere_as_encomendas_de_pecas_do_cliente_e_pesquisa_em_todas(): void
    {
        $this->dossier(3408);                                            // do cliente → sugerida
        $this->dossier(6970, 3);                                         // proposta do cliente → nunca
        $this->dossier(875, 7);                                          // encomenda de produção → nunca
        $this->dossier(3999, Dossier::TIPO_ENCOMENDA_PECAS, '777', 'Outro Cliente SA'); // outro cliente

        $c = $this->editorNovo()
            ->assertSee('Encomenda Peças nº 3408')
            ->assertDontSee('nº 6970')
            ->assertDontSee('nº 875')
            ->assertDontSee('nº 3999'); // sem texto: só as do cliente do relatório

        // Com texto procura em todas as encomendas de peças (a peça pode ter ido noutro nome).
        $c->set('encomendaBusca', '3999')->assertSee('Encomenda Peças nº 3999')
            ->set('encomendaBusca', 'Outro Cliente')->assertSee('Encomenda Peças nº 3999')
            ->set('encomendaBusca', '6970')->assertDontSee('nº 6970'); // proposta nunca aparece
    }

    public function test_proposta_ou_encomenda_de_producao_forjadas_sao_recusadas(): void
    {
        $proposta = $this->dossier(6970, 3);

        // Pela ação: ignorada.
        $this->editorNovo()->call('adicionarEncomenda', $proposta->id)->assertSet('encomendaIds', []);

        // Metida à força na propriedade: a gravação recusa e nada fica ligado.
        $this->editorNovo()
            ->set('encomendaIds', [$proposta->id])
            ->call('guardarRascunho')
            ->assertHasErrors('encomendaIds.0');

        $this->assertSame(0, \DB::table('intervencao_encomenda')->count());
    }

    public function test_a_mesma_encomenda_serve_varias_intervencoes_e_a_ficha_mostra_as(): void
    {
        $enc = $this->dossier(3408);

        // Duas visitas (diagnóstico e instalação) com a mesma encomenda.
        foreach (['2026/0101', '2026/0102'] as $numero) {
            $i = Intervencao::create(['equipamento_id' => $this->ups->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()]);
            Relatorio::create(['intervencao_id' => $i->id, 'numero' => $numero, 'data' => now(), 'estado' => 'finalizado']);
            $i->encomendas()->attach($enc->id);
        }

        Livewire::actingAs($this->admin)->test(Ficha::class, ['dossier' => $enc])
            ->assertSee('Intervenções associadas')
            ->assertSee('2026/0101')
            ->assertSee('2026/0102')
            ->assertSee('SN-ENC-1');
    }

    // ---- Encomenda escrita à mão (nº + ano), ainda por chegar do PHC ----

    public function test_numero_a_mao_que_ainda_nao_chegou_fica_por_sincronizar_e_grava(): void
    {
        $this->editorNovo()
            ->set('encomendaManualNumero', '3425')
            ->assertSet('encomendaManualAno', (string) now()->year) // ano pré-preenchido
            ->set('encomendaManualAno', '2026')
            ->call('adicionarEncomendaManual')
            ->assertSet('encomendasManuais', [['obrano' => 3425, 'ano' => 2026]])
            ->assertSet('encomendaManualNumero', '')
            ->assertSee('Nº 3425/2026')
            ->assertSee('por sincronizar')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::firstOrFail();
        $this->assertTrue($interv->encomendasManuais()->where('obrano', 3425)->where('ano', 2026)->exists());
        $this->assertSame(0, $interv->encomendas()->count());

        // Reabrir traz a escrita à mão; retirá-la e gravar apaga-a.
        Livewire::actingAs($this->admin)->test(Novo::class, ['relatorio' => $interv->relatorio])
            ->assertSet('encomendasManuais', [['obrano' => 3425, 'ano' => 2026]])
            ->call('removerEncomendaManual', 0)
            ->call('guardarRascunho')
            ->assertHasNoErrors();
        $this->assertSame(0, EncomendaManual::count());
    }

    public function test_numero_a_mao_que_ja_existe_liga_logo_a_encomenda_verdadeira(): void
    {
        $enc = $this->dossier(3408); // ano 2026

        $this->editorNovo()
            ->set('encomendaManualNumero', '3408')->set('encomendaManualAno', '2026')
            ->call('adicionarEncomendaManual')
            ->assertSet('encomendaIds', [$enc->id])
            ->assertSet('encomendasManuais', []);
    }

    public function test_o_mesmo_numero_de_outro_ano_nao_e_confundido(): void
    {
        $this->dossier(3408); // é de 2026

        // A nº 3408 de 2025 é outra encomenda: fica por sincronizar, não liga a de 2026.
        $this->editorNovo()
            ->set('encomendaManualNumero', '3408')->set('encomendaManualAno', '2025')
            ->call('adicionarEncomendaManual')
            ->assertSet('encomendaIds', [])
            ->assertSet('encomendasManuais', [['obrano' => 3408, 'ano' => 2025]]);
    }

    public function test_quando_a_encomenda_chega_do_phc_a_ligacao_passa_a_normal(): void
    {
        $interv = Intervencao::create(['equipamento_id' => $this->ups->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/0200', 'data' => now(), 'estado' => 'finalizado']);
        EncomendaManual::create(['intervencao_id' => $interv->id, 'obrano' => 3425, 'ano' => 2026]);

        // Ainda não chegou: nada muda.
        $this->assertSame(0, app(LigadorEncomendasManuais::class)->reconciliar());
        $this->assertSame(1, EncomendaManual::count());

        // Chega no sync: passa a ligação normal e sai das escritas à mão.
        $enc = $this->dossier(3425);
        $this->assertSame(1, app(LigadorEncomendasManuais::class)->reconciliar());
        $this->assertSame([$enc->id], $interv->encomendas()->pluck('dossiers.id')->all());
        $this->assertSame(0, EncomendaManual::count());

        // E a ficha da encomenda já mostra o relatório.
        Livewire::actingAs($this->admin)->test(Ficha::class, ['dossier' => $enc])->assertSee('2026/0200');
    }

    public function test_o_sync_dos_dossies_faz_a_ligacao_no_fim(): void
    {
        $interv = Intervencao::create(['equipamento_id' => $this->ups->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        EncomendaManual::create(['intervencao_id' => $interv->id, 'obrano' => 3425, 'ano' => 2026]);
        $enc = $this->dossier(3425); // já cá está (ex.: veio numa corrida anterior)

        $this->artisan('erp:sincronizar-dossiers', ['--limit' => 3])
            ->expectsOutputToContain('Encomendas escritas à mão ligadas aos relatórios: 1.');

        $this->assertSame([$enc->id], $interv->encomendas()->pluck('dossiers.id')->all());
    }

    public function test_numero_e_ano_invalidos_sao_recusados(): void
    {
        $this->editorNovo()
            ->set('encomendaManualNumero', '')->call('adicionarEncomendaManual')
            ->assertHasErrors('encomendaManualNumero')
            ->set('encomendaManualNumero', '12')->set('encomendaManualAno', '1999')->call('adicionarEncomendaManual')
            ->assertHasErrors('encomendaManualAno')
            ->assertSet('encomendasManuais', []);

        // Forjado directamente na propriedade: a gravação recusa.
        $this->editorNovo()
            ->set('encomendasManuais', [['obrano' => 'abc', 'ano' => 2026]])
            ->call('guardarRascunho')
            ->assertHasErrors('encomendasManuais.0.obrano');
        $this->assertSame(0, EncomendaManual::count());
    }

    // Detalhe da encomenda ligada (set. 2026): com o nº sozinho não se sabe se é a certa, por
    // isso o editor mostra em baixo o cabeçalho e as LINHAS lidas ao vivo do PHC.
    public function test_encomenda_ligada_mostra_o_cabecalho_e_as_linhas_do_phc(): void
    {
        $enc = $this->dossier(3280, nome: 'PONTUAL - IT BUSINESS');
        $enc->update(['total_debito' => 1234.5]);

        $this->editorNovo()
            ->call('adicionarEncomenda', $enc->id)
            ->assertSee('Encomenda Peças nº 3280/2026')
            ->assertSee('PONTUAL - IT BUSINESS')
            ->assertSee('Aberta')
            ->assertSee('1 234,50 €')
            ->assertViewHas('encomendasDetalhe', fn ($d) => $d->count() === 1 && $d->first()['erro'] === false && count($d->first()['linhas']) >= 1)
            ->assertSee('Ref.')
            ->assertSee('UPS Riello NPW 2000VA'); // 1.ª linha do FakeErpDriver
    }

    public function test_phc_em_baixo_mostra_o_cabecalho_com_aviso_e_nao_rebenta(): void
    {
        $this->app->bind(ErpSyncDriver::class, fn () => new class extends FakeErpDriver
        {
            public function obterLinhasDossier(string $bostamp): iterable
            {
                throw new \RuntimeException('SQLSTATE[HY000]: Unable to connect to server');
            }
        });
        $enc = $this->dossier(3281);

        $this->editorNovo()
            ->call('adicionarEncomenda', $enc->id)
            ->assertOk()
            ->assertSee('Encomenda Peças nº 3281/2026')
            ->assertSee('Não foi possível ler as linhas no PHC agora.')
            ->assertViewHas('encomendasDetalhe', fn ($d) => $d->first()['erro'] === true);
    }

    public function test_filtro_so_encomendas_abertas(): void
    {
        $aberta = $this->dossier(3300);
        $fechada = $this->dossier(3301);
        $fechada->update(['fechada' => true]);

        $editor = $this->editorNovo()
            ->assertSee('Só encomendas abertas')
            ->assertViewHas('encomendasFiltradas', fn ($l) => $l->pluck('id')->sort()->values()->all() === [$aberta->id, $fechada->id]);

        $editor->set('encomendasSoAbertas', true)
            ->assertViewHas('encomendasFiltradas', fn ($l) => $l->pluck('id')->all() === [$aberta->id]);

        // Também na pesquisa por texto.
        $editor->set('encomendaBusca', '330')
            ->assertViewHas('encomendasFiltradas', fn ($l) => $l->pluck('id')->all() === [$aberta->id]);
    }

    public function test_ficha_de_uma_proposta_nao_tem_a_seccao(): void
    {
        Livewire::actingAs($this->admin)->test(Ficha::class, ['dossier' => $this->dossier(6970, 3)])
            ->assertDontSee('Intervenções associadas');
    }
}
