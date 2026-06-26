<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Botão "PDF" da listagem -> rota relatorios.pdf: gera e serve o PDF no disco
// CONFIGURADO (FILESYSTEM_DISK), sem instanciar o cliente S3 (regressão da region).
class PdfRelatorioTest extends TestCase
{
    use RefreshDatabase;

    public function test_botao_pdf_gera_e_serve_o_pdf_no_disco_configurado(): void
    {
        $admin = User::create([
            'nome' => 'Admin Teste', 'email' => 'admin.pdf@teste.pt', 'password' => 'password',
            'papel' => PapelUtilizador::Admin, 'ativo' => true,
        ]);

        $cliente = Cliente::create(['nome' => 'Cliente PDF', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $intervencao = Intervencao::create([
            'equipamento_id' => $equipamento->id, 'tipo' => 'preventiva', 'estado' => 'concluida',
        ]);
        // Sem pdf_path: a rota gera o PDF on-demand (exercita GeradorRelatorio + Storage).
        $relatorio = Relatorio::create([
            'intervencao_id' => $intervencao->id, 'numero' => '2026/9100',
            'data' => now(), 'estado' => EstadoRelatorio::Finalizado,
        ]);

        $resp = $this->actingAs($admin)->get(route('relatorios.pdf', $relatorio));

        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $resp->getContent());

        $relatorio->refresh();
        $this->assertNotNull($relatorio->pdf_path);
        $this->assertTrue(Storage::disk()->exists($relatorio->pdf_path));

        // Limpa o ficheiro físico gerado (não coberto pelo RefreshDatabase).
        Storage::disk()->delete($relatorio->pdf_path);
    }

    public function test_pdf_mostra_ficha_completa_do_cliente_e_omite_campos_vazios(): void
    {
        $cliente = Cliente::create([
            'nome' => 'ACME Lda', 'nif' => '500100200',
            'morada' => 'Rua do Teste 10', 'codpost' => '1000-001 LISBOA',
            'telefone' => '210000000', 'tlmvl' => ' ', // só um espaço → deve ser omitido
            'email' => 'geral@acme.pt', 'ativo' => true,
        ]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $intervencao = Intervencao::create(['equipamento_id' => $equipamento->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        $relatorio = Relatorio::create([
            'intervencao_id' => $intervencao->id, 'numero' => '2026/9101',
            'data' => now(), 'estado' => EstadoRelatorio::Finalizado,
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        // Campos preenchidos aparecem.
        $this->assertStringContainsString('NIF 500100200', $html);
        $this->assertStringContainsString('Rua do Teste 10', $html);
        $this->assertStringContainsString('1000-001 LISBOA', $html);
        $this->assertStringContainsString('Tel. 210000000', $html);
        $this->assertStringContainsString('geral@acme.pt', $html);

        // Campo vazio (telemóvel = espaço) é omitido — sem linha "Tlm." nem "null".
        $this->assertStringNotContainsString('Tlm.', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    public function test_pdf_mostra_contrato_quando_existe_e_omite_quando_individual(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $contrato = Contrato::create([
            'numero' => '2026/0001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);

        // Relatório DE CONTRATO → mostra a linha do contrato.
        $iContrato = Intervencao::create(['equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        $rContrato = Relatorio::create(['intervencao_id' => $iContrato->id, 'numero' => '2026/9200', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);
        $htmlContrato = view('pdf.relatorio', ['relatorio' => $rContrato, 'fotos' => []])->render();

        $this->assertStringContainsString('Contrato', $htmlContrato);
        $this->assertStringContainsString('2026/0001', $htmlContrato);

        // Relatório INDIVIDUAL (sem contrato) → não mostra contrato nem "null".
        $iIndividual = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida']);
        $rIndividual = Relatorio::create(['intervencao_id' => $iIndividual->id, 'numero' => '2026/9201', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);
        $htmlIndividual = view('pdf.relatorio', ['relatorio' => $rIndividual, 'fotos' => []])->render();

        $this->assertStringNotContainsString('2026/0001', $htmlIndividual); // nº do contrato não aparece
        $this->assertStringNotContainsString('null', $htmlIndividual);
        // A etiqueta "Contrato" não surge no individual (só existe nessa linha).
        $this->assertStringNotContainsString('>Contrato<', $htmlIndividual);
    }
}
