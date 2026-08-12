<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Pesquisa/filtros das listagens vivem na SESSÃO (#[Session], não #[Url]): entrar num
// registo e voltar à lista mantém o que se estava a pesquisar — pedido da equipa (a
// pesquisa nos clientes perdia-se ao entrar num cliente e sair).
class ListagensPesquisaPersistenteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_pesquisa_de_clientes_sobrevive_a_sair_e_voltar(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(\App\Livewire\Clientes\Index::class)
            ->set('pesquisa', 'VAZ MENDES')
            ->set('ordenar', 'nome_asc');

        // "Voltar à listagem" = novo pedido, componente novo: restaura da sessão.
        Livewire::actingAs($admin)->test(\App\Livewire\Clientes\Index::class)
            ->assertSet('pesquisa', 'VAZ MENDES')
            ->assertSet('ordenar', 'nome_asc');
    }

    public function test_pesquisa_e_filtros_de_equipamentos_sobrevivem(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(\App\Livewire\Equipamentos\Listagem::class)
            ->set('pesquisa', 'MH19VNPW')
            ->set('tipo', 'ups');

        Livewire::actingAs($admin)->test(\App\Livewire\Equipamentos\Listagem::class)
            ->assertSet('pesquisa', 'MH19VNPW')
            ->assertSet('tipo', 'ups');
    }
}
