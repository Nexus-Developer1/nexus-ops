<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Enums\PapelUtilizador;
use App\Jobs\GerarVisitasPreventivas;
use App\Livewire\Contratos\Ficha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use App\Services\Agenda\GeradorVisitasPreventivas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

// Ativação do contrato: feedback imediato síncrono até ao limite; fila acima dele.
class ContratoAtivacaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function contratoCom(int $nEquip, string $periodicidade, $inicio, $fim, string $numero): Contrato
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $ids = [];
        for ($i = 0; $i < $nEquip; $i++) {
            $ids[] = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X' . $i])->id;
        }
        $contrato = Contrato::create([
            'numero' => $numero, 'cliente_id' => $cliente->id,
            'data_inicio' => $inicio, 'data_fim' => $fim,
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync($ids);
        $contrato->planosVisita()->create(['equipamento_tipo' => 'ups', 'periodicidade' => $periodicidade, 'duracao_estimada_min' => 60]);

        return $contrato;
    }

    public function test_estimar_conta_sem_criar_nada(): void
    {
        // 2 equipamentos, trimestral, 6 meses → 2 × 3 ocorrências (mês 0,3,6) = 6.
        $contrato = $this->contratoCom(2, 'trimestral', now()->startOfYear(), now()->startOfYear()->addMonths(6), '2026/8001');

        $this->assertSame(6, app(GeradorVisitasPreventivas::class)->estimar($contrato));
        $this->assertSame(0, $contrato->eventos()->count()); // estimar não cria
    }

    public function test_ativar_pequeno_corre_na_hora_com_resumo_imediato(): void
    {
        // 2 equipamentos, trimestral, 6 meses → 6 visitas (≤ limite) → síncrono.
        $contrato = $this->contratoCom(2, 'trimestral', now()->startOfYear(), now()->startOfYear()->addMonths(6), '2026/8002');

        Livewire::actingAs($this->admin())->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $contrato->refresh();
        $this->assertSame(EstadoContrato::Ativo, $contrato->estado);
        // Correu na hora: o resumo já está disponível no MESMO request (feedback imediato).
        $this->assertNotNull($contrato->resumo_geracao);
        $this->assertSame(6, $contrato->resumo_geracao['criadas']);
        $this->assertSame(6, $contrato->eventos()->count());
    }

    public function test_ativar_grande_vai_para_a_fila(): void
    {
        Queue::fake();
        // 30 equipamentos, mensal, 1 ano → 30 × 13 = 390 > 300 (limite) → assíncrono.
        $contrato = $this->contratoCom(30, 'mensal', now()->startOfYear(), now()->startOfYear()->addYear(), '2026/8003');

        Livewire::actingAs($this->admin())->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        Queue::assertPushed(GerarVisitasPreventivas::class); // foi para a fila, não correu na hora
        $this->assertNull($contrato->fresh()->resumo_geracao); // resumo só depois do worker
    }
}
