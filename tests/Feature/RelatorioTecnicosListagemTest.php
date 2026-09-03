<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Ficha;
use App\Livewire\Relatorios\Listagem;
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

// A coluna "Técnico" das listagens de relatórios mostra TODOS os técnicos da intervenção
// (principal + colaboradores) — mostrava só quem redigiu o relatório e escondia quem lá esteve.
class RelatorioTecnicosListagemTest extends TestCase
{
    use RefreshDatabase;

    public function test_listagem_e_contrato_mostram_todos_os_tecnicos_da_intervencao(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $daniel = User::create(['nome' => 'Daniel Ribeiro', 'email' => 'd@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $rui = User::create(['nome' => 'Rui Pereira', 'email' => 'r@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        $cliente = Cliente::create(['nome' => 'BNP PARIBAS', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-BNP']);
        $contrato = Contrato::create(['numero' => 'C-TEC', 'cliente_id' => $cliente->id, 'data_inicio' => now()->subMonth(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'), 'renovacao_automatica' => false, 'periodo_aviso_dias' => 30]);

        $i = Intervencao::create(['equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'tipo' => 'preventiva',
            'estado' => 'concluida', 'tecnico_id' => $daniel->id, 'data_inicio' => now()]);
        $i->tecnicos()->attach([$rui->id, $daniel->id]); // colaborador + o próprio principal (não duplica)
        Relatorio::create(['intervencao_id' => $i->id, 'numero' => '2026/0004', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);

        $this->assertSame('Daniel Ribeiro, Rui Pereira', $i->fresh()->tecnicosLabel());

        Livewire::actingAs($admin)->test(Listagem::class)
            ->assertSee('Daniel Ribeiro, Rui Pereira');

        Livewire::actingAs($admin)->test(Ficha::class, ['contrato' => $contrato])
            ->assertSee('Daniel Ribeiro, Rui Pereira');
    }

    public function test_sem_tecnicos_mostra_travessao(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a2@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-SEM']);
        $i = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        Relatorio::create(['intervencao_id' => $i->id, 'data' => now(), 'estado' => EstadoRelatorio::Rascunho]);

        $this->assertNull($i->fresh()->tecnicosLabel());

        Livewire::actingAs($admin)->test(Listagem::class)->assertSee('SN-SEM');
    }
}
