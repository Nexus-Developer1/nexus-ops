<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Alertas\Painel;
use App\Livewire\DashboardGestao;
use App\Models\AlertaConcluido;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use App\Notifications\ResumoAlertas;
use App\Services\Alertas\ServicoAlertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Alertas dados como CONCLUÍDOS (pedido da equipa): só depois saem do dashboard, do painel e do
// email diário; ficam no histórico com quem/quando e podem ser reabertos. Chave estável por
// alerta: se a causa mudar (nova data), é outro alerta e volta a aparecer.
class AlertasConcluirTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Equipamento $eq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $this->eq = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-C']);
    }

    private function servico(): ServicoAlertas
    {
        return app(ServicoAlertas::class);
    }

    public function test_concluir_tira_o_alerta_de_todo_o_lado_ate_ser_reaberto(): void
    {
        Notification::fake();
        $this->eq->alertasManutencao()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'TESTE-ANUAL']);
        $alerta = $this->servico()->recolher()->firstWhere('tipo', 'manutencao_programada');
        $this->assertNotEmpty($alerta['chave']);

        // Dashboard e painel mostram-no, com o botão de concluir.
        Livewire::actingAs($this->admin)->test(DashboardGestao::class)->assertSee('TESTE-ANUAL')->assertSee('Concluir');

        // Concluir a partir do painel → some do serviço, do dashboard, do painel e do email.
        Livewire::actingAs($this->admin)->test(Painel::class)
            ->assertSee('TESTE-ANUAL')
            ->call('concluir', $alerta['chave'])
            ->assertDontSee('TESTE-ANUAL');

        $this->assertCount(0, $this->servico()->recolher()->where('tipo', 'manutencao_programada'));
        Livewire::actingAs($this->admin)->test(DashboardGestao::class)->assertDontSee('TESTE-ANUAL');
        $this->artisan('alertas:verificar')->assertSuccessful();
        Notification::assertNotSentTo($this->admin, ResumoAlertas::class); // nada em aberto → sem email

        // Histórico: quem e quando; auditoria registada.
        $c = AlertaConcluido::firstOrFail();
        $this->assertSame($alerta['chave'], $c->chave);
        $this->assertSame($this->admin->id, $c->concluido_por);
        $this->assertStringContainsString('TESTE-ANUAL', $c->titulo);
        $this->assertDatabaseHas('auditoria', ['acao' => 'alerta_concluido']);
        Livewire::actingAs($this->admin)->test(Painel::class)
            ->set('concluidos', true)
            ->assertSee('Concluído por Admin')
            ->assertSee('Reabrir')
            // Reabrir → volta a aparecer.
            ->call('reabrir', $alerta['chave'])
            ->assertSee('TESTE-ANUAL');
        $this->assertCount(1, $this->servico()->recolher()->where('tipo', 'manutencao_programada'));
        $this->assertSame(0, AlertaConcluido::count());
    }

    public function test_concluir_pelo_dashboard_e_chave_inexistente(): void
    {
        $this->eq->alertasManutencao()->create(['data' => now()->addDay()->toDateString(), 'texto' => 'DASH']);
        $chave = $this->servico()->recolher()->firstWhere('tipo', 'manutencao_programada')['chave'];

        Livewire::actingAs($this->admin)->test(DashboardGestao::class)
            ->call('concluirAlerta', $chave)
            ->assertDontSee('DASH');
        $this->assertSame(1, AlertaConcluido::count());

        // Chave que não corresponde a nenhum alerta em aberto: nada gravado, sem rebentar.
        Livewire::actingAs($this->admin)->test(DashboardGestao::class)->call('concluirAlerta', 'manutencao_programada:9999');
        $this->assertSame(1, AlertaConcluido::count());
        $this->assertFalse($this->servico()->concluir('lixo', $this->admin));
    }

    public function test_a_chave_e_estavel_mas_muda_quando_a_causa_muda(): void
    {
        // Renovação: concluído para a data de fim atual; se o contrato for renovado (nova data
        // de fim) é um alerta novo — volta a aparecer quando essa data se aproximar.
        $contrato = Contrato::create(['numero' => 'C-K', 'cliente_id' => $this->eq->local->cliente_id, 'data_inicio' => now()->subYear(),
            'data_fim' => now()->addDays(10), 'estado' => 'ativo', 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'), 'renovacao_automatica' => false, 'periodo_aviso_dias' => 30]);

        $chave = $this->servico()->recolher()->firstWhere('tipo', 'renovacao')['chave'];
        $this->assertSame('renovacao:'.$contrato->id.':'.now()->addDays(10)->toDateString(), $chave);

        $this->assertTrue($this->servico()->concluir($chave, $this->admin));
        $this->assertCount(0, $this->servico()->recolher()->where('tipo', 'renovacao'));

        $contrato->update(['data_fim' => now()->addDays(20)]); // renovado por mais tempo, ainda dentro do aviso
        $this->assertCount(1, $this->servico()->recolher()->where('tipo', 'renovacao'));
    }

    public function test_todos_os_tipos_tem_chave_unica(): void
    {
        $this->eq->alertasManutencao()->create(['data' => now()->toDateString(), 'texto' => 'A']);
        $this->eq->alertasManutencao()->create(['data' => now()->toDateString(), 'texto' => 'B']);
        $this->eq->update(['proxima_troca_baterias' => now()->addDays(5)]);

        $chaves = $this->servico()->recolher()->pluck('chave');
        $this->assertCount(3, $chaves);
        $this->assertSame($chaves->count(), $chaves->unique()->count());
        $this->assertTrue($chaves->every(fn ($c) => is_string($c) && $c !== ''));
    }
}
