<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Editor;
use App\Livewire\Equipamentos\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Os formulários de criação marcam os campos obrigatórios com * e a validação recusa-os
// vazios (o asterisco tem de corresponder a uma regra real, senão é decoração).
class CamposObrigatoriosTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_novo_equipamento_marca_e_exige_os_obrigatorios(): void
    {
        Livewire::actingAs($this->admin())->test(Novo::class)
            ->assertSee('são obrigatórios')
            ->call('guardar')
            ->assertHasErrors(['cliente_id']); // cliente é obrigatório (tipo/estado têm default)
    }

    public function test_novo_contrato_marca_e_exige_os_obrigatorios(): void
    {
        Livewire::actingAs($this->admin())->test(Editor::class)
            ->assertSee('são obrigatórios')
            ->set('numero', '')
            ->set('data_inicio', '')
            ->set('data_fim', '')
            ->call('guardar')
            ->assertHasErrors(['numero', 'cliente_id', 'data_inicio', 'data_fim', 'tipo', 'modelo_faturacao_id']);
    }

    public function test_relatorio_exige_os_obrigatorios_ao_finalizar_mas_nao_no_rascunho(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);

        // Finalizar sem data nem técnicos → erros nos campos marcados com *.
        Livewire::actingAs($admin)->test(\App\Livewire\Relatorios\Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', '')
            ->set('finalizarComFichasVazias', true) // confirma o aviso de fichas vazias (Vaga 1)
            ->call('finalizar')
            ->assertHasErrors(['data', 'tecnicoIds']);

        // O rascunho só precisa do equipamento (é o que a legenda promete).
        Livewire::actingAs($admin)->test(\App\Livewire\Relatorios\Novo::class)
            ->set('equipamento_id', $equip->id)
            ->call('guardarRascunho')
            ->assertHasNoErrors();
    }
}
