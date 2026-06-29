<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Intervencoes\Formulario;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\ContratoPlanoVisita;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use App\Services\Gestao\ServicoMetricas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function metricas(): ServicoMetricas
    {
        return app(ServicoMetricas::class);
    }

    private function equip(): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
    }

    private int $seqContrato = 0;

    // Contrato com um plano de visita (periodicidade) e N equipamentos do tipo dado.
    private function contratoComPlano(string $periodicidade, Carbon $inicio, Carbon $fim, int $nEquip = 1, string $tipo = 'ups', string $estado = 'ativo'): Contrato
    {
        $this->seqContrato++;
        $cliente = Cliente::create(['nome' => 'ACME ' . $this->seqContrato, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        $contrato = Contrato::create([
            'numero' => '2026/T' . $this->seqContrato,
            'cliente_id' => $cliente->id,
            'data_inicio' => $inicio,
            'data_fim' => $fim,
            'estado' => $estado,
            'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);

        $ids = [];
        for ($i = 0; $i < $nEquip; $i++) {
            $ids[] = Equipamento::create(['local_id' => $local->id, 'tipo' => $tipo, 'estado' => 'operacional'])->id;
        }
        $contrato->equipamentos()->sync($ids);

        ContratoPlanoVisita::create([
            'contrato_id' => $contrato->id,
            'equipamento_tipo' => $tipo,
            'periodicidade' => $periodicidade,
        ]);

        return $contrato;
    }

    public function test_visitas_contratadas_calculadas_a_partir_da_periodicidade(): void
    {
        // Contrato anual completo, trimestral, 1 UPS → 4 visitas contratadas no ano (Jan/Abr/Jul/Out).
        $this->contratoComPlano('trimestral', now()->startOfYear(), now()->endOfYear());

        $r = $this->metricas()->rentabilidadeVisitas();
        $this->assertSame(4, $r['contratadas']);
        $this->assertSame(0, $r['realizadas']); // ainda sem eventos concluídos
    }

    public function test_realizadas_nao_conta_canceladas(): void
    {
        $this->contratoComPlano('trimestral', now()->startOfYear(), now()->endOfYear()); // contratadas = 4

        $cliente = Cliente::create(['nome' => 'EV', 'ativo' => true]);
        // 2 concluídas + 1 cancelada no ano.
        foreach (['concluido', 'concluido', 'cancelado'] as $i => $estado) {
            EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => "V$i", 'estado' => $estado,
                'inicio' => now()->startOfYear()->addDays($i + 1), 'fim' => now()->startOfYear()->addDays($i + 1)->addHour(),
                'cliente_id' => $cliente->id]);
        }

        $r = $this->metricas()->rentabilidadeVisitas();
        $this->assertSame(2, $r['realizadas']);  // cancelada não conta (bug corrigido)
        $this->assertSame(4, $r['contratadas']); // determinístico, alheio aos eventos
        $this->assertSame(50, $r['taxa']);       // 2/4
    }

    public function test_vigencia_parcial_conta_so_as_visitas_dentro_do_ano(): void
    {
        // Começa a 1 de julho deste ano, trimestral, 1 UPS → só Jul e Out caem no ano corrente.
        $inicio = now()->startOfYear()->addMonths(6);          // 1 de julho
        $fim = now()->startOfYear()->addMonths(6)->addYear();  // ~1 de julho do ano seguinte
        $this->contratoComPlano('trimestral', $inicio, $fim);

        $this->assertSame(2, $this->metricas()->rentabilidadeVisitas()['contratadas']);
    }

    public function test_contratadas_e_deterministico_independente_de_eventos(): void
    {
        $this->contratoComPlano('trimestral', now()->startOfYear(), now()->endOfYear()); // 4

        $this->assertSame(4, $this->metricas()->rentabilidadeVisitas()['contratadas']);

        // Criar eventos preventivos planeados/cancelados NÃO altera as "contratadas".
        $cliente = Cliente::create(['nome' => 'EV2', 'ativo' => true]);
        foreach (['planeado', 'cancelado', 'cancelado'] as $i => $estado) {
            EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => "X$i", 'estado' => $estado,
                'inicio' => now()->startOfYear()->addDays($i + 1), 'fim' => now()->startOfYear()->addDays($i + 1)->addHour(),
                'cliente_id' => $cliente->id]);
        }

        $r = $this->metricas()->rentabilidadeVisitas();
        $this->assertSame(4, $r['contratadas']); // inalterado
        $this->assertSame(0, $r['realizadas']);  // nenhum concluído
    }

    public function test_contrato_em_rascunho_nao_conta_para_contratadas(): void
    {
        $this->contratoComPlano('trimestral', now()->startOfYear(), now()->endOfYear(), 1, 'ups', 'rascunho');

        $this->assertSame(0, $this->metricas()->rentabilidadeVisitas()['contratadas']);
    }

    public function test_cumprimento_de_sla(): void
    {
        $equip = $this->equip();
        $contrato = Contrato::create(['numero' => 'C-1', 'cliente_id' => $equip->local->cliente_id, 'data_inicio' => now()->subYear(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'corretiva', 'modelo_faturacao_id' => \App\Models\ModeloFaturacao::query()->value('id')]);
        $contrato->slas()->create(['prioridade' => 'critica', 'tempo_resolucao_horas' => 8, 'horario_cobertura' => '24x7']);

        // Uma dentro do prazo (4h <= 8h), outra fora (20h > 8h).
        Intervencao::create(['equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'tipo' => 'corretiva',
            'estado' => 'concluida', 'data_inicio' => now()->subDays(2), 'data_fim' => now()->subDays(2)->addHours(4)]);
        Intervencao::create(['equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'tipo' => 'corretiva',
            'estado' => 'concluida', 'data_inicio' => now()->subDays(3), 'data_fim' => now()->subDays(3)->addHours(20)]);

        $sla = $this->metricas()->cumprimentoSla();
        $this->assertSame(2, $sla['total']);
        $this->assertSame(1, $sla['dentro']);
        $this->assertSame(50, $sla['taxa']);
    }

    public function test_equipamentos_sem_visitas_recentes(): void
    {
        $semVisita = $this->equip();                 // nenhuma intervenção
        $comVisita = $this->equip();
        Intervencao::create(['equipamento_id' => $comVisita->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()->subMonth()]);

        $lista = $this->metricas()->equipamentosSemVisitas();
        $this->assertTrue($lista->contains('id', $semVisita->id));
        $this->assertFalse($lista->contains('id', $comVisita->id));
    }

    public function test_distribuicao_de_equipamentos_por_tipo_e_estado(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'critico']);
        Equipamento::create(['local_id' => $local->id, 'tipo' => 'gerador', 'estado' => 'operacional']);

        // Chaves são os valores do enum (regressão: o cast não pode rebentar a conversão).
        $porTipo = $this->metricas()->equipamentosPorTipo();
        $this->assertSame(2, $porTipo['ups']);
        $this->assertSame(1, $porTipo['gerador']);

        $porEstado = $this->metricas()->equipamentosPorEstado();
        $this->assertSame(2, $porEstado['operacional']);
        $this->assertSame(1, $porEstado['critico']);
    }

    public function test_dashboard_renderiza_para_admin(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->equip(); // garante que a construção dos gráficos corre com dados reais

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Rentabilidade de visitas')
            ->assertSee('Equipamentos por tipo')
            ->assertSee('Visitas preventivas por mês');
    }

    public function test_finalizar_fecha_o_evento_de_agenda(): void
    {
        $equip = $this->equip();
        $evento = EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'V', 'estado' => 'em_curso',
            'inicio' => now(), 'fim' => now()->addHour(), 'cliente_id' => $equip->local->cliente_id, 'equipamento_id' => $equip->id]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'evento_agenda_id' => $evento->id,
            'tipo' => 'preventiva', 'estado' => 'em_curso', 'data_inicio' => now()]);

        Livewire::actingAs(User::create(['nome' => 'T', 'email' => 't@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]))
            ->test(Formulario::class, ['intervencao' => $interv])
            ->call('finalizar');

        $this->assertSame('concluido', $evento->fresh()->estado->value);
        $this->assertSame('concluida', $interv->fresh()->estado->value);
    }
}
