<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Encomendas\Listagem;
use App\Models\Cliente;
use App\Models\Dossier;
use App\Models\User;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\NullErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Aba "Encomendas": listagem dos dossiês do PHC (só leitura), com filtros por tipo, estado,
// ano e pesquisa. Só equipa (o portal do cliente não lhe chega).
class EncomendasListagemTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function dossier(array $extra = []): Dossier
    {
        return Dossier::create(array_merge([
            'id_erp' => 'BO'.uniqid(),
            'ndos' => 3, 'nmdos' => 'Proposta', 'obrano' => 100, 'data' => now(), 'ano' => 2025,
            'cliente_no' => '148', 'nome' => 'ACME Lda', 'total_debito' => 1000, 'fechada' => false,
        ], $extra));
    }

    public function test_lista_e_filtra_por_tipo(): void
    {
        $this->dossier(['ndos' => 3, 'nmdos' => 'Proposta', 'nome' => 'PROPOSTA-ACME']);
        $this->dossier(['ndos' => 7, 'nmdos' => 'Encomenda Produção', 'nome' => 'ENCOMENDA-BETA']);

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->assertSee('PROPOSTA-ACME')
            ->assertSee('ENCOMENDA-BETA')
            ->set('tipo', '7')
            ->assertSee('ENCOMENDA-BETA')
            ->assertDontSee('PROPOSTA-ACME');
    }

    public function test_filtra_por_estado_e_pesquisa(): void
    {
        $this->dossier(['nome' => 'ABERTA-X', 'fechada' => false]);
        $this->dossier(['nome' => 'FECHADA-Y', 'fechada' => true]);

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->set('estado', 'fechada')
            ->assertSee('FECHADA-Y')
            ->assertDontSee('ABERTA-X')
            ->set('estado', '')
            ->set('pesquisa', 'ABERTA')
            ->assertSee('ABERTA-X')
            ->assertDontSee('FECHADA-Y');
    }

    public function test_rota_visivel_para_equipa_e_barrada_ao_cliente(): void
    {
        $this->actingAs($this->admin())->get('/encomendas')->assertOk()->assertSee('Dossiers PHC');

        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $userCliente = User::create(['nome' => 'C', 'email' => 'c@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $cliente->id, 'ativo' => true]);
        $this->actingAs($userCliente)->get('/encomendas')->assertRedirect(route('portal.dashboard'));
    }

    // Os totais da página vêm AO VIVO do PHC, numa só leitura (set. 2026 — a proposta 7431 foi
    // alterada depois da sincronização e a listagem mostrava 1 062,09 € em vez de 2 816 €).
    public function test_totais_da_pagina_vem_do_phc_numa_so_leitura(): void
    {
        $alterada = $this->dossier(['id_erp' => 'BO-7431', 'obrano' => 7431, 'total_debito' => 1062.09]);
        $this->dossier(['id_erp' => 'BO-7428', 'obrano' => 7428, 'total_debito' => 200]);
        $this->dossier(['id_erp' => 'BO-SUMIU', 'obrano' => 7000, 'total_debito' => 55.5]);

        $erp = new class extends NullErpDriver
        {
            public array $pedidos = [];

            public function obterTotaisDossiers(array $bostamps): array
            {
                $this->pedidos[] = $bostamps;

                return ['BO-7431' => 2816.0, 'BO-7428' => 200.0]; // o terceiro já não está no PHC
            }
        };
        $this->app->instance(ErpSyncDriver::class, $erp);

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->assertSee('2 816,00 €')
            ->assertDontSee('1 062,09 €')
            ->assertSee('200,00 €')
            ->assertSee('55,50 €'); // sem PHC para ele: fica o guardado

        $this->assertCount(1, $erp->pedidos, 'uma só leitura para a página toda');
        $this->assertEqualsCanonicalizing(['BO-7431', 'BO-7428', 'BO-SUMIU'], $erp->pedidos[0]);
        $this->assertSame('1062.09', (string) $alterada->fresh()->total_debito); // a listagem só lê
    }

    // PHC em baixo: a listagem abre na mesma, com os totais da última sincronização.
    public function test_phc_em_baixo_mostra_os_totais_guardados(): void
    {
        $this->dossier(['id_erp' => 'BO-7431', 'obrano' => 7431, 'total_debito' => 1062.09]);
        $this->app->instance(ErpSyncDriver::class, new class extends NullErpDriver
        {
            public function obterTotaisDossiers(array $bostamps): array
            {
                throw new RuntimeException('SQLSTATE[HY000]: Unable to connect to server');
            }
        });

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->assertOk()
            ->assertSee('1 062,09 €');
    }
}
