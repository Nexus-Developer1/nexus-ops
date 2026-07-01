<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Detalhe;
use App\Livewire\Clientes\Faturacao;
use App\Models\Cliente;
use App\Models\LinhaFatura;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Fase 2: faturação na ficha do cliente. Liga por linhas_fatura.cliente_no = clientes.id_erp.
class ClienteFaturacaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function linha(string $clienteNo, string $idErp, $data, array $extra = []): LinhaFatura
    {
        return LinhaFatura::create(array_merge([
            'id_erp' => $idErp, 'cliente_no' => $clienteNo, 'data' => $data,
            'nmdoc' => 'Factura', 'fno' => 1, 'design' => 'Artigo', 'series' => 'S1', 'qtt' => 1,
        ], $extra));
    }

    public function test_pagina_lista_so_linhas_do_cliente_paginada_25(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);
        Cliente::create(['nome' => 'Outro', 'id_erp' => '999', 'ativo' => true]);

        for ($i = 0; $i < 30; $i++) {
            $this->linha('148', "L$i", now()->subDays($i));
        }
        $this->linha('999', 'X1', now(), ['design' => 'SO_DO_OUTRO_CLIENTE']);

        Livewire::actingAs($this->admin())->test(Faturacao::class, ['cliente' => $cliente])
            ->assertViewHas('linhas', fn ($p) => $p->total() === 30 && $p->perPage() === 25)
            ->assertDontSee('SO_DO_OUTRO_CLIENTE'); // não mostra faturação de outro cliente
    }

    public function test_ordena_por_data_desc(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);
        $this->linha('148', 'ANTIGA', now()->subYear(), ['design' => 'LINHA_ANTIGA']);
        $this->linha('148', 'RECENTE', now(), ['design' => 'LINHA_RECENTE']);

        Livewire::actingAs($this->admin())->test(Faturacao::class, ['cliente' => $cliente])
            ->assertViewHas('linhas', fn ($p) => $p->getCollection()->first()?->design === 'LINHA_RECENTE');
    }

    public function test_estado_vazio(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '777', 'ativo' => true]);

        Livewire::actingAs($this->admin())->test(Faturacao::class, ['cliente' => $cliente])
            ->assertSee('não tem linhas de faturação');
    }

    public function test_detalhe_mostra_seccao_faturacao_com_ver_todas(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);
        for ($i = 0; $i < 12; $i++) { // > LIMITE (10) → aparece o "Ver todas"
            $this->linha('148', "L$i", now()->subDays($i));
        }

        Livewire::actingAs($this->admin())->test(Detalhe::class, ['cliente' => $cliente])
            ->assertViewHas('faturacaoTotal', 12)
            ->assertSee('Faturação')
            ->assertSee('Ver todas');
    }
}
