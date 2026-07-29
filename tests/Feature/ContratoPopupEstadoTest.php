<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Editor;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Popup pós-gravação do contrato (só em rascunho): ativar / suspender / manter rascunho.
// Ativar reaplica a regra da ficha (exige ≥1 equipamento); contratos já ativos não perguntam.
class ContratoPopupEstadoTest extends TestCase
{
    use RefreshDatabase;

    private function base(): array
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);

        return [$admin, $cliente, $equip];
    }

    private function editor(User $admin, Cliente $cliente, array $extra = [])
    {
        $c = Livewire::actingAs($admin)->test(Editor::class)
            ->set('cliente_id', $cliente->id)
            ->set('tipo', 'preventiva')
            ->set('modelo_faturacao_id', ModeloFaturacao::query()->value('id'));
        foreach ($extra as $k => $v) {
            $c->set($k, $v);
        }

        return $c->call('guardar')->assertHasNoErrors();
    }

    public function test_ativar_no_popup_poe_o_contrato_ativo(): void
    {
        [$admin, $cliente, $equip] = $this->base();

        $this->editor($admin, $cliente, ['equipamentoIds' => [$equip->id]])
            ->assertSet('modalEstado', true)
            ->call('decidirEstado', 'ativar')
            ->assertRedirect();

        $this->assertSame(EstadoContrato::Ativo, Contrato::firstOrFail()->estado);
    }

    public function test_suspender_no_popup_poe_o_contrato_suspenso(): void
    {
        [$admin, $cliente] = $this->base();

        $this->editor($admin, $cliente)
            ->call('decidirEstado', 'suspender')
            ->assertRedirect();

        $this->assertSame(EstadoContrato::Suspenso, Contrato::firstOrFail()->estado);
    }

    public function test_ativar_sem_equipamentos_fica_rascunho_com_aviso(): void
    {
        [$admin, $cliente] = $this->base();

        $this->editor($admin, $cliente)
            ->call('decidirEstado', 'ativar')
            ->assertRedirect();

        // A regra da ficha reaplica-se no servidor: sem equipamentos não ativa.
        $this->assertSame(EstadoContrato::Rascunho, Contrato::firstOrFail()->estado);
    }

    public function test_editar_contrato_ja_ativo_nao_pergunta(): void
    {
        [$admin, $cliente, $equip] = $this->base();
        $contrato = Contrato::create(['numero' => '2026/0100', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(), 'estado' => EstadoContrato::Ativo,
            'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
            'renovacao_automatica' => false, 'periodo_aviso_dias' => 30]);
        $contrato->equipamentos()->sync([$equip->id]);

        Livewire::actingAs($admin)->test(Editor::class, ['contrato' => $contrato])
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertSet('modalEstado', false)
            ->assertRedirect(); // sai direto, como sempre — sem popup

        $this->assertSame(EstadoContrato::Ativo, $contrato->fresh()->estado);
    }
}
