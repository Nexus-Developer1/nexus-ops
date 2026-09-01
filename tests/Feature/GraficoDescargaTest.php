<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\FichaMedicao;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

// Gráfico do teste de descarga (Vbat+ / Vbat− ao longo dos tempos da tabela): SVG puro gerado
// no servidor — inline no editor; no PDF vai como IMAGEM (data URI svg+xml), porque o dompdf
// não desenha SVG inline (despejava só os rótulos numa linha). Só aparece com pelo menos 2
// valores numéricos; células vazias são ignoradas; vírgula decimal aceite.
class GraficoDescargaTest extends TestCase
{
    use RefreshDatabase;

    private function relatorioComFicha(array $testeDescarga): Relatorio
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $e = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-1']);
        $i = Intervencao::create(['equipamento_id' => $e->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $e->id, 'tipo_equipamento' => 'ups',
            'marca' => 'Riello', 'modelo' => 'NPW', 'serie' => 'SN-1', 'teste_descarga' => $testeDescarga]);

        return Relatorio::create(['intervencao_id' => $i->id, 'numero' => '2026/9400', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);
    }

    public function test_pdf_desenha_o_grafico_com_as_duas_series(): void
    {
        // Curva típica: desce, estabiliza e recupera no fim (vírgula decimal à mistura).
        $relatorio = $this->relatorioComFicha([
            'inicio' => ['vbat_pos' => '270.6', 'vbat_neg' => '270.2', 'aut_pct' => '100', 'aut_min' => '19'],
            '1' => ['vbat_pos' => '262,4', 'vbat_neg' => '262.6'],
            '2' => ['vbat_pos' => '256.6', 'vbat_neg' => '256.6'],
            '5' => ['vbat_pos' => '250.8', 'vbat_neg' => '251.4'],
            '20' => ['vbat_pos' => '251.6', 'vbat_neg' => '251.4'],
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertStringContainsString('Gráfico do teste de descarga', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);   // vai como imagem
        $this->assertStringNotContainsString('<svg', $html);                     // nunca inline no PDF
        $svg = $this->svgEmbebido($html);
        $this->assertSame(2, substr_count($svg, '<polyline'));                   // Vbat+ e Vbat−
        $this->assertSame(10, substr_count($svg, '<circle'));                    // 5 pontos por série
        $this->assertStringContainsString('stroke="#2563eb"', $svg);             // Vbat+ azul
        $this->assertStringContainsString('stroke="#ea580c"', $svg);             // Vbat− laranja
        $this->assertStringContainsString('Vbat +', $svg);
        $this->assertStringContainsString('20 min', $svg);                        // rótulo do eixo X
    }

    public function test_sem_valores_suficientes_nao_ha_grafico(): void
    {
        $vazio = $this->relatorioComFicha([]);
        $htmlVazio = view('pdf.relatorio', ['relatorio' => $vazio, 'fotos' => []])->render();
        $this->assertStringNotContainsString('data:image/svg+xml', $htmlVazio);
        $this->assertStringNotContainsString('Gráfico do teste de descarga', $htmlVazio);

        // Um único valor não chega para desenhar uma curva.
        $html = Blade::render('<x-relatorios.grafico-descarga :dados="$d" />', ['d' => ['inicio' => ['vbat_pos' => '270']]]);
        $this->assertStringNotContainsString('<svg', $html);

        // Texto não numérico é ignorado.
        $html = Blade::render('<x-relatorios.grafico-descarga :dados="$d" />', ['d' => ['inicio' => ['vbat_pos' => 'n/a'], '1' => ['vbat_pos' => '—']]]);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function test_dois_valores_da_mesma_serie_chegam(): void
    {
        $html = Blade::render('<x-relatorios.grafico-descarga :dados="$d" />',
            ['d' => ['inicio' => ['vbat_pos' => '270'], '10' => ['vbat_pos' => '250']]]);
        $this->assertStringContainsString('<svg', $html);
        $this->assertSame(1, substr_count($html, '<polyline'));
    }
}
