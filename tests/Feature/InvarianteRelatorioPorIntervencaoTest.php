<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Invariante "um relatório VIVO por intervenção", imposto por índice único parcial
// (WHERE deleted_at IS NULL) + criação canónica via Intervencao::garantirRascunho().
class InvarianteRelatorioPorIntervencaoTest extends TestCase
{
    use RefreshDatabase;

    private function intervencao(): Intervencao
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);

        return Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada', 'data_inicio' => now()]);
    }

    public function test_garantir_rascunho_e_idempotente(): void
    {
        $interv = $this->intervencao();

        $r1 = $interv->garantirRascunho();
        $r2 = $interv->fresh()->garantirRascunho();

        $this->assertSame($r1->id, $r2->id);                                      // devolve o mesmo
        $this->assertSame(1, Relatorio::where('intervencao_id', $interv->id)->count()); // 1 vivo, não 2
    }

    public function test_indice_bloqueia_dois_relatorios_vivos_na_mesma_intervencao(): void
    {
        $interv = $this->intervencao();
        $interv->relatorio()->create(['estado' => 'rascunho', 'data' => now()]);

        // Segundo INSERT vivo para a mesma intervenção → viola o índice parcial (23505).
        $this->expectException(QueryException::class);
        $interv->relatorio()->create(['estado' => 'rascunho', 'data' => now()]);
    }

    public function test_apagar_relatorio_e_criar_outro_funciona(): void
    {
        $interv = $this->intervencao();

        $r1 = $interv->garantirRascunho();
        $r1->delete(); // soft delete → deleted_at preenchido → fora do índice parcial

        $r2 = $interv->fresh()->garantirRascunho(); // NÃO é bloqueado pelo índice

        $this->assertNotSame($r1->id, $r2->id);
        $this->assertSame(1, Relatorio::where('intervencao_id', $interv->id)->count());              // 1 vivo
        $this->assertSame(2, Relatorio::withTrashed()->where('intervencao_id', $interv->id)->count()); // 1 apagado + 1 vivo
    }
}
