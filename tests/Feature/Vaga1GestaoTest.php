<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Vaga 1 (gestão): o cartão "Todos" do painel de alertas passa a bater com a lista
// (contagens dinâmicas), o modal da agenda mostra o saldo do contrato ao marcar a
// cobertura, e o backlog "por associar" ganha filtro + contador na listagem.
class Vaga1GestaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function contratoAtivo(Cliente $cliente, ?int $visitas = null): Contrato
    {
        return Contrato::create(['numero' => 'C-V1', 'cliente_id' => $cliente->id, 'data_inicio' => now()->subMonth(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'), 'visitas_incluidas' => $visitas]);
    }

    public function test_painel_conta_todos_os_tipos_de_alerta(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $contrato = $this->contratoAtivo($cliente);
        // Alerta de um tipo que ANTES ficava fora das contagens (visita programada vencida).
        $contrato->alertasVisita()->create(['data' => now()->subDay()->toDateString(), 'texto' => 'Agendar visita']);

        Livewire::actingAs($this->admin())->test(\App\Livewire\Alertas\Painel::class)
            ->assertSee('Visitas programadas')
            ->assertViewHas('contagens', fn ($c) => ($c['visita_programada'] ?? 0) === 1)
            // O "Todos" é a soma real das contagens — bate com a lista completa.
            ->assertViewHas('alertas', fn ($a) => $a->count() === 1);
    }

    public function test_modal_da_agenda_mostra_o_saldo_do_contrato(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $contrato = $this->contratoAtivo($cliente, visitas: 4);
        // 3 visitas incluídas já usadas → restam 1.
        foreach (range(1, 3) as $i) {
            $contrato->eventos()->create(['tipo' => 'visita_preventiva', 'titulo' => "V{$i}", 'estado' => 'planeado',
                'inicio' => now()->addDays($i), 'fim' => now()->addDays($i)->addHour(),
                'cliente_id' => $cliente->id, 'cobertura' => 'incluida']);
        }

        Livewire::actingAs($this->admin())->test(\App\Livewire\Agenda\Calendario::class)
            ->set('formContratoId', $contrato->id)
            ->assertViewHas('saldoContratoForm', fn ($s) => $s['usadas'] === 3 && $s['restantes'] === 1);
    }

    public function test_listagem_filtra_por_associar_com_contador(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'COM-CLIENTE']);
        Equipamento::create(['local_id' => null, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SEM-CLIENTE-1']);

        Livewire::actingAs($this->admin())->test(\App\Livewire\Equipamentos\Listagem::class)
            ->assertSee('Por associar (1)')
            ->set('porAssociar', true)
            ->assertSee('SEM-CLIENTE-1')
            ->assertDontSee('COM-CLIENTE');
    }
}
