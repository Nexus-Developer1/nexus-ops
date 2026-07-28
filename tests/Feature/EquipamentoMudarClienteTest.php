<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Mudar o CLIENTE de um equipamento na ficha (com confirmação na UI): o equipamento aterra
// na "Instalação principal" do cliente novo — mesma designação do registo manual e do sync,
// para não criar locais paralelos.
class EquipamentoMudarClienteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_muda_o_cliente_e_reutiliza_a_instalacao_principal(): void
    {
        $a = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $b = Cliente::create(['nome' => 'Beta', 'ativo' => true]);
        $localA = Local::create(['cliente_id' => $a->id, 'designacao' => 'DC1']);
        $localB = Local::create(['cliente_id' => $b->id, 'designacao' => 'Instalação principal']);
        $equip = Equipamento::create(['local_id' => $localA->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);

        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $equip])
            ->call('mudarCliente', $b->id)
            ->assertHasNoErrors();

        // Reutilizou o local existente do cliente B (não criou um paralelo).
        $this->assertSame($localB->id, $equip->fresh()->local_id);
        $this->assertSame(1, Local::where('cliente_id', $b->id)->count());
    }

    public function test_cria_a_instalacao_principal_quando_o_cliente_novo_nao_tem(): void
    {
        $a = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $b = Cliente::create(['nome' => 'Beta', 'ativo' => true]); // sem locais
        $localA = Local::create(['cliente_id' => $a->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $localA->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);

        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $equip])
            ->call('mudarCliente', $b->id);

        $novoLocal = Local::where('cliente_id', $b->id)->firstOrFail();
        $this->assertSame('Instalação principal', $novoLocal->designacao);
        $this->assertSame($novoLocal->id, $equip->fresh()->local_id);
    }

    public function test_mesmo_cliente_e_id_invalido_nao_mexem_em_nada(): void
    {
        $a = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $localA = Local::create(['cliente_id' => $a->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $localA->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);

        $c = Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $equip]);
        $c->call('mudarCliente', $a->id);      // mesmo cliente → no-op (mantém o local atual)
        $c->call('mudarCliente', 999999);      // inexistente → no-op

        $this->assertSame($localA->id, $equip->fresh()->local_id);
    }

    public function test_pesquisa_sugere_clientes_sem_acentos_e_por_nif(): void
    {
        $a = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Cliente::create(['nome' => 'Câmara de Évora', 'nif' => '512345678', 'ativo' => true]);
        $localA = Local::create(['cliente_id' => $a->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $localA->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);

        // Sem acentos no termo encontra o nome acentuado.
        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $equip])
            ->set('novoClienteBusca', 'camara de evora')
            ->assertViewHas('novosClientesFiltrados', fn ($c) => $c->pluck('nome')->contains('Câmara de Évora'));

        // Por NIF.
        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $equip])
            ->set('novoClienteBusca', '512345678')
            ->assertViewHas('novosClientesFiltrados', fn ($c) => $c->pluck('nome')->contains('Câmara de Évora'));
    }
}
