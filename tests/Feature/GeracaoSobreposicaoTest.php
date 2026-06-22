<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Jobs\GerarVisitasPreventivas;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Services\Agenda\DetetorSobreposicaoEquipamento;
use App\Services\Agenda\GeradorVisitasPreventivas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Aviso de sobreposição por equipamento na geração de preventivas (só visibilidade —
// não move, não bloqueia, não altera datas).
class GeracaoSobreposicaoTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array<string, mixed>> $planos @return array{0: Contrato, 1: Equipamento} */
    private function contrato(array $planos): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40']);
        $contrato = Contrato::create([
            'numero' => '2026/9001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->startOfYear(), 'data_fim' => now()->startOfYear()->addMonths(6),
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync([$equip->id]);
        foreach ($planos as $p) {
            $contrato->planosVisita()->create($p);
        }

        return [$contrato, $equip];
    }

    public function test_sem_sobreposicao_aviso_vazio(): void
    {
        [$contrato] = $this->contrato([
            ['equipamento_tipo' => 'ups', 'periodicidade' => 'trimestral', 'duracao_estimada_min' => 90],
        ]);

        $criadas = app(GeradorVisitasPreventivas::class)->gerar($contrato);
        $sobre = app(DetetorSobreposicaoEquipamento::class)->paraContrato($contrato);

        $this->assertGreaterThan(0, $criadas);
        $this->assertSame([], $sobre);
        $this->assertSame($criadas, $contrato->eventos()->count()); // todas criadas
    }

    public function test_dois_planos_no_mesmo_equipamento_geram_sobreposicao(): void
    {
        // Dois planos 'ups' no mesmo equipamento → coincidem no mês 0 (e 3, 6) à mesma hora.
        [$contrato] = $this->contrato([
            ['equipamento_tipo' => 'ups', 'periodicidade' => 'trimestral', 'duracao_estimada_min' => 90],
            ['equipamento_tipo' => 'ups', 'periodicidade' => 'mensal', 'duracao_estimada_min' => 60],
        ]);

        $criadas = app(GeradorVisitasPreventivas::class)->gerar($contrato);
        $sobre = app(DetetorSobreposicaoEquipamento::class)->paraContrato($contrato);

        $this->assertNotEmpty($sobre);
        $this->assertArrayHasKey('quando', $sobre[0]);
        $this->assertArrayHasKey('colide_com', $sobre[0]);
        $this->assertFalse($sobre[0]['externo']); // colisão dentro do mesmo contrato
        // A geração NÃO foi afetada — todas as visitas existem.
        $this->assertSame($criadas, $contrato->eventos()->count());
    }

    public function test_job_persiste_resumo_no_contrato(): void
    {
        [$contrato] = $this->contrato([
            ['equipamento_tipo' => 'ups', 'periodicidade' => 'trimestral', 'duracao_estimada_min' => 90],
            ['equipamento_tipo' => 'ups', 'periodicidade' => 'mensal', 'duracao_estimada_min' => 60],
        ]);

        (new GerarVisitasPreventivas($contrato))->handle(
            app(GeradorVisitasPreventivas::class),
            app(DetetorSobreposicaoEquipamento::class),
        );

        $resumo = $contrato->fresh()->resumo_geracao;
        $this->assertNotNull($resumo);
        $this->assertGreaterThan(0, $resumo['criadas']);
        $this->assertGreaterThan(0, $resumo['sobreposicoes']);
        $this->assertNotEmpty($resumo['detalhe']);
    }
}
