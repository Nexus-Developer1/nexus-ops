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
}
