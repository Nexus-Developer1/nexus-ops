<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\FichaMedicao;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// O PDF de um relatório de CONTRATO inclui a ficha de medições (uma por equipamento);
// o INDIVIDUAL mantém a checklist antiga. Nada de valores inventados.
class PdfFichaMedicaoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Contrato, 1: Local, 2: Equipamento, 3: Equipamento} */
    private function contexto(): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala DC', 'morada' => 'Rua X']);
        $e1 = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-1']);
        $e2 = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'MST', 'numero_serie' => 'SN-2']);

        $contrato = Contrato::create([
            'numero' => '2026/0001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);

        return [$contrato, $local, $e1, $e2];
    }

    private function relatorioContrato(Contrato $contrato, Equipamento $principal): Relatorio
    {
        $interv = Intervencao::create([
            'equipamento_id' => $principal->id, 'contrato_id' => $contrato->id,
            'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now(),
        ]);

        return Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/9300', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);
    }

    public function test_pdf_contrato_renderiza_uma_ficha_por_equipamento_com_page_break(): void
    {
        [$contrato, , $e1, $e2] = $this->contexto();
        $relatorio = $this->relatorioContrato($contrato, $e1);
        $interv = $relatorio->intervencao;
        $interv->equipamentosCobertos()->attach($e2->id);

        FichaMedicao::create([
            'intervencao_id' => $interv->id, 'equipamento_id' => $e1->id, 'tipo_equipamento' => 'ups',
            'marca' => 'Riello', 'modelo' => 'NPW', 'serie' => 'SN-1', 'baterias' => '40',
            've_ln_l1' => '231.40', 'carga_l1' => '55.00',
            'verificacoes' => ['acessibilidade' => ['estado' => 'ok', 'nota' => 'acesso livre']],
            'ups_modo_normal' => 'ok',
        ]);
        FichaMedicao::create([
            'intervencao_id' => $interv->id, 'equipamento_id' => $e2->id, 'tipo_equipamento' => 'ups',
            'marca' => 'Riello', 'modelo' => 'MST', 'serie' => 'SN-2',
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        // Duas fichas, cada uma no seu <div class="ficha-pagina"> (CSS: page-break-before → 1.ª incluída).
        $this->assertSame(2, substr_count($html, '<div class="ficha-pagina">'));

        // Secções e valores medidos presentes (sem inventar).
        $this->assertStringContainsString('Valores elétricos', $html);
        $this->assertStringContainsString('Teste de descarga', $html);
        $this->assertStringContainsString('231.40', $html);
        $this->assertStringContainsString('55.00', $html);
        $this->assertStringContainsString('acesso livre', $html);

        // Num relatório de contrato NÃO sai a checklist antiga.
        $this->assertStringNotContainsString('<h2>Checklist</h2>', $html);
    }

    // Legado: relatório SEM fichas (só checklist antiga) continua a mostrar a checklist (fallback).
    public function test_pdf_legado_sem_ficha_mostra_checklist(): void
    {
        [, $local] = $this->contexto();
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-IND']);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida']);
        $etapa = $interv->checklistEtapas()->create(['titulo' => 'Inspeção', 'ordem' => 0]);
        $etapa->itens()->create(['intervencao_id' => $interv->id, 'descricao' => 'Verificar ventoinhas', 'concluido' => true, 'ordem' => 0]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/9301', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertStringContainsString('<h2>Checklist</h2>', $html);
        $this->assertStringContainsString('Verificar ventoinhas', $html);
        $this->assertStringNotContainsString('Valores elétricos', $html); // sem fichas → sem secção de ficha
        $this->assertStringNotContainsString('<div class="ficha-pagina">', $html);
    }

    public function test_pdf_nao_mostra_seccao_diagnostico(): void
    {
        [$contrato, , $e1] = $this->contexto();
        $interv = Intervencao::create([
            'equipamento_id' => $e1->id, 'contrato_id' => $contrato->id, 'tipo' => 'preventiva', 'estado' => 'concluida',
        ]);
        // Diagnóstico legado semeado direto na BD — o campo saiu do fillable (nada o escreve na app).
        DB::table('intervencoes')->where('id', $interv->id)->update([
            'diagnostico' => json_encode(['estado_geral' => 'Operacional', 'carga' => '55', 'prioridade' => 'Normal']),
        ]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/9400', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        // A secção Diagnóstico foi removida do PDF (info passou para a ficha).
        $this->assertStringNotContainsString('<h2>Diagnóstico</h2>', $html);
    }

    // Individual (sem contrato) COM ficha → o PDF mostra a ficha, não a checklist, e não rebenta
    // com o contrato nulo.
    public function test_pdf_individual_com_ficha_mostra_ficha_sem_contrato(): void
    {
        [, $local, $e1] = $this->contexto();
        $interv = Intervencao::create(['equipamento_id' => $e1->id, 'tipo' => 'preventiva', 'estado' => 'concluida']); // sem contrato_id
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/9302', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);

        FichaMedicao::create([
            'intervencao_id' => $interv->id, 'equipamento_id' => $e1->id, 'tipo_equipamento' => 'ups',
            'marca' => 'Riello', 've_ln_l1' => '231.40',
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertStringContainsString('Valores elétricos', $html);           // ficha renderizada
        $this->assertStringContainsString('<div class="ficha-pagina">', $html);
        $this->assertStringContainsString('231.40', $html);
        $this->assertStringNotContainsString('<h2>Checklist</h2>', $html);        // sem checklist quando há ficha
        $this->assertStringNotContainsString('null', $html);                      // contrato nulo não vira "null"
    }

    // Temperatura acima de 25 °C sai marcada a vermelho (classe temp-alerta); até 25, não.
    public function test_pdf_marca_temperatura_acima_de_25_a_vermelho(): void
    {
        [$contrato, , $e1, $e2] = $this->contexto();
        $relatorio = $this->relatorioContrato($contrato, $e1);
        $interv = $relatorio->intervencao;
        $interv->equipamentosCobertos()->attach($e2->id);

        FichaMedicao::create([
            'intervencao_id' => $interv->id, 'equipamento_id' => $e1->id, 'tipo_equipamento' => 'ups',
            'temperatura' => '30.50', // acima do limiar → alerta
        ]);
        FichaMedicao::create([
            'intervencao_id' => $interv->id, 'equipamento_id' => $e2->id, 'tipo_equipamento' => 'ups',
            'temperatura' => '25.00', // no limiar → sem alerta (só ACIMA de 25)
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertSame(1, substr_count($html, 'temp-alerta"')); // só a ficha dos 30.5 °C
        $this->assertStringContainsString('30.50', $html);
        $this->assertStringContainsString('25.00', $html);
    }

    // A recomendação (e prioridade) é por equipamento: sai na página da ficha respetiva.
    public function test_pdf_mostra_recomendacao_por_equipamento(): void
    {
        [$contrato, , $e1] = $this->contexto();
        $relatorio = $this->relatorioContrato($contrato, $e1);

        FichaMedicao::create([
            'intervencao_id' => $relatorio->intervencao->id, 'equipamento_id' => $e1->id, 'tipo_equipamento' => 'ups',
            'recomendacao' => 'Substituir baterias em 2027', 'prioridade' => 'Alta',
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertStringContainsString('Recomendações e próximos passos', $html);
        $this->assertStringContainsString('Substituir baterias em 2027', $html);
        $this->assertStringContainsString('Alta', $html);
    }

    // Ficha sem recomendação → a secção NÃO aparece (não força blocos vazios).
    public function test_pdf_sem_recomendacao_nao_mostra_seccao(): void
    {
        [$contrato, , $e1] = $this->contexto();
        $relatorio = $this->relatorioContrato($contrato, $e1);

        FichaMedicao::create([
            'intervencao_id' => $relatorio->intervencao->id, 'equipamento_id' => $e1->id, 'tipo_equipamento' => 'ups',
            've_ln_l1' => '230.00',
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertStringNotContainsString('Recomendações e próximos passos', $html);
    }

    // CRÍTICO: estado null NÃO marca nem OK nem NOK. Nunca default para NOK.
    public function test_verificacao_null_nao_marca_ok_nem_nok(): void
    {
        [$contrato, , $e1] = $this->contexto();
        $relatorio = $this->relatorioContrato($contrato, $e1);

        FichaMedicao::create([
            'intervencao_id' => $relatorio->intervencao->id, 'equipamento_id' => $e1->id, 'tipo_equipamento' => 'ups',
            'verificacoes' => [
                'limpeza' => ['estado' => 'nok', 'nota' => ''],  // único marcado (NOK)
                'acessibilidade' => ['estado' => null, 'nota' => ''], // não verificado → nada
                // restantes chaves ausentes → null → nada
            ],
            // OK/NOK finais todos null → sem marcas
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        // Nenhuma marca OK em lado nenhum (nada era 'ok').
        $this->assertStringNotContainsString('cel-ok">X', $html);
        // Exatamente uma marca NOK (a limpeza) — o item null NÃO contribuiu.
        $this->assertSame(1, substr_count($html, 'cel-nok">X'));
    }

    public function test_ficha_valor_nulo_fica_vazio_sem_zero_nem_null(): void
    {
        [$contrato, , $e1] = $this->contexto();
        $relatorio = $this->relatorioContrato($contrato, $e1);

        // Ficha praticamente vazia: só um valor preenchido.
        FichaMedicao::create([
            'intervencao_id' => $relatorio->intervencao->id, 'equipamento_id' => $e1->id, 'tipo_equipamento' => 'ups',
            've_ln_l1' => '230.00',
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertStringContainsString('230.00', $html); // o que foi medido aparece
        $this->assertStringNotContainsString('null', $html); // campos nulos não viram "null"
    }

    // Smoke real: o dompdf gera o binário do PDF com a ficha (tabelas + CSS) sem rebentar.
    public function test_dompdf_gera_binario_com_ficha_de_contrato(): void
    {
        \Illuminate\Support\Facades\Storage::fake();
        [$contrato, , $e1] = $this->contexto();
        $relatorio = $this->relatorioContrato($contrato, $e1);

        FichaMedicao::create([
            'intervencao_id' => $relatorio->intervencao->id, 'equipamento_id' => $e1->id, 'tipo_equipamento' => 'ups',
            'marca' => 'Riello', 'serie' => 'SN-1', 've_ln_l1' => '231.40', 'temperatura' => '24.50',
            'config_tipo' => 'modular', 'modulos' => [['modelo' => 'MOD-1', 'sn' => 'M-001']],
            'verificacoes' => ['limpeza' => ['estado' => 'nok', 'nota' => 'poeira']],
            'teste_descarga' => ['inicio' => ['vbat_pos' => '13.60']],
            'ups_modo_normal' => 'ok',
        ]);

        app(\App\Services\GeradorRelatorio::class)->gerarPdf($relatorio);

        $relatorio->refresh();
        $this->assertNotNull($relatorio->pdf_path);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk()->exists($relatorio->pdf_path));
        $this->assertStringStartsWith('%PDF', \Illuminate\Support\Facades\Storage::disk()->get($relatorio->pdf_path));
    }
}
