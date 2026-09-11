<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Listagem;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Listagem de relatórios: coluna "Cliente final / Local" (pedido da equipa, set. 2026) — o
// cliente final e o local de instalação do equipamento, os mesmos que saem no PDF.
class RelatorioClienteFinalListagemTest extends TestCase
{
    use RefreshDatabase;

    private function relatorio(array $equipamento, array $local = []): Relatorio
    {
        $cliente = Cliente::create(['nome' => 'CAMPOS & PEREDO LDA', 'ativo' => true, 'morada' => 'Rua da Sede 1']);
        $l = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Instalação principal'] + $local);
        $e = Equipamento::create(['local_id' => $l->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-'.uniqid()] + $equipamento);
        $i = Intervencao::create(['equipamento_id' => $e->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);

        return Relatorio::create(['intervencao_id' => $i->id, 'numero' => '2026/'.rand(1000, 9999), 'data' => now(), 'estado' => 'finalizado']);
    }

    private function listagem()
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        return Livewire::actingAs($admin)->test(Listagem::class);
    }

    public function test_mostra_o_cliente_final_e_o_local_de_instalacao(): void
    {
        $this->relatorio(['cliente_final' => 'Grau - Fábrica de Torneiras', 'localizacao_instalacao' => 'Bastidor piso -1']);

        $this->listagem()
            ->assertSee('Cliente final / Local')
            ->assertSee('Grau - Fábrica de Torneiras')
            ->assertSee('Bastidor piso -1');
    }

    public function test_sem_local_na_ficha_cai_na_morada_como_no_pdf(): void
    {
        // Sem localização explícita: a morada do local (a mesma regra do PDF).
        $this->relatorio(['cliente_final' => null], ['morada' => 'Zona Industrial de Oiã']);

        $this->listagem()
            ->assertSee('Zona Industrial de Oiã')
            ->assertSee('—'); // sem cliente final
    }
}
