<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\FichaMedicao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Assinaturas na ficha SADEI (cliente + técnico): desenhadas no ecrã, guardadas como PNG no
// object storage (nunca na BD) e impressas no PDF. Payloads inválidos são ignorados.
class RelatorioAssinaturaSadeiTest extends TestCase
{
    use RefreshDatabase;

    /** PNG 1x1 válido, como o que o canvas produz. */
    private function pngDataUri(): string
    {
        return 'data:image/png;base64,' . base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    private function cenario(): array
    {
        Storage::fake('local');
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'incendio', 'estado' => 'operacional', 'numero_serie' => 'SAD-9']);

        return [$tecnico, $equip];
    }

    public function test_assinaturas_gravam_no_storage_e_a_ficha_guarda_so_a_chave(): void
    {
        [$tecnico, $equip] = $this->cenario();

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$equip->id}.assinatura_cliente", $this->pngDataUri())
            ->set("fichas.{$equip->id}.assinatura_cliente_nome", 'Sr. Cliente')
            ->set("fichas.{$equip->id}.assinatura_tecnico", $this->pngDataUri())
            ->set("fichas.{$equip->id}.assinatura_tecnico_nome", 'Téc')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $ficha = FichaMedicao::firstOrFail();
        $this->assertNotNull($ficha->assinatura_cliente_key);
        $this->assertNotNull($ficha->assinatura_tecnico_key);
        $this->assertSame('Sr. Cliente', $ficha->assinatura_cliente_nome);
        $this->assertNotNull($ficha->assinado_em);

        // O PNG está no storage (não na BD) e as chaves apontam para ficheiros reais.
        Storage::disk('local')->assertExists($ficha->assinatura_cliente_key);
        Storage::disk('local')->assertExists($ficha->assinatura_tecnico_key);
        $this->assertStringStartsWith('assinaturas/fichas/', $ficha->assinatura_cliente_key);
    }

    public function test_payload_invalido_e_recusado_com_erro_visivel(): void
    {
        [$tecnico, $equip] = $this->cenario();

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            // Nem PNG, nem data URI de imagem: um SVG com script e um texto qualquer.
            ->set("fichas.{$equip->id}.assinatura_cliente", 'data:image/svg+xml;base64,' . base64_encode('<svg onload=alert(1)>'))
            ->set("fichas.{$equip->id}.assinatura_tecnico", 'data:image/png;base64,ISTO-NAO-E-BASE64')
            ->set("fichas.{$equip->id}.sadei.final_automatico", 'ok')
            ->call('guardarRascunho')
            // Numa folha que é prova de execução, o técnico TEM de saber que não ficou assinada.
            ->assertHasErrors("fichas.{$equip->id}.assinatura_cliente")
            ->assertHasErrors("fichas.{$equip->id}.assinatura_tecnico");

        $ficha = FichaMedicao::firstOrFail();
        $this->assertNull($ficha->assinatura_cliente_key);
        $this->assertNull($ficha->assinatura_tecnico_key);
    }

    public function test_png_gigante_e_recusado_bomba_de_descompressao(): void
    {
        [$tecnico, $equip] = $this->cenario();

        // PNG que ANUNCIA 20000×20000 no cabeçalho mas ocupa uns bytes — é assim que uma
        // "bomba de descompressão" se apresenta: pequena no disco, enorme ao descodificar
        // (o DomPDF rebentava a memória/CPU do worker ao gerar o PDF). O header é remendado
        // sobre um PNG 1×1 válido: getimagesizefromstring lê as dimensões daí.
        $png = base64_decode(substr($this->pngDataUri(), strlen('data:image/png;base64,')));
        $png = substr_replace($png, pack('N', 20000), 16, 4);  // largura no IHDR
        $png = substr_replace($png, pack('N', 20000), 20, 4);  // altura no IHDR

        $this->assertLessThan(FichaMedicao::ASSINATURA_MAX_BYTES, strlen($png)); // passaria no limite de tamanho
        $this->assertSame([20000, 20000], array_slice(getimagesizefromstring($png), 0, 2));
        $this->assertNull(FichaMedicao::pngDeAssinatura('data:image/png;base64,' . base64_encode($png)));

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$equip->id}.assinatura_cliente", 'data:image/png;base64,' . base64_encode($png))
            ->call('guardarRascunho')
            ->assertHasErrors("fichas.{$equip->id}.assinatura_cliente");
    }

    public function test_ficha_so_com_assinatura_persiste_e_a_data_nao_muda_ao_gravar_de_novo(): void
    {
        [$tecnico, $equip] = $this->cenario();

        // Só a assinatura (sem preencher mais nada) — a ficha tem de ser criada.
        $c = Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$equip->id}.assinatura_cliente", $this->pngDataUri())
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $ficha = FichaMedicao::firstOrFail();
        $this->assertNotNull($ficha->assinatura_cliente_key);
        $assinadoEm = $ficha->assinado_em;
        $keyOriginal = $ficha->assinatura_cliente_key;

        // Gravar outra vez não regrava o PNG nem empurra a data da assinatura para a frente.
        $this->travel(5)->minutes();
        $c->call('guardarRascunho')->assertHasNoErrors();
        $this->travelBack();

        $ficha->refresh();
        $this->assertSame($keyOriginal, $ficha->assinatura_cliente_key);
        $this->assertEquals($assinadoEm, $ficha->assinado_em);
        Storage::disk('local')->assertExists($keyOriginal);
    }

    public function test_limpar_a_assinatura_apaga_o_png_e_a_ficha_vazia(): void
    {
        [$tecnico, $equip] = $this->cenario();

        $c = Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$equip->id}.assinatura_cliente", $this->pngDataUri())
            ->call('guardarRascunho');

        $key = FichaMedicao::firstOrFail()->assinatura_cliente_key;
        Storage::disk('local')->assertExists($key);

        // Limpar a única coisa que a ficha tinha: a ficha desaparece e o PNG não fica órfão.
        $c->set("fichas.{$equip->id}.assinatura_cliente", 'limpar')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $this->assertSame(0, FichaMedicao::count());
        Storage::disk('local')->assertMissing($key);
    }

    public function test_assinatura_aparece_no_pdf(): void
    {
        [$tecnico, $equip] = $this->cenario();

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$tecnico->id])
            ->set("fichas.{$equip->id}.assinatura_cliente", $this->pngDataUri())
            ->set("fichas.{$equip->id}.assinatura_cliente_nome", 'Sr. Cliente')
            ->call('finalizar')
            ->assertHasNoErrors();

        $relatorio = Relatorio::firstOrFail()->load('intervencao.fichasMedicao');
        $ficha = $relatorio->intervencao->fichasMedicao->first();

        $html = view('pdf.relatorio', [
            'relatorio' => $relatorio,
            'fotos' => [],
            'assinaturasFichas' => [$ficha->id => ['cliente' => $this->pngDataUri()]],
        ])->render();

        $this->assertStringContainsString('Assinaturas', $html);
        $this->assertStringContainsString('Sr. Cliente', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }
}
