<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Alertas\Painel;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use App\Services\Alertas\ServicoAlertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Alerta automático "propor nova intervenção": equipamentos instalados por nós ou já sujeitos a
// manutenção preventiva avisam 10 meses (config) depois da ÚLTIMA instalação/preventiva
// concluída. Corretivas não contam; uma preventiva mais recente cala o aviso; os "também
// cobertos" de uma intervenção contam como se fossem o principal.
class AlertasPropostaIntervencaoTest extends TestCase
{
    use RefreshDatabase;

    private Equipamento $ups;

    private Equipamento $outra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $this->ups = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-P1']);
        $this->outra = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'SRT', 'numero_serie' => 'SN-P2']);
    }

    private function intervencao(Equipamento $e, string $tipo, string $quando, string $estado = 'concluida'): Intervencao
    {
        return Intervencao::create(['equipamento_id' => $e->id, 'tipo' => $tipo, 'estado' => $estado, 'data_inicio' => $quando, 'data_fim' => $quando]);
    }

    private function propostas()
    {
        return app(ServicoAlertas::class)->recolher()->where('tipo', 'proposta_intervencao')->values();
    }

    public function test_avisa_10_meses_depois_da_ultima_preventiva_ou_instalacao(): void
    {
        $this->intervencao($this->ups, 'preventiva', '2025-10-15');   // 10 meses e meio → avisa (média)
        $this->intervencao($this->outra, 'instalacao', '2025-07-01'); // 14 meses → alta

        $p = $this->propostas();
        $this->assertCount(2, $p);

        $daUps = $p->firstWhere('titulo', 'Propor nova intervenção · Riello NPW');
        $this->assertSame('media', $daUps['severidade']);
        $this->assertStringContainsString('ACME · última manutenção preventiva a 15 out 2025', $daUps['descricao']);
        $this->assertSame('proposta_intervencao:'.$this->ups->id.':2025-10-15', $daUps['chave']);
        $this->assertSame(route('equipamentos.ficha', $this->ups), $daUps['url']);
        $this->assertSame([], $daUps['atribuido_a']); // equipa completa

        $daOutra = $p->firstWhere('titulo', 'Propor nova intervenção · APC SRT');
        $this->assertSame('alta', $daOutra['severidade']);
        $this->assertStringContainsString('última instalação a 01 jul 2025', $daOutra['descricao']);
    }

    public function test_nao_avisa_antes_do_prazo_nem_com_corretivas_nem_com_preventiva_recente(): void
    {
        $this->intervencao($this->ups, 'preventiva', '2026-01-10');   // 8 meses → ainda não
        $this->intervencao($this->outra, 'corretiva', '2025-01-10');  // corretiva não conta
        $this->assertCount(0, $this->propostas());

        // Preventiva antiga MAS há uma mais recente → a mais recente manda.
        $this->intervencao($this->outra, 'preventiva', '2025-03-01');
        $this->intervencao($this->outra, 'preventiva', '2026-02-01');
        $this->assertCount(0, $this->propostas());

        // Preventiva antiga ainda em curso (não concluída) não conta.
        $this->intervencao($this->ups, 'preventiva', '2025-01-01', 'em_curso');
        $this->assertCount(0, $this->propostas());
    }

    public function test_equipamentos_tambem_cobertos_contam_e_o_prazo_e_configuravel(): void
    {
        // A "outra" foi só coberta numa preventiva de há 11 meses → também avisa.
        $i = $this->intervencao($this->ups, 'preventiva', '2025-10-01');
        $i->equipamentosCobertos()->sync([$this->outra->id]);
        $this->assertCount(2, $this->propostas());

        // Com 12 meses de prazo, 11 meses ainda não chega.
        config(['alertas.proposta_meses' => 12]);
        $this->assertCount(0, $this->propostas());
    }

    public function test_intervencao_apagada_nao_conta(): void
    {
        $this->intervencao($this->ups, 'preventiva', '2025-09-01')->delete(); // soft delete
        $this->assertCount(0, $this->propostas());
    }

    public function test_painel_mostra_o_tipo_e_concluir_cala_ate_haver_nova_intervencao(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->intervencao($this->ups, 'preventiva', '2025-09-01');
        $chave = $this->propostas()->first()['chave'];

        Livewire::actingAs($admin)->test(Painel::class)
            ->assertSee('Propostas de intervenção')
            ->assertSee('Propor nova intervenção · Riello NPW')
            ->call('concluir', $chave)
            ->assertDontSee('Propor nova intervenção · Riello NPW');

        // Nova preventiva → chave nova; mas como é recente, não há alerta (só daqui a 10 meses).
        $this->intervencao($this->ups, 'preventiva', '2026-08-20');
        $this->assertCount(0, $this->propostas());
        Carbon::setTestNow('2027-07-01 10:00:00');
        $this->assertCount(1, $this->propostas());
        $this->assertSame('proposta_intervencao:'.$this->ups->id.':2026-08-20', $this->propostas()->first()['chave']);
    }
}
