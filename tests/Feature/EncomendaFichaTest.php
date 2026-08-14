<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Detalhe;
use App\Livewire\Encomendas\Ficha;
use App\Models\Cliente;
use App\Models\Dossier;
use App\Models\User;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use App\Services\Erp\LinhaDossierErp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Ficha do dossiê: cabeçalho da nossa BD + linhas lidas AO VIVO do PHC (não sincronizadas).
// O ERP nunca deve rebentar a ficha — em baixo, abre na mesma com um aviso.
class EncomendaFichaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function dossier(): Dossier
    {
        return Dossier::create([
            'id_erp' => 'BO-ABC-1', 'ndos' => 3, 'nmdos' => 'Proposta', 'obrano' => 42, 'ano' => 2025,
            'data' => now(), 'cliente_no' => '148', 'nome' => 'ACME Lda', 'total_debito' => 500, 'fechada' => false,
        ]);
    }

    public function test_ficha_mostra_o_cabecalho_e_as_linhas_ao_vivo(): void
    {
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver);
        $dossier = $this->dossier();

        Livewire::actingAs($this->admin())->test(Ficha::class, ['dossier' => $dossier])
            ->assertSee('Proposta 42/2025')
            ->assertSee('ACME Lda')
            ->assertSee('em direto do PHC')
            // O Fake devolve linhas determinísticas para qualquer bostamp.
            ->assertViewHas('linhas', fn ($l) => count($l) >= 1)
            ->assertSee('UPS Riello NPW 2000VA');
    }

    public function test_erp_em_baixo_nao_rebenta_a_ficha(): void
    {
        // Driver que falha na leitura das linhas (PHC em baixo).
        $this->app->bind(ErpSyncDriver::class, fn () => new class extends FakeErpDriver
        {
            public function obterLinhasDossier(string $bostamp): iterable
            {
                throw new RuntimeException('SQLSTATE[HY000]: Unable to connect to server');
            }
        });

        Livewire::actingAs($this->admin())->test(Ficha::class, ['dossier' => $this->dossier()])
            ->assertOk()
            ->assertViewHas('erroLinhas', true)
            ->assertSee('Não foi possível obter as linhas do PHC');
    }

    public function test_linha_do_dossier_do_fake_tem_os_campos(): void
    {
        $linhas = iterator_to_array((new FakeErpDriver)->obterLinhasDossier('BO-XYZ'));

        $this->assertInstanceOf(LinhaDossierErp::class, $linhas[0]);
        $this->assertNotNull($linhas[0]->ref);
        $this->assertNotNull($linhas[0]->total);
    }

    public function test_encomendas_do_cliente_aparecem_na_ficha_do_cliente(): void
    {
        $cliente = Cliente::create(['id_erp' => '148', 'nome' => 'ACME Lda', 'ativo' => true]);
        Dossier::create(['id_erp' => 'BO-C-1', 'ndos' => 7, 'nmdos' => 'Encomenda Produção', 'obrano' => 55,
            'ano' => 2025, 'data' => now(), 'cliente_no' => '148', 'nome' => 'ACME Lda', 'total_debito' => 3200, 'fechada' => false]);
        // Dossiê de OUTRO cliente — não deve aparecer.
        Dossier::create(['id_erp' => 'BO-X-1', 'ndos' => 3, 'nmdos' => 'Proposta', 'obrano' => 1,
            'ano' => 2025, 'data' => now(), 'cliente_no' => '999', 'nome' => 'Outro', 'total_debito' => 10, 'fechada' => false]);

        Livewire::actingAs($this->admin())->test(Detalhe::class, ['cliente' => $cliente])
            ->assertViewHas('encomendasTotal', 1)
            ->assertSee('Encomendas e propostas')
            ->assertSee('Encomenda Produção 55/2025');
    }

    public function test_ficha_barrada_ao_cliente_do_portal(): void
    {
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver);
        $dossier = $this->dossier();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $userCliente = User::create(['nome' => 'C', 'email' => 'c@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $cliente->id, 'ativo' => true]);

        $this->actingAs($userCliente)->get(route('encomendas.ficha', $dossier))->assertRedirect(route('portal.dashboard'));
    }
}
