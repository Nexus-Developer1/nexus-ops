<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Modal do evento: a pesquisa de equipamento também encontra pelo NOME DO CLIENTE — escreve-se
// "acme" e aparecem os equipamentos da ACME para escolher (quem marca a visita sabe o cliente,
// não o nº de série). Sem acentos/maiúsculas; a pesquisa por série/modelo continua igual.
class AgendaPesquisaClienteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function clienteComEquipamentos(string $nome, array $series): Cliente
    {
        $cliente = Cliente::create(['nome' => $nome, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        foreach ($series as $sn) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => $sn]);
        }

        return $cliente;
    }

    public function test_pesquisar_pelo_nome_do_cliente_lista_os_equipamentos_dele(): void
    {
        $this->clienteComEquipamentos('Câmara Municipal de Évora', ['CM-001', 'CM-002']);
        $this->clienteComEquipamentos('ACME Lda', ['AC-001']);

        $c = Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-07', '2026-09-07')
            ->set('formEquipamentoBusca', 'evora'); // sem acento, minúsculas

        $c->assertSee('CM-001')->assertSee('CM-002')->assertDontSee('AC-001')
            ->assertSee('Câmara Municipal de Évora');
    }

    public function test_pesquisa_por_serie_e_modelo_continua_a_funcionar(): void
    {
        $this->clienteComEquipamentos('ACME Lda', ['AC-001']);
        $this->clienteComEquipamentos('Beta SA', ['BT-777']);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-07', '2026-09-07')
            ->set('formEquipamentoBusca', 'BT-77')
            ->assertSee('BT-777')->assertDontSee('AC-001');
    }

    public function test_escolher_da_lista_herda_o_cliente_do_equipamento(): void
    {
        $cliente = $this->clienteComEquipamentos('ACME Lda', ['AC-001']);
        $equip = Equipamento::where('numero_serie', 'AC-001')->firstOrFail();

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-07', '2026-09-07')
            ->set('formEquipamentoBusca', 'acme')
            ->call('selecionarEquipamento', $equip->id)
            ->assertSet('formEquipamentoId', $equip->id)
            ->set('formTitulo', 'Visita')
            ->set('formInicio', '2026-09-07T09:00')
            ->set('formFim', '2026-09-07T10:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('eventos_agenda', ['titulo' => 'Visita', 'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);
    }
}
