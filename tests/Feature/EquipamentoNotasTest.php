<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Campo de notas livres na ficha do ativo.
class EquipamentoNotasTest extends TestCase
{
    use RefreshDatabase;

    private function equipamento(): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW']);
    }

    public function test_guarda_e_recarrega_notas(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $equip = $this->equipamento();

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $equip])
            ->set('notas', 'Bateria a degradar — vigiar.')
            ->call('guardarNotas')
            ->assertHasNoErrors();

        $this->assertSame('Bateria a degradar — vigiar.', $equip->fresh()->notas);

        // Ao reabrir a ficha, as notas vêm pré-preenchidas.
        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $equip->fresh()])
            ->assertSet('notas', 'Bateria a degradar — vigiar.');
    }

    public function test_notas_vazias_ficam_null(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $equip = $this->equipamento();
        $equip->update(['notas' => 'algo']);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $equip->fresh()])
            ->set('notas', '   ')
            ->call('guardarNotas')
            ->assertHasNoErrors();

        $this->assertNull($equip->fresh()->notas);
    }
}
