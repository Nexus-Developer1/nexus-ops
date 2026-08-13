<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Guardas de persistência do editor de relatórios contra chamadas forjadas (as props públicas
// do Livewire são manipuláveis pelo browser — a UI honesta nunca dispara estes caminhos).
class RelatorioGuardasTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;
    private Equipamento $equip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $this->equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);
    }

    public function test_editar_relatorio_enviado_reabre_o_ciclo_e_preserva_historico_de_envio(): void
    {
        // Documento oficial já entregue ao cliente (pedido da equipa: enviados são editáveis;
        // ao gravar, o ciclo reabre e é preciso REENVIAR para o cliente receber a versão nova).
        $interv = Intervencao::create(['equipamento_id' => $this->equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $rel = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/0100', 'data' => now(), 'estado' => 'enviado',
            'pdf_path' => 'relatorios/x.pdf', 'enviado_em' => now()->subDay(), 'enviado_para' => 'cliente@acme.pt']);

        // Finalizar sobre um enviado → volta a FINALIZADO (pronto a reenviar); o número e o
        // histórico de envio (enviado_em/enviado_para) mantêm-se.
        Livewire::actingAs($this->tecnico)->test(Novo::class, ['relatorio' => $rel])
            ->set('tecnicoIds', [$this->tecnico->id])
            ->set('finalizarComFichasVazias', true) // confirma o aviso de fichas vazias (Vaga 1)
            ->call('finalizar')
            ->assertHasNoErrors();

        $rel->refresh();
        $this->assertSame('finalizado', $rel->estado->value);
        $this->assertSame('2026/0100', $rel->numero);
        $this->assertNotNull($rel->enviado_em);
        $this->assertSame('cliente@acme.pt', $rel->enviado_para);
    }

    public function test_equipamentos_cobertos_com_id_inexistente_sao_rejeitados(): void
    {
        // Antes ia direto ao sync(): id inexistente rebentava na FK (500); agora é validação.
        Livewire::actingAs($this->tecnico)->test(Novo::class)
            ->set('equipamento_id', $this->equip->id)
            ->set('data', now()->toDateString())
            ->set('equipamentosCobertos', [999999])
            ->call('guardarRascunho')
            ->assertHasErrors('equipamentosCobertos.0');

        $this->assertSame(0, Intervencao::count());
    }
}
