<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Alertas\Painel;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
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
            'modelo_faturacao' => 'avenca', 'periodo_aviso_dias' => 30]);

        $alerta = $this->servico()->recolher()->firstWhere('tipo', 'renovacao');
        $this->assertNotNull($alerta);
        $this->assertSame('alta', $alerta['severidade']); // 10 dias <= 15 (crítico)
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
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'corretiva', 'modelo_faturacao' => 'avenca']);
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
