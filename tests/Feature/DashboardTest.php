<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Intervencoes\Formulario;
use App\Models\Cliente;
use App\Models\Contrato;
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

    public function test_visitas_por_mes_conta_manuais_e_legado(): void
    {
        // (Fase 3) O gráfico mensal conta visitas de contrato manuais (com cobertura)
        // E o legado (tipo visita_preventiva). Eventos sem cobertura e não-preventivos ficam de fora.
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $contrato = Contrato::create([
            'numero' => '2026/VM', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->startOfYear(), 'data_fim' => now()->endOfYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);

        $mk = fn (string $tipo, ?string $cobertura, string $estado) => EventoAgenda::create([
            'tipo' => $tipo, 'titulo' => 'V', 'estado' => $estado,
            'inicio' => now(), 'fim' => now()->addHour(),
            'cliente_id' => $cliente->id, 'contrato_id' => $contrato->id, 'cobertura' => $cobertura,
        ]);

        $mk('outro', 'incluida', 'concluido');       // manual concluída → planeada + realizada
        $mk('outro', 'extra', 'planeado');            // manual extra planeada → só planeada
        $mk('visita_preventiva', null, 'concluido');  // legado concluída → planeada + realizada
        $mk('outro', null, 'concluido');              // sem cobertura e não-preventiva → NÃO conta

        $r = $this->metricas()->visitasPorMes();
        $this->assertSame(3, array_sum($r['planeadas']));  // 2 manuais + 1 legado
        $this->assertSame(2, array_sum($r['realizadas'])); // as duas concluídas que contam
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
            ->assertDontSee('Rentabilidade de visitas')            // (Fase 3) cartão removido
            ->assertSee('Cumprimento de SLA')
            ->assertSee('Equipamentos por tipo')
            ->assertSee('Visitas de contrato');                    // gráfico mensal renomeado
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
