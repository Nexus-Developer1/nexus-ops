<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// A ficha do ativo mostra o(s) contrato(s) que o cobrem (N:M contrato_equipamentos).
class EquipamentoContratoFichaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Cliente, 2: Equipamento} */
    private function cenario(): array
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-1']);

        return [$admin, $cliente, $equip];
    }

    public function test_equipamento_associado_mostra_o_contrato(): void
    {
        [$admin, $cliente, $equip] = $this->cenario();
        $contrato = Contrato::create([
            'numero' => '2026/0007', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->attach($equip->id);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $equip])
            ->assertViewHas('contratos', fn ($c) => $c->contains('id', $contrato->id))
            ->assertSee('2026/0007')
            ->assertDontSee('Sem contrato associado.');
    }

    public function test_equipamento_sem_contrato_mostra_vazio(): void
    {
        [$admin, , $equip] = $this->cenario();

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $equip])
            ->assertViewHas('contratos', fn ($c) => $c->isEmpty())
            ->assertSee('Sem contrato associado.');
    }
}
