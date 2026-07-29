<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Editor;
use App\Livewire\Contratos\Ficha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// SLA com resposta NBD (Next Business Day): alternativa às horas no tempo de resposta.
// NBD e horas são mutuamente exclusivos (NBD ganha); a ficha mostra "NBD".
class ContratoSlaNbdTest extends TestCase
{
    use RefreshDatabase;

    public function test_sla_com_nbd_grava_e_aparece_na_ficha(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('cliente_id', $cliente->id)
            ->set('tipo', 'preventiva')
            ->set('modelo_faturacao_id', ModeloFaturacao::query()->value('id'))
            // NBD marcado E horas preenchidas — o NBD tem de ganhar (exclusividade).
            ->set('slas', [[
                'prioridade' => 'critica', 'tempo_resposta_horas' => 4,
                'resposta_nbd' => true, 'tempo_resolucao_horas' => 48, 'horario_cobertura' => '24x7',
            ]])
            ->call('guardar')
            ->assertHasNoErrors();

        $contrato = Contrato::firstOrFail();
        $sla = $contrato->slas()->firstOrFail();
        $this->assertTrue($sla->resposta_nbd);
        $this->assertNull($sla->tempo_resposta_horas);   // NBD ganhou às horas
        $this->assertSame(48, $sla->tempo_resolucao_horas);
        $this->assertSame('NBD', $sla->rotuloResposta());

        // A ficha do contrato mostra "NBD" na coluna do tempo de resposta.
        Livewire::actingAs($admin)->test(Ficha::class, ['contrato' => $contrato])
            ->assertSee('NBD');

        // Reabrir no editor carrega o NBD marcado.
        Livewire::actingAs($admin)->test(Editor::class, ['contrato' => $contrato])
            ->assertSet('slas.0.resposta_nbd', true);
    }

    public function test_sla_sem_nbd_mantem_as_horas(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);

        Livewire::actingAs($admin)->test(Editor::class)
            ->set('cliente_id', $cliente->id)
            ->set('tipo', 'preventiva')
            ->set('modelo_faturacao_id', ModeloFaturacao::query()->value('id'))
            ->set('slas', [[
                'prioridade' => 'alta', 'tempo_resposta_horas' => 8,
                'resposta_nbd' => false, 'tempo_resolucao_horas' => 72, 'horario_cobertura' => '8x5',
            ]])
            ->call('guardar')
            ->assertHasNoErrors();

        $sla = Contrato::firstOrFail()->slas()->firstOrFail();
        $this->assertFalse($sla->resposta_nbd);
        $this->assertSame(8, $sla->tempo_resposta_horas);
        $this->assertSame('8 h', $sla->rotuloResposta());
    }
}
