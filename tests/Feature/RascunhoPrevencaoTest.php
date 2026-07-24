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

// "Guardar rascunho" é um save de PREVENÇÃO: não volta à lista. O 1.º rascunho muda a URL
// para a edição do próprio rascunho (F5 retoma-o); em edição fica na página, com toast.
class RascunhoPrevencaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function equipamento(): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40', 'numero_serie' => 'SN-1']);
    }

    public function test_primeiro_rascunho_redireciona_para_a_edicao_do_proprio_rascunho(): void
    {
        $equip = $this->equipamento();

        Livewire::actingAs($this->admin())->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('tipo', 'corretiva')
            ->call('guardarRascunho')
            ->assertHasNoErrors()
            ->assertRedirect(route('relatorios.editar', Relatorio::firstOrFail()));
    }

    public function test_guardar_rascunho_em_edicao_fica_na_pagina(): void
    {
        $equip = $this->equipamento();
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'em_curso', 'data_inicio' => now()]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => null, 'data' => now(), 'estado' => 'rascunho']);

        Livewire::actingAs($this->admin())->test(Novo::class, ['relatorio' => $relatorio])
            ->set('resumo', 'Trabalho a meio — save de prevenção.')
            ->call('guardarRascunho')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertDispatched('rascunho-guardado');

        // O trabalho ficou mesmo gravado.
        $this->assertSame('Trabalho a meio — save de prevenção.', $interv->fresh()->trabalho_realizado);
    }
}
