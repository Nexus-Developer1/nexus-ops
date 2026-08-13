<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Vaga 2 (gestão): a ficha do contrato mostra QUAIS visitas alimentam o saldo, e o detalhe
// do cliente responde a "quanto trabalho extra fizemos?" (visitas extra + sem contrato).
class Vaga2GestaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_ficha_do_contrato_lista_as_visitas_do_saldo(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $contrato = Contrato::create(['numero' => 'C-VIS', 'cliente_id' => $cliente->id, 'data_inicio' => now()->subMonth(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'), 'visitas_incluidas' => 4]);
        $contrato->eventos()->create(['tipo' => 'visita_preventiva', 'titulo' => 'Visita Q1', 'estado' => 'concluido',
            'inicio' => now()->subDays(9), 'fim' => now()->subDays(9)->addHours(2), 'cliente_id' => $cliente->id, 'cobertura' => 'incluida']);
        $contrato->eventos()->create(['tipo' => 'visita_preventiva', 'titulo' => 'Urgência fim-de-semana', 'estado' => 'concluido',
            'inicio' => now()->subDays(3), 'fim' => now()->subDays(3)->addHours(2), 'cliente_id' => $cliente->id, 'cobertura' => 'extra']);

        Livewire::actingAs($this->admin())->test(\App\Livewire\Contratos\Ficha::class, ['contrato' => $contrato])
            ->assertSee('Visitas do contrato')
            ->assertSee('Visita Q1')
            ->assertSee('Urgência fim-de-semana')
            ->assertSee('Extra (faturável)');
    }

    public function test_detalhe_do_cliente_mostra_trabalho_faturavel_a_parte(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'EXT-1']);

        // Visita extra + corretiva sem contrato → ambas aparecem; total = 2.
        \App\Models\EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'Extra de sábado', 'estado' => 'concluido',
            'inicio' => now()->subDays(2), 'fim' => now()->subDays(2)->addHours(2), 'cliente_id' => $cliente->id, 'cobertura' => 'extra']);
        Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida',
            'data_inicio' => now()->subDay()]);

        Livewire::actingAs($this->admin())->test(\App\Livewire\Clientes\Detalhe::class, ['cliente' => $cliente])
            ->assertSee('Trabalho faturável à parte')
            ->assertSee('Extra de sábado')
            ->assertSee('Sem contrato')
            ->assertViewHas('visitasExtraTotal', 1)
            ->assertViewHas('semContratoTotal', 1);
    }
}
