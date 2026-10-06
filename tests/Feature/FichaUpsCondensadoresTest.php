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

// Verificações da ficha UPS: "Condensadores DC" e "Condensadores AC" entram a seguir a
// "Aperto/estado das ligações" (out. 2026). Aparecem no formulário, gravam-se, vão ao PDF e um
// NOK conta como anomalia. Uma ficha gravada ANTES de existirem não as mostra no PDF — um
// relatório antigo, se for regenerado, sai como foi emitido.
class FichaUpsCondensadoresTest extends TestCase
{
    use RefreshDatabase;

    private function ficha(array $verificacoes): FichaMedicao
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $e = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-1']);
        $i = Intervencao::create(['equipamento_id' => $e->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        Relatorio::create(['intervencao_id' => $i->id, 'numero' => '2026/9600', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);

        return FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $e->id, 'tipo_equipamento' => 'ups',
            'marca' => 'Riello', 'modelo' => 'NPW', 'serie' => 'SN-1', 'verificacoes' => $verificacoes]);
    }

    private function pdf(FichaMedicao $ficha): string
    {
        $relatorio = Relatorio::where('intervencao_id', $ficha->intervencao_id)->firstOrFail();

        return view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();
    }

    public function test_as_duas_linhas_entram_a_seguir_as_ligacoes(): void
    {
        $chaves = array_keys(FichaMedicao::VERIFICACOES);
        $depois = array_search('ligacoes', $chaves, true);

        $this->assertSame('condensadores_dc', $chaves[$depois + 1]);
        $this->assertSame('condensadores_ac', $chaves[$depois + 2]);
        $this->assertSame('tensao_entrada_saida', $chaves[$depois + 3]);
        $this->assertSame('Condensadores DC', FichaMedicao::VERIFICACOES['condensadores_dc']);
        $this->assertSame('Condensadores AC', FichaMedicao::VERIFICACOES['condensadores_ac']);
    }

    public function test_aparecem_no_formulario_e_gravam(): void
    {
        $html = Blade::render('<x-relatorios.ficha-ups.verificacoes prefixo="fichas.1" />');
        $this->assertStringContainsString('Condensadores DC', $html);
        $this->assertStringContainsString('fichas.1.verificacoes.condensadores_ac.estado', $html);

        // Ida e volta pelo formulário: o estado e a nota ficam.
        $dados = FichaMedicao::estruturaVazia();
        $dados['verificacoes']['condensadores_dc'] = ['estado' => 'nok', 'nota' => 'Inchados'];
        $attrs = FichaMedicao::atributosDeFormulario($dados);
        $this->assertSame(['estado' => 'nok', 'nota' => 'Inchados'], $attrs['verificacoes']['condensadores_dc']);
        $this->assertArrayHasKey('condensadores_ac', $attrs['verificacoes']); // ficha nova grava todos os itens
    }

    public function test_vao_ao_pdf_e_o_nok_conta_como_anomalia(): void
    {
        $verificacoes = [];
        foreach (array_keys(FichaMedicao::VERIFICACOES) as $k) {
            $verificacoes[$k] = ['estado' => 'ok', 'nota' => null];
        }
        $verificacoes['condensadores_ac'] = ['estado' => 'nok', 'nota' => 'Substituir'];
        $ficha = $this->ficha($verificacoes);

        $html = $this->pdf($ficha);
        $this->assertStringContainsString('Condensadores DC', $html);
        $this->assertStringContainsString('Condensadores AC', $html);

        $anomalias = collect($ficha->anomalias())->pluck('item')->all();
        $this->assertContains('Condensadores AC', $anomalias);
        $this->assertNotContains('Condensadores DC', $anomalias);
    }

    public function test_ficha_antiga_sem_os_itens_nao_os_mostra_no_pdf(): void
    {
        // Gravada antes de out. 2026: as verificações não têm as chaves dos condensadores.
        $antigas = [];
        foreach (array_keys(FichaMedicao::VERIFICACOES) as $k) {
            if (! str_starts_with($k, 'condensadores_')) {
                $antigas[$k] = ['estado' => 'ok', 'nota' => null];
            }
        }
        $html = $this->pdf($this->ficha($antigas));

        $this->assertStringContainsString('Aperto/estado das ligações', $html);
        $this->assertStringNotContainsString('Condensadores DC', $html);
        $this->assertStringNotContainsString('Condensadores AC', $html);
    }
}
