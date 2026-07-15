<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Associar;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// "Associar equipamento a local": a página não pode carregar os ~17k equipamentos nem os ~600
// locais (era o problema de performance). Equipamento e local escolhem-se por pesquisa server-side;
// quando vem da ficha (equipamento fixo) carrega-se só esse.
class AssociarEquipamentoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    /** Cria um cliente com um local e $n equipamentos (WF-0001…). */
    private function clienteComEquipamentos(int $n, string $nome = 'ACME'): Local
    {
        $cliente = Cliente::create(['nome' => $nome, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala Técnica']);
        for ($i = 1; $i <= $n; $i++) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => sprintf('WF-%04d', $i)]);
        }

        return $local;
    }

    public function test_associacao_livre_nao_carrega_todos_e_pesquisa_server_side(): void
    {
        $this->clienteComEquipamentos(25); // vários equipamentos

        $c = Livewire::actingAs($this->admin())->test(Associar::class)
            ->assertSet('equipamentoFixo', false);

        // Sem texto → NÃO carrega nada (não há get() dos 17k).
        $c->assertViewHas('equipamentosFiltrados', fn ($r) => $r->isEmpty());

        // Com texto → traz só os que batem certo.
        $c->set('equipamentoBusca', 'WF-0003')
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->count() === 1 && $r->first()->numero_serie === 'WF-0003');
    }

    public function test_pesquisa_filtra_por_serie_ou_modelo_e_limita_resultados(): void
    {
        $this->clienteComEquipamentos(40); // 40 com modelo "NPW"

        $c = Livewire::actingAs($this->admin())->test(Associar::class);

        // Por nº de série.
        $c->set('equipamentoBusca', 'WF-0007')
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->pluck('numero_serie')->contains('WF-0007'));

        // Por modelo (sem acentos) → limitado a 30 mesmo havendo 40 a bater.
        $c->set('equipamentoBusca', 'npw')
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->count() === 30);
    }

    public function test_com_equipamento_fixo_carrega_so_um(): void
    {
        $local = $this->clienteComEquipamentos(5);
        $equip = Equipamento::orderBy('numero_serie')->firstOrFail();

        $c = Livewire::actingAs($this->admin())->test(Associar::class, ['equipamento' => $equip])
            ->assertSet('equipamentoFixo', true)
            ->assertSet('equipamento_id', $equip->id)
            ->assertSet('local_id', $equip->local_id);

        // Carrega SÓ esse equipamento (não os 17k) e a caixa de local vem pré-preenchida.
        $c->assertViewHas('equipamentoAtual', fn ($x) => $x !== null && $x->id === $equip->id)
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->isEmpty()); // sem combo de pesquisa

        $this->assertNotSame('', $c->get('localBusca')); // local atual pré-preenchido
    }

    public function test_pesquisa_de_local_e_server_side(): void
    {
        // Dois clientes com locais distintos.
        $this->clienteComEquipamentos(2, 'ALFA');
        Local::create(['cliente_id' => Cliente::where('nome', 'ALFA')->value('id'), 'designacao' => 'Datacenter Norte']);

        $c = Livewire::actingAs($this->admin())->test(Associar::class);

        // Sem texto → vazio (não carrega os ~600 locais).
        $c->assertViewHas('locaisFiltrados', fn ($r) => $r->isEmpty());

        // Pesquisa por designação.
        $c->set('localBusca', 'Datacenter')
            ->assertViewHas('locaisFiltrados', fn ($r) => $r->count() === 1 && $r->first()->designacao === 'Datacenter Norte');
    }

    public function test_guardar_associa_o_equipamento_ao_local_escolhido(): void
    {
        $local = $this->clienteComEquipamentos(3);
        $equip = Equipamento::orderBy('numero_serie')->firstOrFail();
        $novoLocal = Local::create(['cliente_id' => $local->cliente_id, 'designacao' => 'Sala -1']);

        Livewire::actingAs($this->admin())->test(Associar::class, ['equipamento' => $equip])
            ->call('selecionarLocal', $novoLocal->id)
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('equipamentos.ficha', $equip));

        $this->assertSame($novoLocal->id, $equip->fresh()->local_id);
    }

    // ---- Modo "novo local" (mudança de titularidade: mover para um cliente sem locais) ----

    public function test_novo_local_move_equipamento_para_cliente_sem_locais(): void
    {
        // Cliente de origem (ex.: Dourogas) com o equipamento.
        $this->clienteComEquipamentos(1, 'DOUROGAS');
        $equip = Equipamento::firstOrFail();

        // Cliente de destino (ex.: Sonorgas) SEM locais.
        $sonorgas = Cliente::create(['nome' => 'SONORGAS', 'ativo' => true]);
        $this->assertSame(0, Local::where('cliente_id', $sonorgas->id)->count());

        Livewire::actingAs($this->admin())->test(Associar::class, ['equipamento' => $equip])
            ->call('definirModoLocal', 'novo')
            ->call('selecionarNovoLocalCliente', $sonorgas->id)
            ->set('novoLocalDesignacao', 'Instalação principal')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('equipamentos.ficha', $equip));

        // Criou o local na Sonorgas e moveu o equipamento para lá.
        $local = Local::where('cliente_id', $sonorgas->id)->where('designacao', 'Instalação principal')->firstOrFail();
        $this->assertSame($local->id, $equip->fresh()->local_id);
        $this->assertSame($sonorgas->id, $equip->fresh()->local->cliente_id);
    }

    public function test_novo_local_reutiliza_existente_com_a_mesma_designacao(): void
    {
        $this->clienteComEquipamentos(1, 'ALFA');
        $equip = Equipamento::firstOrFail();
        $sonorgas = Cliente::create(['nome' => 'SONORGAS', 'ativo' => true]);
        $existente = Local::create(['cliente_id' => $sonorgas->id, 'designacao' => 'Instalação principal']);

        Livewire::actingAs($this->admin())->test(Associar::class, ['equipamento' => $equip])
            ->call('definirModoLocal', 'novo')
            ->call('selecionarNovoLocalCliente', $sonorgas->id)
            ->set('novoLocalDesignacao', 'Instalação principal')
            ->call('guardar')
            ->assertHasNoErrors();

        // Não duplica — reutiliza o local existente.
        $this->assertSame(1, Local::where('cliente_id', $sonorgas->id)->where('designacao', 'Instalação principal')->count());
        $this->assertSame($existente->id, $equip->fresh()->local_id);
    }

    public function test_novo_local_exige_cliente_e_designacao(): void
    {
        $this->clienteComEquipamentos(1);
        $equip = Equipamento::firstOrFail();

        Livewire::actingAs($this->admin())->test(Associar::class, ['equipamento' => $equip])
            ->call('definirModoLocal', 'novo')
            ->set('novoLocalDesignacao', '')
            ->call('guardar')
            ->assertHasErrors(['novoLocalClienteId', 'novoLocalDesignacao']);
    }
}
