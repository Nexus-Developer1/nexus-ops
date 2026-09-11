<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Encomendas\Ficha;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Dossier;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
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

    public function test_ficha_de_uma_proposta_nao_tem_a_seccao(): void
    {
        Livewire::actingAs($this->admin)->test(Ficha::class, ['dossier' => $this->dossier(6970, 3)])
            ->assertDontSee('Intervenções associadas');
    }
}
