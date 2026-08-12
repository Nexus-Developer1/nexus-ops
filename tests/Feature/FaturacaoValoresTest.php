<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Fatura;
use App\Livewire\Clientes\Faturacao;
use App\Models\Cliente;
use App\Models\LinhaFatura;
use App\Models\User;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Valores das faturas do PHC (12/08): o sync passa a trazer preço/desconto/total da linha
// (fi) + totais do documento sem/com IVA e a flag de anulada (ft). Visíveis SÓ PARA ADMIN
// — técnicos continuam a ver o histórico (documentos, datas, séries) sem €.
class FaturacaoValoresTest extends TestCase
{
    use RefreshDatabase;

    private function linhaComValores(string $clienteNo): LinhaFatura
    {
        return LinhaFatura::create([
            'id_erp' => 'VAL-1', 'cliente_no' => $clienteNo, 'data' => now()->subMonth(),
            'nmdoc' => 'Factura', 'fno' => 42, 'design' => 'UPS RIELLO NPW', 'series' => 'S1', 'qtt' => 2,
            'preco_unitario' => 350.5, 'desconto' => 5, 'total_linha' => 665.95,
            'total_documento' => 800.00, 'total_documento_iva' => 984.00, 'anulada' => false,
        ]);
    }

    public function test_sync_grava_os_valores_e_a_flag_de_anulada(): void
    {
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver());

        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 14])->assertSuccessful();

        // Os valores do Fake são determinísticos: todas as linhas têm unitário/total da
        // linha/totais do documento, e 1 em cada 7 documentos vem anulado.
        $this->assertTrue(LinhaFatura::count() > 0);
        $this->assertSame(0, LinhaFatura::whereNull('preco_unitario')->count());
        $this->assertSame(0, LinhaFatura::whereNull('total_documento_iva')->count());
        $this->assertTrue(LinhaFatura::where('anulada', true)->exists());

        // Idempotência: 2.ª corrida sem mudanças no ERP salta tudo (hash inclui os valores).
        $this->artisan('erp:sincronizar-faturacao', ['--limit' => 14])
            ->expectsOutputToContain('0 criadas, 0 atualizadas')
            ->assertSuccessful();
    }

    public function test_admin_ve_valores_na_listagem_e_no_detalhe(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);
        $linha = $this->linhaComValores('148');

        // Listagem: coluna do total do documento + cartão dos últimos 12 meses.
        Livewire::actingAs($admin)->test(Faturacao::class, ['cliente' => $cliente])
            ->assertSee('Total doc.')
            ->assertSee('984,00 €')
            ->assertSee('Faturado (últimos 12 meses)');

        // Detalhe: unitário/desconto/total da linha + totais do documento.
        Livewire::actingAs($admin)->test(Fatura::class, ['cliente' => $cliente, 'linha' => $linha])
            ->assertSee('350,50 €')
            ->assertSee('665,95 €')
            ->assertSee('800,00 €')
            ->assertSee('984,00 €');
    }

    public function test_tecnico_nao_ve_valores(): void
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);
        $linha = $this->linhaComValores('148');

        // O histórico continua visível (documento, série), mas sem nenhum €.
        Livewire::actingAs($tecnico)->test(Faturacao::class, ['cliente' => $cliente])
            ->assertSee('Factura 42')
            ->assertDontSee('Total doc.')
            ->assertDontSee('984,00')
            ->assertDontSee('Faturado (últimos 12 meses)');

        Livewire::actingAs($tecnico)->test(Fatura::class, ['cliente' => $cliente, 'linha' => $linha])
            ->assertSee('UPS RIELLO NPW')
            ->assertDontSee('350,50')
            ->assertDontSee('984,00');
    }

    public function test_anulada_fica_marcada_para_toda_a_equipa(): void
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);
        $linha = $this->linhaComValores('148');
        $linha->update(['anulada' => true]);

        // A etiqueta não é informação monetária — o técnico também a vê (o rasto fica).
        Livewire::actingAs($tecnico)->test(Faturacao::class, ['cliente' => $cliente])
            ->assertSee('Anulada');

        Livewire::actingAs($tecnico)->test(Fatura::class, ['cliente' => $cliente, 'linha' => $linha])
            ->assertSee('Anulada no PHC');
    }

    public function test_cartao_12_meses_exclui_anuladas_e_conta_por_documento(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);

        // Documento A: 2 linhas do MESMO documento (o total conta UMA vez) — 984 €.
        $this->linhaComValores('148');
        LinhaFatura::create(['id_erp' => 'VAL-2', 'cliente_no' => '148', 'data' => now()->subMonth(),
            'nmdoc' => 'Factura', 'fno' => 42, 'design' => 'Bateria', 'series' => 'S2', 'qtt' => 1,
            'total_documento_iva' => 984.00, 'anulada' => false]);
        // Documento B: anulado — fica de fora do total.
        LinhaFatura::create(['id_erp' => 'VAL-3', 'cliente_no' => '148', 'data' => now()->subMonths(2),
            'nmdoc' => 'Factura', 'fno' => 43, 'design' => 'UPS', 'series' => 'S3', 'qtt' => 1,
            'total_documento_iva' => 500.00, 'anulada' => true]);
        // Documento C: com mais de 12 meses — fora da janela.
        LinhaFatura::create(['id_erp' => 'VAL-4', 'cliente_no' => '148', 'data' => now()->subMonths(18),
            'nmdoc' => 'Factura', 'fno' => 44, 'design' => 'PDU', 'series' => 'S4', 'qtt' => 1,
            'total_documento_iva' => 300.00, 'anulada' => false]);

        Livewire::actingAs($admin)->test(Faturacao::class, ['cliente' => $cliente])
            ->assertViewHas('totais', fn ($t) => $t['ano'] === 984.0 && $t['docs'] === 1);
    }
}
