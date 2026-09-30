<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Anexo;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// 27.ª revisão de segurança (partes sem efeito visível dos achados médios 1 e 3):
//  - fotos removidas são ARQUIVADAS (não apagadas) e ficam na auditoria;
//  - o PDF guardado deixa de valer quando o relatório muda (fotos, gravação) — o próximo pedido
//    gera-o de novo, em vez de mandar o antigo ao cliente;
//  - cada rascunho tem o seu ficheiro de PDF (antes todos iam para «relatorios/.pdf»).
class RelatorioArquivoPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    private function cenario(EstadoRelatorio $estado = EstadoRelatorio::Finalizado): array
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-ARQ']);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => $estado === EstadoRelatorio::Rascunho ? null : '2026/9500',
            'data' => now(), 'estado' => $estado, 'pdf_path' => 'relatorios/antigo.pdf']);
        Storage::put('relatorios/antigo.pdf', 'PDF ANTIGO');
        Storage::put('anexos/foto-1.jpg', 'FOTO');
        $foto = $interv->anexos()->create(['nome_ficheiro' => 'foto-1.jpg', 'storage_key' => 'anexos/foto-1.jpg', 'mime' => 'image/jpeg', 'tamanho' => 4, 'no_relatorio' => true]);

        return [$admin, $relatorio, $foto];
    }

    public function test_foto_removida_fica_arquivada_e_na_auditoria(): void
    {
        [$admin, $relatorio, $foto] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->call('removerAnexoExistente', $foto->id);

        $this->assertNull(Anexo::find($foto->id));                       // sai do relatório
        Storage::assertMissing('anexos/foto-1.jpg');
        $arquivo = collect(Storage::allFiles('arquivo'))->first(fn ($f) => str_ends_with($f, 'anexos/foto-1.jpg'));
        $this->assertNotNull($arquivo, 'A foto devia ter ido para o arquivo.');
        $this->assertSame('FOTO', Storage::get($arquivo));

        $registo = Auditoria::where('acao', 'ficheiro_arquivado')->firstOrFail();
        $this->assertSame('foto_removida', $registo->detalhe['motivo']);
        $this->assertSame($arquivo, $registo->detalhe['arquivo']);
        $this->assertSame($admin->id, $registo->user_id);

        $this->assertNull($relatorio->fresh()->pdf_path);                 // o PDF antigo deixa de valer
    }

    public function test_esconder_uma_foto_invalida_o_pdf(): void
    {
        [$admin, $relatorio, $foto] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->call('alternarFotoNoRelatorio', $foto->id);

        $this->assertFalse($foto->fresh()->no_relatorio);
        $this->assertNull($relatorio->fresh()->pdf_path);
    }

    public function test_gravar_invalida_o_pdf(): void
    {
        [$admin, $relatorio] = $this->cenario(EstadoRelatorio::Rascunho);

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $this->assertNull($relatorio->fresh()->pdf_path);
    }

    public function test_cada_rascunho_tem_o_seu_ficheiro_de_pdf(): void
    {
        [, $r1] = $this->cenario(EstadoRelatorio::Rascunho);
        $r2 = Intervencao::create(['equipamento_id' => $r1->intervencao->equipamento_id, 'tipo' => 'corretiva', 'estado' => 'em_curso', 'data_inicio' => now()])->garantirRascunho();

        $gerador = app(GeradorRelatorio::class);
        $gerador->gerarPdf($r1);
        $gerador->gerarPdf($r2);

        $this->assertSame('relatorios/rascunho-'.$r1->id.'.pdf', $r1->fresh()->pdf_path);
        $this->assertSame('relatorios/rascunho-'.$r2->id.'.pdf', $r2->fresh()->pdf_path);
        Storage::assertMissing('relatorios/.pdf');
    }
}
