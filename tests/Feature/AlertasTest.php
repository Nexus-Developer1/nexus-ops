<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use App\Notifications\ResumoAlertas;
use App\Services\Alertas\ServicoAlertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertasTest extends TestCase
{
    use RefreshDatabase;

    private function localDe(string $nome): Local
    {
        $cliente = Cliente::create(['nome' => $nome, 'ativo' => true]);

        return Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
    }

    private function servico(): ServicoAlertas
    {
        return app(ServicoAlertas::class);
    }

    public function test_alerta_de_baterias_vencidas(): void
    {
        $local = $this->localDe('ACME');
        Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'fabricante' => 'APC', 'modelo' => 'X40', 'proxima_troca_baterias' => now()->subDay()]);

        $alertas = $this->servico()->recolher();
        $bateria = $alertas->firstWhere('tipo', 'bateria');

        $this->assertNotNull($bateria);
        $this->assertSame('alta', $bateria['severidade']);
    }

    public function test_bateria_longe_nao_alerta(): void
    {
        $local = $this->localDe('ACME');
        Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'proxima_troca_baterias' => now()->addYear()]);

        $this->assertNull($this->servico()->recolher()->firstWhere('tipo', 'bateria'));
    }

    public function test_alerta_de_renovacao_de_contrato(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Contrato::create(['numero' => 'C-1', 'cliente_id' => $cliente->id, 'data_inicio' => now()->subYear(),
            'data_fim' => now()->addDays(10), 'estado' => 'ativo', 'tipo' => 'preventiva',
            'modelo_faturacao_id' => \App\Models\ModeloFaturacao::query()->value('id'), 'periodo_aviso_dias' => 30]);

        $alerta = $this->servico()->recolher()->firstWhere('tipo', 'renovacao');
        $this->assertNotNull($alerta);
        $this->assertSame('alta', $alerta['severidade']); // 10 dias <= 15 (crítico)
    }

    // Alertas de visita PROGRAMADOS no contrato (data + texto editável): entram a partir de
    // 7 dias antes da data, com o TEXTO escrito pela equipa; alta quando a data chega/passa.
    public function test_alerta_de_visita_programado_no_contrato(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $contrato = Contrato::create(['numero' => 'C-9', 'cliente_id' => $cliente->id, 'data_inicio' => now()->subMonth(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'preventiva',
            'modelo_faturacao_id' => \App\Models\ModeloFaturacao::query()->value('id')]);

        // Dentro da janela (amanhã) → média, com o texto editável no título.
        $contrato->alertasVisita()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'Agendar 2.ª visita preventiva']);
        // Vencido (ontem) → alta.
        $contrato->alertasVisita()->create(['data' => now()->subDay()->toDateString(), 'texto' => 'Visita do 1.º semestre por agendar']);
        // Longe (2 meses) → não aparece.
        $contrato->alertasVisita()->create(['data' => now()->addMonths(2)->toDateString(), 'texto' => 'Ainda longe']);

        $alertas = $this->servico()->recolher()->where('tipo', 'visita_programada')->values();

        $this->assertCount(2, $alertas);
        $this->assertSame('alta', $alertas->firstWhere('titulo', 'Visita do 1.º semestre por agendar · C-9')['severidade']);
        $this->assertSame('media', $alertas->firstWhere('titulo', 'Agendar 2.ª visita preventiva · C-9')['severidade']);
    }

    // Alertas de manutenção PROGRAMADOS no equipamento: mesma mecânica dos do contrato —
    // texto editável, 7 dias antes (média), alta ao vencer; fora da janela não aparece.
    public function test_alerta_de_manutencao_programado_no_equipamento(): void
    {
        $local = $this->localDe('ACME');
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'fabricante' => 'Riello', 'modelo' => 'NPW 2000']);

        $equip->alertasManutencao()->create(['data' => now()->addDays(3)->toDateString(), 'texto' => 'Teste de autonomia anual']);
        $equip->alertasManutencao()->create(['data' => now()->subDays(2)->toDateString(), 'texto' => 'Limpeza dos filtros']);
        $equip->alertasManutencao()->create(['data' => now()->addMonths(3)->toDateString(), 'texto' => 'Ainda longe']);

        $alertas = $this->servico()->recolher()->where('tipo', 'manutencao_programada')->values();

        $this->assertCount(2, $alertas);
        $this->assertSame('media', $alertas->firstWhere('titulo', 'Teste de autonomia anual · Riello NPW 2000')['severidade']);
        $this->assertSame('alta', $alertas->firstWhere('titulo', 'Limpeza dos filtros · Riello NPW 2000')['severidade']);
    }

    public function test_alerta_de_visita_em_atraso(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'Preventiva', 'estado' => 'planeado',
            'inicio' => now()->subDays(2), 'fim' => now()->subDays(2)->addHour(), 'cliente_id' => $cliente->id]);

        $alerta = $this->servico()->recolher()->firstWhere('tipo', 'visita_atraso');
        $this->assertNotNull($alerta);
        $this->assertSame('alta', $alerta['severidade']);
    }

    public function test_alerta_de_sla_em_risco(): void
    {
        $local = $this->localDe('ACME');
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $contrato = Contrato::create(['numero' => 'C-2', 'cliente_id' => $local->cliente_id, 'data_inicio' => now()->subYear(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'corretiva', 'modelo_faturacao_id' => \App\Models\ModeloFaturacao::query()->value('id')]);
        $contrato->slas()->create(['prioridade' => 'critica', 'tempo_resposta_horas' => 2, 'tempo_resolucao_horas' => 4, 'horario_cobertura' => '24x7']);

        Intervencao::create(['equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'tipo' => 'corretiva',
            'estado' => 'em_curso', 'data_inicio' => now()->subHours(10)]); // 10h > 4h

        $alerta = $this->servico()->recolher()->firstWhere('tipo', 'sla');
        $this->assertNotNull($alerta);
        $this->assertSame('alta', $alerta['severidade']);
    }

    public function test_comando_notifica_administradores(): void
    {
        Notification::fake();
        $admin = User::create(['nome' => 'Admin', 'email' => 'admin@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        // Cria um alerta (visita em atraso).
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'V', 'estado' => 'planeado',
            'inicio' => now()->subDay(), 'fim' => now()->subDay()->addHour(), 'cliente_id' => $cliente->id]);

        $this->artisan('alertas:verificar')->assertSuccessful();

        Notification::assertSentTo($admin, ResumoAlertas::class);
    }

    public function test_painel_visivel_para_admin_e_oculto_para_cliente(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'admin@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->actingAs($admin)->get('/alertas')->assertOk();

        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $userCliente = User::create(['nome' => 'C', 'email' => 'c@x.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $cliente->id, 'ativo' => true]);
        $this->actingAs($userCliente)->get('/alertas')->assertRedirect(route('portal.dashboard'));
    }
}
