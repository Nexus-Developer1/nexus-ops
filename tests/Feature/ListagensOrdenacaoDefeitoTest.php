<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Index;
use App\Livewire\Equipamentos\Listagem;
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

    public function test_equipamentos_abrem_pelos_mais_recentes_pela_ordem_do_phc(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        foreach (['SN-A', 'SN-B', 'SN-C'] as $serie) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => $serie]);
        }
        // Equipamento do PHC inserido POR ÚLTIMO na app (ex.: backfill), mas ANTIGO no PHC:
        // "recentes" segue a ordem do PHC (criado_erp_em), não a ordem de inserção na app.
        Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'numero_serie' => 'SN-PHC-VELHO', 'criado_erp_em' => '2020-01-01 09:00:00']);

        // Default: manuais pela data de registo (empate → id desc), o antigo do PHC no fim.
        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->assertSet('ordenar', 'recentes')
            ->assertSeeInOrder(['SN-C', 'SN-B', 'SN-A', 'SN-PHC-VELHO']);

        // Mais antigos: o antigo do PHC primeiro.
        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->set('ordenar', 'antigos')
            ->assertSeeInOrder(['SN-PHC-VELHO', 'SN-A', 'SN-B', 'SN-C']);

        // Por nº de série.
        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->set('ordenar', 'serie_desc')
            ->assertSeeInOrder(['SN-PHC-VELHO', 'SN-C', 'SN-B', 'SN-A']);
    }

    public function test_equipamentos_ordenam_por_cliente_sem_acentos(): void
    {
        foreach (['Zebra Lda', 'Águia SA', 'Mar Alto'] as $nome) {
            $c = Cliente::create(['nome' => $nome, 'ativo' => true]);
            $l = Local::create(['cliente_id' => $c->id, 'designacao' => 'DC']);
            Equipamento::create(['local_id' => $l->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-'.substr($nome, 0, 3)]);
        }

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->set('ordenar', 'cliente_asc')
            ->assertSeeInOrder(['guia SA', 'Mar Alto', 'Zebra Lda']); // 'Águia' sem o Á (JSON escapa acentos)
    }

    public function test_clientes_abrem_pelos_mais_recentes(): void
    {
        // data_criacao_erp não é fillable (só o sync a escreve) → forceFill.
        Cliente::create(['nome' => 'Alfa', 'id_erp' => '1', 'ativo' => true])->forceFill(['data_criacao_erp' => '2020-01-01'])->save();
        Cliente::create(['nome' => 'Zulu', 'id_erp' => '2', 'ativo' => true])->forceFill(['data_criacao_erp' => '2026-07-30'])->save();

        // Default passa a ser "recentes": o Zulu (criado agora no PHC) vem primeiro.
        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertSet('ordenar', 'recentes')
            ->assertSeeInOrder(['Zulu', 'Alfa']);

        // A ordenação alfabética continua disponível.
        Livewire::actingAs($this->admin())->test(Index::class)
            ->set('ordenar', 'nome_asc')
            ->assertSeeInOrder(['Alfa', 'Zulu']);
    }
}
