<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Lembretes de segurança das verificações periódicas SADEI: "Inibir o sistema antes de
// iniciar" (vermelho, no topo de cada secção) e "Repor em automático no fim" (verde, no
// fim). São instruções ao técnico durante a manutenção — aparecem no editor, NUNCA no PDF.
class FichaSadeiLembretesTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'incendio', 'estado' => 'operacional', 'numero_serie' => 'SAD-1']);

        return [$tecnico, $equip];
    }

    public function test_editor_mostra_inibir_a_vermelho_no_topo_e_repor_a_verde_no_fim(): void
    {
        [$tecnico, $equip] = $this->cenario();

        $html = Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->html();

        // Uma vez por secção periódica (trimestral, semestral, anual).
        $this->assertSame(3, substr_count($html, 'Inibir o sistema antes de iniciar'));
        $this->assertSame(3, substr_count($html, 'Repor em automático no fim'));
        $this->assertMatchesRegularExpression('/text-perigo-\d+[^>]*>Inibir o sistema antes de iniciar/', $html);
        $this->assertMatchesRegularExpression('/text-verde-\d+[^>]*>Repor em automático no fim/', $html);

        // O "Inibir" vem antes da primeira pergunta da secção e o "Repor" depois da última.
        $inibir = strpos($html, 'Inibir o sistema antes de iniciar');
        $primeira = strpos($html, 'Acesso livre e sem obstruções');
        $repor = strpos($html, 'Repor em automático no fim');
        $this->assertLessThan($primeira, $inibir);
        $this->assertGreaterThan($primeira, $repor);
    }

    public function test_pdf_nao_leva_os_lembretes(): void
    {
        [$tecnico, $equip] = $this->cenario();

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$tecnico->id])
            ->set("fichas.{$equip->id}.sadei.trimestral.acessos.estado", 'ok')
            ->call('finalizar')
            ->assertHasNoErrors();

        $relatorio = Relatorio::firstOrFail()->load('intervencao.fichasMedicao');
        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => [], 'assinaturasFichas' => []])->render();

        $this->assertStringContainsString('Verificação trimestral', $html);
        $this->assertStringNotContainsStringIgnoringCase('inibir o sistema', $html);
        $this->assertStringNotContainsStringIgnoringCase('repor em automático', $html);
    }
}
