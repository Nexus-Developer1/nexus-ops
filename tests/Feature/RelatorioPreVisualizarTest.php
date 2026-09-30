<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// «Pré-visualizar» no editor (set. 2026): grava o rascunho e abre o PDF gerado na hora — sem
// guardar ficheiro, sem número, marcado no cabeçalho e no rodapé. Só para rascunhos.
class RelatorioPreVisualizarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function equipamento(): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-PREV']);
    }

    public function test_grava_o_rascunho_e_devolve_o_endereco_da_pre_visualizacao(): void
    {
        $admin = $this->admin();
        $equip = $this->equipamento();

        $url = Livewire::actingAs($admin)->test(Novo::class)
            ->assertSee('Pré-visualizar')
            ->set('equipamento_id', $equip->id)
            ->set('resumo', 'Trabalho escrito antes de pré-visualizar')
            ->call('preVisualizar')
            ->assertHasNoErrors()
            ->assertDispatched('rascunho-guardado')
            ->effects['returns'][0];

        $relatorio = Relatorio::firstOrFail();
        $this->assertSame(route('relatorios.pre-visualizar', $relatorio), $url);
        $this->assertSame(EstadoRelatorio::Rascunho, $relatorio->estado);
        $this->assertNull($relatorio->numero);                                          // não dá número
        $this->assertSame('Trabalho escrito antes de pré-visualizar', $relatorio->intervencao->trabalho_realizado); // gravou
    }

    public function test_o_pdf_e_gerado_na_hora_sem_ficar_guardado_e_com_a_marca(): void
    {
        Storage::fake();
        $equip = $this->equipamento();
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'em_curso', 'data_inicio' => now()]);
        $relatorio = $interv->garantirRascunho();

        $this->actingAs($this->admin())
            ->get(route('relatorios.pre-visualizar', $relatorio))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertNull($relatorio->fresh()->pdf_path);   // nada guardado
        $this->assertSame([], Storage::allFiles());

        $html = view('pdf.relatorio', app(GeradorRelatorio::class)->dadosDoPdf($relatorio) + ['preVisualizacao' => true])->render();
        // Sem marca de água a meio da página (tirada a pedido da equipa): diz-se no cabeçalho e no rodapé.
        $this->assertStringNotContainsString('PRÉ-VISUALIZAÇÃO', $html);
        $this->assertStringContainsString('Rascunho · pré-visualização', $html);
        $this->assertStringContainsString('não é o documento final', $html);
        // O PDF normal não diz nada disto.
        $this->assertStringNotContainsString('pré-visualização', view('pdf.relatorio', app(GeradorRelatorio::class)->dadosDoPdf($relatorio))->render());
    }

    // 27.ª revisão: a rota só serve rascunhos e tem limite (10 por minuto por utilizador) — cada
    // pedido gera o PDF no próprio pedido.
    public function test_rota_so_para_rascunhos_e_com_limite_por_minuto(): void
    {
        $admin = $this->admin();
        $equip = $this->equipamento();
        $finalizado = Relatorio::create(['intervencao_id' => Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()])->id,
            'numero' => '2026/9401', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);
        $this->actingAs($admin)->get(route('relatorios.pre-visualizar', $finalizado))->assertNotFound();

        $rascunho = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'em_curso', 'data_inicio' => now()])->garantirRascunho();
        for ($i = 0; $i < 9; $i++) { // o pedido acima já contou 1
            $this->actingAs($admin)->get(route('relatorios.pre-visualizar', $rascunho))->assertOk();
        }
        $this->actingAs($admin)->get(route('relatorios.pre-visualizar', $rascunho))->assertStatus(429);
    }

    public function test_relatorio_finalizado_nao_tem_o_botao_nem_pre_visualiza(): void
    {
        $admin = $this->admin();
        $equip = $this->equipamento();
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/9400', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->assertDontSee('Pré-visualizar')
            ->call('preVisualizar')
            ->assertReturned(null);

        $this->assertSame(EstadoRelatorio::Finalizado, $relatorio->fresh()->estado);
    }

    // Cabeçalho da ficha de cada equipamento: marca/modelo, nº de série e o LOCAL DE INSTALAÇÃO.
    public function test_ficha_do_equipamento_mostra_o_local_de_instalacao(): void
    {
        $equip = $this->equipamento();
        $equip->update(['localizacao_instalacao' => 'Recepção · Quadro elétrico sala de embalamento']);

        Livewire::actingAs($this->admin())->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->assertSee('Recepção · Quadro elétrico sala de embalamento');
    }

    // 27.ª revisão: num relatório de contrato, o equipamento tem de ser do cliente DO CONTRATO —
    // antes valia o cliente_id (prop pública) e um contrato de outro cliente passava.
    public function test_contrato_de_outro_cliente_e_recusado(): void
    {
        $equipA = $this->equipamento(); // cliente ACME
        $clienteB = Cliente::create(['nome' => 'Outro Cliente', 'ativo' => true]);
        $contratoB = Contrato::create(['numero' => '2026/0900', 'cliente_id' => $clienteB->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id')]);

        Livewire::actingAs($this->admin())->test(Novo::class)
            ->set('cliente_id', $equipA->local->cliente_id)
            ->call('selecionarContrato', $contratoB->id)
            ->set('equipamento_id', $equipA->id)
            ->call('guardarRascunho')
            ->assertHasErrors('equipamento_id');

        $this->assertSame(0, Relatorio::count());
    }

    public function test_cliente_do_portal_nao_acede(): void
    {
        $equip = $this->equipamento();
        $relatorio = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'em_curso', 'data_inicio' => now()])->garantirRascunho();
        $cliente = User::create(['nome' => 'Cli', 'email' => 'c@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Cliente, 'cliente_id' => $equip->local->cliente_id, 'ativo' => true]);

        $this->actingAs($cliente)->get(route('relatorios.pre-visualizar', $relatorio))->assertRedirect(); // vai para o portal, sem PDF
    }
}
