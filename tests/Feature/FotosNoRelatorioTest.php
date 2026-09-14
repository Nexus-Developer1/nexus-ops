<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Anexo;
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

// Escolher que fotos saem no PDF do cliente (pedido da equipa, set. 2026). Cada foto gravada tem
// um interruptor «No relatório / Só interno»: desligada fica guardada na intervenção (registo
// interno) mas não entra no PDF. Por defeito ligado. Só se mexe em fotos da própria intervenção.
class FotosNoRelatorioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Intervencao $intervencao;

    private Relatorio $relatorio;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-FOTO']);
        $this->intervencao = Intervencao::create(['equipamento_id' => $equipamento->id, 'tipo' => 'preventiva', 'estado' => 'em_curso', 'tecnico_id' => $this->admin->id, 'data_inicio' => now()]);
        $this->relatorio = Relatorio::create(['intervencao_id' => $this->intervencao->id, 'numero' => '2026/9400', 'data' => now(), 'estado' => EstadoRelatorio::Rascunho]);
    }

    // PNG 1×1 real, para o gerador o embeber como data URI.
    private function foto(Intervencao $i, string $nome, bool $noRelatorio = true): Anexo
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        Storage::disk()->put("anexos/$nome", $png);

        return $i->anexos()->create([
            'nome_ficheiro' => $nome, 'storage_key' => "anexos/$nome", 'mime' => 'image/png', 'tamanho' => strlen($png),
            'equipamento_id' => $i->equipamento_id, 'no_relatorio' => $noRelatorio,
        ]);
    }

    public function test_nasce_ligada_e_o_editor_mostra_o_interruptor(): void
    {
        $a = $this->foto($this->intervencao, 'quadro.png');
        $this->assertTrue($a->fresh()->no_relatorio); // por defeito sai no relatório

        Livewire::actingAs($this->admin)->test(Novo::class, ['relatorio' => $this->relatorio])
            ->assertSee('No relatório')
            ->assertSeeHtml('wire:click="alternarFotoNoRelatorio('.$a->id.')"');
    }

    public function test_desligar_guarda_a_foto_mas_tira_a_do_pdf(): void
    {
        $sai = $this->foto($this->intervencao, 'sai.png');
        $interna = $this->foto($this->intervencao, 'interna.png');

        Livewire::actingAs($this->admin)->test(Novo::class, ['relatorio' => $this->relatorio])
            ->call('alternarFotoNoRelatorio', $interna->id)
            ->assertSee('Só interno');

        $this->assertFalse($interna->fresh()->no_relatorio);
        $this->assertTrue($sai->fresh()->no_relatorio);
        $this->assertSame(2, $this->intervencao->anexos()->count()); // continua guardada

        // O PDF só leva a que está ligada: uma foto do equipamento, não duas.
        $this->assertCount(1, $this->fotosDoPdf());

        // Voltar a ligar volta a pô-la no PDF.
        Livewire::actingAs($this->admin)->test(Novo::class, ['relatorio' => $this->relatorio])
            ->call('alternarFotoNoRelatorio', $interna->id);
        $this->assertTrue($interna->fresh()->no_relatorio);
        $this->assertCount(2, $this->fotosDoPdf());
    }

    /** As fotos (data URIs) que a vista do PDF recebe para o equipamento da intervenção. */
    private function fotosDoPdf(): array
    {
        $dados = app(GeradorRelatorio::class)->dadosDoPdf($this->relatorio);

        return $dados['fotosPorEquipamento'][$this->intervencao->equipamento_id]['fotos'] ?? [];
    }

    public function test_nao_mexe_em_fotos_de_outra_intervencao(): void
    {
        $outra = Intervencao::create(['equipamento_id' => $this->intervencao->equipamento_id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $alheia = $this->foto($outra, 'alheia.png');

        Livewire::actingAs($this->admin)->test(Novo::class, ['relatorio' => $this->relatorio])
            ->call('alternarFotoNoRelatorio', $alheia->id);

        $this->assertTrue($alheia->fresh()->no_relatorio); // intocada
    }
}
