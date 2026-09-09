<?php

namespace Tests\Feature;

use App\Enums\EstadoEquipamento;
use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Livewire\Equipamentos\Listagem;
use App\Livewire\Equipamentos\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Estado do equipamento (set. 2026): deixa de nascer «operacional» por defeito — o PHC não
// traz o estado e ninguém o tinha confirmado. Nasce «Por definir» e é marcado na ficha, por
// quem vê o equipamento — escolher no seletor não grava: só o botão «Guardar estado» da
// barra de cima, que a seguir devolve à lista. A listagem tem um filtro por estado.
class EquipamentoEstadoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function equipamento(string $serie, string $estado = 'por_definir'): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME '.$serie, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => $estado,
            'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => $serie]);
    }

    public function test_equipamento_novo_nasce_por_definir(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Instalação principal']);

        // O formulário abre em «Por definir» — quem regista não é obrigado a inventar um estado.
        Livewire::actingAs($this->admin())->test(Novo::class)
            ->assertSet('estado', 'por_definir')
            ->assertSee('Por definir');
    }

    public function test_ficha_mostra_a_etiqueta_e_o_seletor_de_estado(): void
    {
        $equipamento = $this->equipamento('SN-1');

        Livewire::actingAs($this->admin())->test(Ficha::class, ['equipamento' => $equipamento])
            ->assertSet('estado', 'por_definir')
            ->assertSee('Por definir')
            ->assertSee('Definir o estado do equipamento') // o seletor ao lado da etiqueta
            ->assertSee('Operacional');                    // uma das opções por onde escolher
    }

    public function test_escolher_nao_grava_ate_se_carregar_em_guardar(): void
    {
        $admin = $this->admin();
        $equipamento = $this->equipamento('SN-2');

        $c = Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $equipamento])
            ->assertDontSee('Guardar estado')          // sem alterações, sem botão
            ->set('estado', 'operacional')
            ->assertSee('Guardar estado')              // aparece na barra de cima
            ->assertSee('Por guardar');                // e junto ao seletor

        // Escolher NÃO grava — só o botão o faz.
        $this->assertSame(EstadoEquipamento::PorDefinir, $equipamento->fresh()->estado);

        // Guardar: grava e volta à lista de equipamentos.
        $c->call('guardarEstado')
            ->assertHasNoErrors()
            ->assertRedirect(route('ativos'));

        $this->assertSame(EstadoEquipamento::Operacional, $equipamento->fresh()->estado);
    }

    public function test_voltar_a_escolher_o_estado_gravado_faz_o_botao_desaparecer(): void
    {
        $equipamento = $this->equipamento('SN-4', 'operacional');

        Livewire::actingAs($this->admin())->test(Ficha::class, ['equipamento' => $equipamento])
            ->set('estado', 'critico')
            ->assertSee('Guardar estado')
            ->set('estado', 'operacional')   // mudou de ideias
            ->assertDontSee('Guardar estado');

        $this->assertSame(EstadoEquipamento::Operacional, $equipamento->fresh()->estado);
    }

    public function test_estado_forjado_e_recusado(): void
    {
        $equipamento = $this->equipamento('SN-3');

        Livewire::actingAs($this->admin())->test(Ficha::class, ['equipamento' => $equipamento])
            ->set('estado', 'inventado')
            ->call('guardarEstado')
            ->assertHasErrors('estado');

        $this->assertSame(EstadoEquipamento::PorDefinir, $equipamento->fresh()->estado);
    }

    public function test_filtro_de_estado_na_listagem(): void
    {
        $this->equipamento('SN-OPER', 'operacional');
        $this->equipamento('SN-POR-DEFINIR');
        $this->equipamento('SN-CRITICO', 'critico');

        $c = Livewire::actingAs($this->admin())->test(Listagem::class)
            ->assertSee('SN-OPER')->assertSee('SN-POR-DEFINIR')->assertSee('SN-CRITICO');

        // Só os que alguém já confirmou como operacionais.
        $c->set('estado', 'operacional')
            ->assertSee('SN-OPER')
            ->assertDontSee('SN-POR-DEFINIR')
            ->assertDontSee('SN-CRITICO');

        // E o inverso: os que ainda estão por marcar.
        $c->set('estado', 'por_definir')
            ->assertSee('SN-POR-DEFINIR')
            ->assertDontSee('SN-OPER');
    }
}
