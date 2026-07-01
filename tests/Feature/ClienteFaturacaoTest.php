<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Detalhe;
use App\Livewire\Clientes\Fatura;
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

    public function test_fatura_mostra_todas_as_linhas_do_mesmo_documento(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);
        // Documento "Fatura 3314" (este ano) com 3 artigos + outro documento com 1.
        $a = $this->linha('148', 'A1', now(), ['nmdoc' => 'Fatura', 'fno' => 3314, 'design' => 'CPU INTEL']);
        $this->linha('148', 'A2', now(), ['nmdoc' => 'Fatura', 'fno' => 3314, 'design' => 'MB ASUS']);
        $this->linha('148', 'A3', now(), ['nmdoc' => 'Fatura', 'fno' => 3314, 'design' => 'SSD PNY']);
        $this->linha('148', 'B1', now(), ['nmdoc' => 'Fatura', 'fno' => 999, 'design' => 'ARTIGO_DOUTRO_DOC']);
        // MESMO nmdoc+fno (Fatura 3314) mas de OUTRO ANO → documento diferente (o fno
        // reinicia anualmente no PHC): NÃO pode juntar-se a esta fatura.
        $this->linha('148', 'C1', now()->subYear(), ['nmdoc' => 'Fatura', 'fno' => 3314, 'design' => 'ARTIGO_DE_OUTRO_ANO']);

        Livewire::actingAs($this->admin())->test(Fatura::class, ['cliente' => $cliente, 'linha' => $a])
            ->assertViewHas('linhas', fn ($c) => $c->count() === 3)
            ->assertSee('CPU INTEL')->assertSee('MB ASUS')->assertSee('SSD PNY')
            ->assertDontSee('ARTIGO_DOUTRO_DOC')        // outro documento (fno diferente) não entra
            ->assertDontSee('ARTIGO_DE_OUTRO_ANO');     // mesmo nmdoc+fno noutra data → separado
    }

    public function test_fatura_de_linha_de_outro_cliente_da_404(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'id_erp' => '148', 'ativo' => true]);
        $linhaOutro = $this->linha('999', 'Z1', now()); // pertence ao cliente 999

        $this->actingAs($this->admin())
            ->get(route('clientes.fatura', [$cliente, $linhaOutro]))
            ->assertNotFound();
    }
}
