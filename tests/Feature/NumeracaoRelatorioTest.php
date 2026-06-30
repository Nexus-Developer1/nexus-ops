<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Numeração de relatórios: deriva do MAIOR número usado no ano (incl. soft-deleted),
// nunca do COUNT — senão, ao apagar relatórios, o contador recua e reutiliza um número
// já ocupado, violando relatorios_numero_unique (erro 500 ao finalizar).
class NumeracaoRelatorioTest extends TestCase
{
    use RefreshDatabase;

    private Equipamento $equip;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->actingAs($admin);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $this->equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
    }

    private function relatorioComNumero(string $numero): Relatorio
    {
        $interv = Intervencao::create(['equipamento_id' => $this->equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);

        return $interv->relatorio()->create(['numero' => $numero, 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);
    }

    public function test_proximo_numero_usa_max_e_ignora_apagados(): void
    {
        $ano = now()->year;
        for ($i = 1; $i <= 10; $i++) {
            $this->relatorioComNumero(sprintf('%d/%04d', $ano, $i)); // 0001..0010
        }
        // Apaga (soft) alguns do meio → o COUNT dos vivos recua para 8, mas os números
        // continuam ocupados no índice unique (soft delete mantém a linha).
        Relatorio::where('numero', sprintf('%d/0003', $ano))->delete();
        Relatorio::where('numero', sprintf('%d/0007', $ano))->delete();

        // MAX+1 = 0011 (o COUNT+1 daria 0009, que já existe → colisão).
        $this->assertSame(sprintf('%d/0011', $ano), app(GeradorRelatorio::class)->proximoNumero());
    }

    public function test_finalizar_apos_eliminacoes_nao_duplica_numero(): void
    {
        $ano = now()->year;
        for ($i = 1; $i <= 10; $i++) {
            $this->relatorioComNumero(sprintf('%d/%04d', $ano, $i)); // 0001..0010
        }
        // Apaga quase todos → só 0001 vivo. O código antigo (COUNT) daria 0002, que existe
        // (soft-deleted) → unique violation. O novo (MAX+1) tem de dar 0011.
        for ($i = 2; $i <= 10; $i++) {
            Relatorio::where('numero', sprintf('%d/%04d', $ano, $i))->delete();
        }

        $interv = Intervencao::create(['equipamento_id' => $this->equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $relatorio = app(GeradorRelatorio::class)->criarParaIntervencao($interv);

        $this->assertTrue($relatorio->exists);                          // gravou sem rebentar
        $this->assertSame(sprintf('%d/0011', $ano), $relatorio->numero); // acima de tudo o que existe
    }

    public function test_relatorio_existente_nao_regenera_numero(): void
    {
        $ano = now()->year;
        $existente = $this->relatorioComNumero(sprintf('%d/0005', $ano));
        $interv = $existente->intervencao;

        // Chamar de novo para a mesma intervenção devolve o mesmo relatório/número.
        $r = app(GeradorRelatorio::class)->criarParaIntervencao($interv);
        $this->assertSame($existente->id, $r->id);
        $this->assertSame(sprintf('%d/0005', $ano), $r->numero);
    }
}
