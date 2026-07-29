<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Ficha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// A ficha do contrato lista os relatórios feitos no seu âmbito (intervencoes.contrato_id —
// o campo que separa "incluído no contrato" de faturável à parte, CLAUDE.md §6).
class ContratoRelatoriosTest extends TestCase
{
    use RefreshDatabase;

    public function test_ficha_do_contrato_lista_os_relatorios_do_contrato(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-77']);
        $contrato = Contrato::create(['numero' => '2026/5001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(), 'estado' => 'ativo',
            'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id')]);

        // Relatório DO contrato.
        $iC = Intervencao::create(['equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        Relatorio::create(['intervencao_id' => $iC->id, 'numero' => '2026/0300', 'data' => now(), 'estado' => 'finalizado']);

        // Relatório INDIVIDUAL (sem contrato) do mesmo equipamento — não pode aparecer.
        $iI = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        Relatorio::create(['intervencao_id' => $iI->id, 'numero' => '2026/0301', 'data' => now(), 'estado' => 'finalizado']);

        Livewire::actingAs($admin)->test(Ficha::class, ['contrato' => $contrato])
            ->assertSee('Relatórios do contrato')
            ->assertSee('2026/0300')
            ->assertSee('SN-77')
            ->assertDontSee('2026/0301');
    }
}
