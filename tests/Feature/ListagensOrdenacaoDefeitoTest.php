<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Equipamentos e clientes abrem com os MAIS RECENTES primeiro (antes: equipamentos pela
// ordem de inserção do ERP e clientes por nome), e ambos têm seletor de ordenação.
class ListagensOrdenacaoDefeitoTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_equipamentos_abrem_pelos_mais_recentes_e_ordenam_por_serie(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        foreach (['SN-A', 'SN-B', 'SN-C'] as $serie) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => $serie]);
        }

        // Default: o último criado (SN-C) aparece primeiro.
        Livewire::actingAs($this->admin())->test(\App\Livewire\Equipamentos\Listagem::class)
            ->assertSet('ordenar', 'recentes')
            ->assertSeeInOrder(['SN-C', 'SN-B', 'SN-A']);

        // Mais antigos = ordem de inserção (o comportamento anterior, agora explícito).
        Livewire::actingAs($this->admin())->test(\App\Livewire\Equipamentos\Listagem::class)
            ->set('ordenar', 'antigos')
            ->assertSeeInOrder(['SN-A', 'SN-B', 'SN-C']);

        // Por nº de série.
        Livewire::actingAs($this->admin())->test(\App\Livewire\Equipamentos\Listagem::class)
            ->set('ordenar', 'serie_desc')
            ->assertSeeInOrder(['SN-C', 'SN-B', 'SN-A']);
    }

    public function test_equipamentos_ordenam_por_cliente_sem_acentos(): void
    {
        foreach (['Zebra Lda', 'Águia SA', 'Mar Alto'] as $nome) {
            $c = Cliente::create(['nome' => $nome, 'ativo' => true]);
            $l = Local::create(['cliente_id' => $c->id, 'designacao' => 'DC']);
            Equipamento::create(['local_id' => $l->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-' . substr($nome, 0, 3)]);
        }

        Livewire::actingAs($this->admin())->test(\App\Livewire\Equipamentos\Listagem::class)
            ->set('ordenar', 'cliente_asc')
            ->assertSeeInOrder(['guia SA', 'Mar Alto', 'Zebra Lda']); // 'Águia' sem o Á (JSON escapa acentos)
    }

    public function test_clientes_abrem_pelos_mais_recentes(): void
    {
        // data_criacao_erp não é fillable (só o sync a escreve) → forceFill.
        Cliente::create(['nome' => 'Alfa', 'id_erp' => '1', 'ativo' => true])->forceFill(['data_criacao_erp' => '2020-01-01'])->save();
        Cliente::create(['nome' => 'Zulu', 'id_erp' => '2', 'ativo' => true])->forceFill(['data_criacao_erp' => '2026-07-30'])->save();

        // Default passa a ser "recentes": o Zulu (criado agora no PHC) vem primeiro.
        Livewire::actingAs($this->admin())->test(\App\Livewire\Clientes\Index::class)
            ->assertSet('ordenar', 'recentes')
            ->assertSeeInOrder(['Zulu', 'Alfa']);

        // A ordenação alfabética continua disponível.
        Livewire::actingAs($this->admin())->test(\App\Livewire\Clientes\Index::class)
            ->set('ordenar', 'nome_asc')
            ->assertSeeInOrder(['Alfa', 'Zulu']);
    }
}
