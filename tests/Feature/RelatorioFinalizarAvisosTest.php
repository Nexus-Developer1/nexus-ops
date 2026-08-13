<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Vaga 1 (campo): finalizar com fichas vazias pede confirmação explícita (o descarte
// silencioso escondia preventivas sem uma única medição), e depois de finalizar o técnico
// aterra DIRETO no envio (antes era atirado para a listagem e tinha de reencontrar o
// relatório para o enviar ao cliente).
class RelatorioFinalizarAvisosTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'AVISO-1']);

        return [$tecnico, $equip];
    }

    public function test_finalizar_com_fichas_vazias_pede_confirmacao_e_nao_finaliza(): void
    {
        [$tecnico, $equip] = $this->cenario();

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$tecnico->id])
            ->call('finalizar')
            ->assertDispatched('confirmar-fichas-vazias');

        // Sem confirmação, nada foi finalizado.
        $this->assertSame(0, Relatorio::where('estado', 'finalizado')->count());
    }

    public function test_confirmado_finaliza_e_aterra_no_envio(): void
    {
        [$tecnico, $equip] = $this->cenario();

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$tecnico->id])
            ->set('finalizarComFichasVazias', true)
            ->call('finalizar')
            ->assertRedirect(route('relatorios.enviar', Relatorio::firstOrFail()));

        $this->assertSame('finalizado', Relatorio::firstOrFail()->estado->value);
    }

    public function test_ficha_com_medicoes_finaliza_sem_aviso(): void
    {
        [$tecnico, $equip] = $this->cenario();

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$tecnico->id])
            ->set("fichas.{$equip->id}.ve_ln_l1", '230')
            ->call('finalizar')
            ->assertNotDispatched('confirmar-fichas-vazias');

        $this->assertSame('finalizado', Relatorio::firstOrFail()->estado->value);
    }
}
