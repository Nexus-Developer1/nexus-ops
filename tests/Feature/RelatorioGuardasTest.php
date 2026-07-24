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

    public function test_persistir_recusa_intervencao_com_relatorio_enviado(): void
    {
        // Documento oficial já entregue ao cliente.
        $interv = Intervencao::create(['equipamento_id' => $this->equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/0100', 'data' => now(), 'estado' => 'enviado', 'pdf_path' => 'relatorios/x.pdf']);

        // O mount bloqueia editar um Enviado; forjar intervencaoId num componente "novo"
        // contornava-o e reescrevia o documento. Tem de dar 403 — em rascunho E finalizar.
        Livewire::actingAs($this->tecnico)->test(Novo::class)
            ->set('equipamento_id', $this->equip->id)
            ->set('data', now()->toDateString())
            ->set('intervencaoId', $interv->id)
            ->call('guardarRascunho')
            ->assertForbidden();

        Livewire::actingAs($this->tecnico)->test(Novo::class)
            ->set('equipamento_id', $this->equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$this->tecnico->id])
            ->set('intervencaoId', $interv->id)
            ->call('finalizar')
            ->assertForbidden();

        // Nada foi reescrito.
        $this->assertSame('enviado', $interv->fresh()->relatorio->estado->value);
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
