<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Equipamentos as EquipamentosDoCliente;
use App\Livewire\Concerns\LembraPagina;
use App\Livewire\Equipamentos\Listagem as EquipamentosListagem;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\WithPagination;
use Tests\TestCase;

// Todas as listagens lembram-se da página (out. 2026): abrir um registo na página 3 e «Voltar»
// (sem ?page) regressa à 3. Nos separadores da ficha do cliente, a página é por cliente.
class ListagensLembramPaginaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function equipamentos(Cliente $cliente, int $quantos): void
    {
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        for ($n = 1; $n <= $quantos; $n++) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
                'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => sprintf('SN-%s-%02d', $cliente->id, $n)]);
        }
    }

    // Nenhuma listagem paginada fica de fora: quem usa WithPagination usa também LembraPagina.
    public function test_todas_as_listagens_paginadas_lembram_a_pagina(): void
    {
        $semMemoria = collect(glob(app_path('Livewire/*/*.php')))
            ->map(fn ($f) => 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], substr($f, strlen(app_path('Livewire/')))))
            ->filter(fn ($c) => class_exists($c) && in_array(WithPagination::class, class_uses_recursive($c), true))
            ->reject(fn ($c) => in_array(LembraPagina::class, class_uses_recursive($c), true))
            ->values()->all();

        $this->assertSame([], $semMemoria);
    }

    public function test_listagem_de_equipamentos_volta_a_pagina_onde_se_estava(): void
    {
        $this->equipamentos(Cliente::create(['nome' => 'ACME', 'ativo' => true]), 25);

        Livewire::actingAs($this->admin)->test(EquipamentosListagem::class)->call('gotoPage', 3);

        Livewire::actingAs($this->admin)->test(EquipamentosListagem::class) // «Voltar»: sem ?page
            ->assertSet('paginators.page', 3);
    }

    public function test_separadores_do_cliente_lembram_a_pagina_de_cada_cliente(): void
    {
        $acme = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $beta = Cliente::create(['nome' => 'BETA', 'ativo' => true]);
        $this->equipamentos($acme, 45);
        $this->equipamentos($beta, 45);

        Livewire::actingAs($this->admin)->test(EquipamentosDoCliente::class, ['cliente' => $acme])->call('gotoPage', 3);

        Livewire::actingAs($this->admin)->test(EquipamentosDoCliente::class, ['cliente' => $acme])
            ->assertSet('paginators.page', 3);
        Livewire::actingAs($this->admin)->test(EquipamentosDoCliente::class, ['cliente' => $beta]) // outro cliente: a 1
            ->assertSet('paginators.page', 1);
    }
}
