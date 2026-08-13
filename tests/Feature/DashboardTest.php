<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

// Dashboard: KPIs, agenda dos próximos 7 dias, próximos alertas e renovações. Os testes das
// métricas removidas (visitas por mês, SLA, distribuição, sem-visitas) saíram com elas.
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function equip(): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
    }

    public function test_dashboard_renderiza_para_admin(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->equip(); // garante que a construção dos gráficos corre com dados reais

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Rentabilidade de visitas')            // (Fase 3) cartão removido
            ->assertDontSee('Cumprimento de SLA')                  // removidos a pedido da equipa:
            ->assertDontSee('Equipamentos por tipo')               // gráficos e sem-visitas saíram,
            ->assertDontSee('Equipamentos sem visitas recentes')   // as métricas ficam no serviço
            ->assertSee('Agenda — próximos 7 dias')
            ->assertSee('Próximos alertas')
            ->assertSee('Renovações próximas');
    }

    // Dashboard: agenda dos próximos 7 dias e próximos alertas de equipamentos/contratos.
    public function test_dashboard_mostra_agenda_da_semana_e_proximos_alertas(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'ag@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $equip = $this->equip();

        // Evento dentro da janela de 7 dias, um fora dela e um cancelado (não aparecem).
        EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'Preventiva BNP', 'estado' => 'planeado',
            'inicio' => now()->addDay()->setTime(9, 0), 'fim' => now()->addDay()->setTime(12, 0), 'cliente_id' => $equip->local->cliente_id]);
        EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'Preventiva distante', 'estado' => 'planeado',
            'inicio' => now()->addDays(20)->setTime(9, 0), 'fim' => now()->addDays(20)->setTime(12, 0), 'cliente_id' => $equip->local->cliente_id]);
        EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'Visita cancelada', 'estado' => 'cancelado',
            'inicio' => now()->addDays(2)->setTime(9, 0), 'fim' => now()->addDays(2)->setTime(12, 0), 'cliente_id' => $equip->local->cliente_id]);

        // Alerta de baterias: troca já vencida → aparece nos próximos alertas.
        $equip->update(['proxima_troca_baterias' => now()->subDay()->toDateString()]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Preventiva BNP')
            ->assertDontSee('Preventiva distante')
            ->assertDontSee('Visita cancelada')
            ->assertSee('Baterias vencidas');
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
        $tec = User::create(['nome' => 'T', 'email' => 't@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        Livewire::actingAs($tec)
            ->test(Novo::class, ['relatorio' => $relatorio])
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$tec->id]) // finalizar exige quem fez a intervenção
            ->set('finalizarComFichasVazias', true) // confirma o aviso de fichas vazias (Vaga 1)
            ->call('finalizar')
            ->assertHasNoErrors();

        $this->assertSame('finalizado', $relatorio->fresh()->estado->value);
        $this->assertSame('concluido', $evento->fresh()->estado->value); // visita executada → fechado
    }
}
