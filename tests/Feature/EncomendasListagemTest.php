<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Encomendas\Listagem;
use App\Models\Cliente;
use App\Models\Dossier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_reordenar_colunas_persiste_e_ignora_chaves_invalidas(): void
    {
        Livewire::actingAs($this->admin())->test(Listagem::class)
            // Ordem por defeito de fábrica.
            ->assertSet('ordemColunas', ['tipo', 'numero', 'cliente', 'data', 'total', 'estado'])
            // Mover 'total' para o início; uma chave forjada ('rm -rf') é descartada e as
            // que faltarem entram no fim.
            ->call('reordenarColunas', ['total', 'tipo', 'rm -rf', 'cliente'])
            ->assertSet('ordemColunas', ['total', 'tipo', 'cliente', 'numero', 'data', 'estado'])
            ->call('reporColunas')
            ->assertSet('ordemColunas', ['tipo', 'numero', 'cliente', 'data', 'total', 'estado']);
    }

    public function test_rota_visivel_para_equipa_e_barrada_ao_cliente(): void
    {
        $this->actingAs($this->admin())->get('/encomendas')->assertOk()->assertSee('Encomendas');

        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $userCliente = User::create(['nome' => 'C', 'email' => 'c@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $cliente->id, 'ativo' => true]);
        $this->actingAs($userCliente)->get('/encomendas')->assertRedirect(route('portal.dashboard'));
    }
}
