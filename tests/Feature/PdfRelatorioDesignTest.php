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
use Tests\TestCase;

// Redesign do PDF (set. 2026): a 1.ª página diz ao cliente o VEREDICTO sem ler as fichas —
// selo Conforme / Com anomalias, tabela de resultado por equipamento, lista de anomalias e
// recomendações; rodapé com nº de página em todas as páginas; marcas ✓/✗/– nas fichas.
class PdfRelatorioDesignTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $cliente = Cliente::create(['nome' => 'ACME Lda', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC', 'morada' => 'Rua X 1']);
        $ups = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-UPS-1', 'localizacao_instalacao' => 'Sala técnica']);
        $inc = Equipamento::create(['local_id' => $local->id, 'tipo' => 'incendio', 'estado' => 'operacional', 'fabricante' => 'Siemens', 'modelo' => 'FC722', 'numero_serie' => 'SN-INC-1']);
        $i = Intervencao::create(['equipamento_id' => $ups->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $r = Relatorio::create(['intervencao_id' => $i->id, 'numero' => '2026/9600', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);

        return [$r, $i, $ups, $inc];
    }

    private function html(Relatorio $r): string
    {
        return view('pdf.relatorio', ['relatorio' => $r->fresh(), 'fotos' => []])->render();
    }

    // ---- Modelo: anomalias() e resultado() ----------------------------------------------

    public function test_ficha_ups_lista_as_anomalias_e_da_o_veredicto(): void
    {
        [, $i, $ups] = $this->cenario();

        $ficha = FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $ups->id, 'tipo_equipamento' => 'ups',
            'verificacoes' => ['ventiladores' => ['estado' => 'nok', 'nota' => 'Ruído anómalo'], 'limpeza' => ['estado' => 'ok', 'nota' => '']],
            'baterias_funcionamento' => 'nok', 'temperatura' => 27]);

        $anomalias = $ficha->anomalias();
        $this->assertSame(['Ventiladores', 'Baterias em funcionamento', 'Temperatura da UPS acima de 25 °C'], array_column($anomalias, 'item'));
        $this->assertSame('Ruído anómalo', $anomalias[0]['nota']);
        $this->assertSame('anomalias', $ficha->resultado());

        // Tudo OK → conforme; nada marcado → sem dados (não se afirma conformidade do nada).
        $ficha->update(['verificacoes' => ['limpeza' => ['estado' => 'ok', 'nota' => '']], 'baterias_funcionamento' => 'ok', 'temperatura' => 22]);
        $this->assertSame([], $ficha->fresh()->anomalias());
        $this->assertSame('conforme', $ficha->fresh()->resultado());

        $ficha->update(['verificacoes' => [], 'baterias_funcionamento' => null]);
        $this->assertSame('sem_dados', $ficha->fresh()->resultado());
    }

    public function test_ficha_sadei_lista_os_ko_das_seccoes_cilindros_e_final(): void
    {
        [, $i, , $inc] = $this->cenario();

        $ficha = FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $inc->id, 'tipo_equipamento' => 'incendio', 'sadei' => [
            'central' => ['limpeza' => ['estado' => 'ok', 'nota' => '']],
            'aspiracao' => ['filtro' => ['estado' => 'ko', 'nota' => 'Filtro substituído']],
            'sensores' => ['outro' => ['estado' => 'na', 'nota' => '']],
            'cilindros' => [['identificacao' => 'C1', 'estado' => 'ko'], ['identificacao' => 'C2', 'estado' => 'ok']],
            'final_automatico' => 'ko',
        ]]);

        $itens = array_column($ficha->anomalias(), 'item');
        $this->assertSame(['Filtro', 'Cilindro C1', 'Equipamento em modo automático, com a solenoide colocada, e a funcionar corretamente'], $itens);
        $this->assertSame('anomalias', $ficha->resultado());

        // N/A não é anomalia nem "sem dados".
        $ficha->update(['sadei' => ['sensores' => ['outro' => ['estado' => 'na', 'nota' => '']]]]);
        $this->assertSame('conforme', $ficha->fresh()->resultado());
    }

    // ---- 1.ª página: veredicto, resultado por equipamento, anomalias, recomendações -----

    public function test_primeira_pagina_resume_o_resultado_por_equipamento(): void
    {
        [$r, $i, $ups, $inc] = $this->cenario();
        FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $ups->id, 'tipo_equipamento' => 'ups', 'marca' => 'Riello', 'modelo' => 'NPW', 'serie' => 'SN-UPS-1',
            'verificacoes' => ['ventiladores' => ['estado' => 'nok', 'nota' => 'Ruído anómalo']], 'recomendacao' => 'Substituir ventiladores', 'prioridade' => 'Alta']);
        FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $inc->id, 'tipo_equipamento' => 'incendio', 'serie' => 'SN-INC-1',
            'sadei' => ['central' => ['limpeza' => ['estado' => 'ok', 'nota' => '']], 'tipo_manutencao' => 'semestral']]);

        $html = $this->html($r);

        // Selo global na faixa (uma ficha com anomalias chega) + um selo por ficha no resumo.
        $this->assertStringContainsString('selo-anomalias">Com anomalias', $html);
        $this->assertStringContainsString('Resultado da intervenção', $html);
        $this->assertStringContainsString('<b>UPS</b> · Riello NPW', $html);
        $this->assertStringContainsString('<b>Deteção de incêndio</b>', $html);
        $this->assertStringContainsString('Sala técnica', $html);               // local de instalação
        $this->assertStringContainsString('selo-conforme">Conforme', $html);    // a ficha SADEI está toda OK

        // Anomalias e recomendações listadas, com o equipamento a que pertencem.
        $this->assertStringContainsString('Anomalias detetadas (1)', $html);
        $this->assertStringContainsString('✗ Ventiladores — Ruído anómalo', $html);
        $this->assertStringContainsString('UPS · SN-UPS-1', $html);
        $this->assertStringContainsString('Recomendações e próximos passos', $html);
        $this->assertStringContainsString('Substituir ventiladores', $html);
        $this->assertStringContainsString('Prioridade alta', $html);

        // Com fichas, os extras do equipamento (localização, "também cobertos") não se repetem.
        $this->assertStringNotContainsString('Localização da instalação', $html);
        $this->assertStringNotContainsString('Também cobertos', $html);

        // Tipo de manutenção selecionado sai com ✓ (não ✗); ✗ só para KO/NOK.
        $this->assertStringContainsString('Semestral</td><td class="cel-ok">✓', $html);
        $this->assertSame(1, substr_count($html, 'cel-nok">✗'));
    }

    public function test_tudo_conforme_da_selo_verde_e_nao_ha_caixa_de_anomalias(): void
    {
        [$r, $i, $ups] = $this->cenario();
        FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $ups->id, 'tipo_equipamento' => 'ups',
            'verificacoes' => ['limpeza' => ['estado' => 'ok', 'nota' => '']], 'carga_a_funcionar' => 'ok']);

        $html = $this->html($r);

        $this->assertStringContainsString('selo-conforme">Conforme', $html);
        $this->assertStringNotContainsString('Com anomalias', $html);
        $this->assertStringNotContainsString('Anomalias detetadas', $html);
        $this->assertStringNotContainsString('Recomendações e próximos passos', $html); // sem recomendação
    }

    public function test_sem_fichas_nao_ha_veredicto_nem_resumo(): void
    {
        [$r] = $this->cenario();

        $html = $this->html($r);

        $this->assertStringNotContainsString('Resultado da intervenção', $html);
        $this->assertStringNotContainsString('class="selo', $html);
        $this->assertStringContainsString('Intervenção individual', $html);   // sem contrato → cartão de âmbito
        $this->assertStringNotContainsString('>Contrato<', $html);
    }

    // ---- A 1.ª página adapta-se ao resumo ------------------------------------------------

    public function test_primeira_ficha_segue_na_pagina_do_resumo_quando_ele_e_curto_ou_nao_existe(): void
    {
        [$r, $i, $ups, $inc] = $this->cenario();
        FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $ups->id, 'tipo_equipamento' => 'ups']);
        FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $inc->id, 'tipo_equipamento' => 'incendio']);

        // Sem textos: a 1.ª ficha segue logo a seguir ao resumo (senão a página ficava em branco);
        // a 2.ª começa em página nova como sempre.
        $html = $this->html($r);
        $this->assertSame(1, substr_count($html, '<div class="ficha-pagina ficha-segue">'));
        $this->assertSame(1, substr_count($html, '<div class="ficha-pagina">'));
        $this->assertStringNotContainsString('Descrição da intervenção', $html); // secção omitida sem textos

        // Resumo curto: idem.
        $i->update(['trabalho_realizado' => 'Limpeza e verificação geral.']);
        $this->assertSame(1, substr_count($this->html($r), '<div class="ficha-pagina ficha-segue">'));

        // Resumo grande (enche a 1.ª página): a 1.ª ficha passa para página nova.
        $i->update(['trabalho_realizado' => str_repeat('Medição das tensões e correntes por fase, verificação do equilíbrio de cargas. ', 25)]);
        $html = $this->html($r);
        $this->assertStringNotContainsString('<div class="ficha-pagina ficha-segue">', $html);
        $this->assertSame(2, substr_count($html, '<div class="ficha-pagina">'));
    }

    // ---- Rodapé em todas as páginas + fotos com título colado ----------------------------

    public function test_rodape_fixo_com_numero_de_pagina_e_fotos_com_titulo_colado(): void
    {
        [$r, $i, $ups] = $this->cenario();
        FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $ups->id, 'tipo_equipamento' => 'ups']);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $html = view('pdf.relatorio', [
            'relatorio' => $r->fresh(), 'fotosGerais' => [],
            'fotosPorEquipamento' => [$ups->id => ['nome' => 'SN-UPS-1', 'fotos' => [$png, $png, $png, $png]]],
        ])->render();

        $this->assertStringContainsString('class="rodape-fixo"', $html);
        $this->assertStringContainsString('content: counter(page)', $html);
        $this->assertStringContainsString('Relatório 2026/9600 · ACME Lda', $html);

        // Título "Fotografias" dentro do bloco que não se parte (com a 1.ª linha de 3 fotos);
        // a 4.ª foto vai numa 2.ª tabela.
        $this->assertMatchesRegularExpression('#<div class="junto"\s*>\s*<div class="ficha-seccao">Fotografias</div>#', $html);
        $this->assertSame(2, substr_count($html, '<table class="fotos-tab">'));
    }
}
