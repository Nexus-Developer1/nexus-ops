<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\Gestao\ServicoMetricas;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
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

    public function test_enviar_fecha_o_evento(): void
    {
        Mail::fake();
        $equip = $this->equip();
        $evento = EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'V', 'estado' => 'em_curso',
            'inicio' => now(), 'fim' => now()->addHour(), 'cliente_id' => $equip->local->cliente_id, 'equipamento_id' => $equip->id]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'evento_agenda_id' => $evento->id,
            'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        // pdf_path preenchido para o job não gerar PDF no teste.
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/0300',
            'data' => now(), 'estado' => 'finalizado', 'pdf_path' => 'relatorios/x.pdf']);

        // ENVIAR (job) → marca Enviado E fecha o evento de agenda.
        (new EnviarRelatorioPorEmail($relatorio, 'cliente@x.pt', 'Assunto', 'Msg'))
            ->handle(app(GeradorRelatorio::class));

        $this->assertSame('enviado', $relatorio->fresh()->estado->value);
        $this->assertSame('concluido', $evento->fresh()->estado->value); // evento fechado no envio
    }

    public function test_enviar_sem_evento_associado_nao_rebenta(): void
    {
        Mail::fake();
        $equip = $this->equip();
        // Intervenção SEM evento_agenda_id.
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/0301',
            'data' => now(), 'estado' => 'finalizado', 'pdf_path' => 'relatorios/y.pdf']);

        (new EnviarRelatorioPorEmail($relatorio, 'cliente@x.pt', 'A', 'M'))
            ->handle(app(GeradorRelatorio::class));

        $this->assertSame('enviado', $relatorio->fresh()->estado->value); // envia na mesma, sem erro
    }

    public function test_finalizar_fecha_o_evento(): void
    {
        // Regra revista (2026-07-23): FINALIZAR fecha o evento — Concluido significa "visita
        // executada", não "relatório entregue". Antes só o envio por email fechava; relatórios
        // entregues em mão/portal deixavam a visita "em curso" para sempre e as métricas
        // planeadas vs. realizadas contavam mal. O envio continua a fechar (idempotente).
        $equip = $this->equip();
        $evento = EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'V', 'estado' => 'em_curso',
            'inicio' => now(), 'fim' => now()->addHour(), 'cliente_id' => $equip->local->cliente_id, 'equipamento_id' => $equip->id]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'evento_agenda_id' => $evento->id,
            'tipo' => 'preventiva', 'estado' => 'em_curso', 'data_inicio' => now()]);
        $relatorio = $interv->relatorio()->create(['numero' => null, 'data' => now(), 'estado' => 'rascunho']);

        // Finalizar pelo editor VIVO (Novo).
        Livewire::actingAs(User::create(['nome' => 'T', 'email' => 't@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]))
            ->test(Novo::class, ['relatorio' => $relatorio])
            ->set('data', now()->toDateString())
            ->call('finalizar')
            ->assertHasNoErrors();

        $this->assertSame('finalizado', $relatorio->fresh()->estado->value);
        $this->assertSame('concluido', $evento->fresh()->estado->value); // visita executada → fechado
    }
}
