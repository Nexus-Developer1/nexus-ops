<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Livewire\Equipamentos\Listagem;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Associação equipamento→equipamento: banco de baterias ligado ao UPS pai
// (equipamento_pai_id), pesquisável por nº de série ou local, e filtrável na listagem.
class BancoBateriasAssociacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Local $local;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $this->local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
    }

    private function equipamento(array $attrs = []): Equipamento
    {
        return Equipamento::create($attrs + ['local_id' => $this->local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello']);
    }

    public function test_associa_e_desassocia_banco(): void
    {
        $ups = $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-1']);
        $banco = $this->equipamento(['modelo' => 'BANCO DE BATERIAS RIELLO SDU', 'numero_serie' => 'BB-1']);

        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $ups])
            ->call('associarBanco', $banco->id)
            ->assertHasNoErrors();

        $this->assertSame($ups->id, $banco->fresh()->equipamento_pai_id);
        $this->assertSame(1, $ups->equipamentosAssociados()->count());

        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $ups->fresh()])
            ->call('desassociarBanco', $banco->id);

        $this->assertNull($banco->fresh()->equipamento_pai_id);
    }

    public function test_banco_ja_associado_a_outro_ups_nao_e_roubado(): void
    {
        $ups1 = $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-1']);
        $ups2 = $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-2']);
        $banco = $this->equipamento(['modelo' => 'BANCO DE BATERIAS', 'numero_serie' => 'BB-1', 'equipamento_pai_id' => $ups1->id]);

        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $ups2])
            ->call('associarBanco', $banco->id)
            ->assertHasErrors('bancoBusca');

        $this->assertSame($ups1->id, $banco->fresh()->equipamento_pai_id);
    }

    public function test_nao_cria_cadeias_nem_autoassociacao(): void
    {
        $ups = $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-1']);
        $banco = $this->equipamento(['modelo' => 'BANCO DE BATERIAS', 'numero_serie' => 'BB-1', 'equipamento_pai_id' => $ups->id]);
        $outro = $this->equipamento(['modelo' => 'KIT BATERIA', 'numero_serie' => 'KB-1']);

        // Auto-associação ignorada.
        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $ups->fresh()])
            ->call('associarBanco', $ups->id);
        $this->assertNull($ups->fresh()->equipamento_pai_id);

        // Um banco (tem pai) não pode receber associados — evita cadeias.
        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $banco->fresh()])
            ->call('associarBanco', $outro->id);
        $this->assertNull($outro->fresh()->equipamento_pai_id);
    }

    public function test_sugestao_sem_texto_mostra_bancos_livres_do_mesmo_local(): void
    {
        $ups = $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-1']);
        $livre = $this->equipamento(['modelo' => 'BANCO DE BATERIAS SDU', 'numero_serie' => 'BB-LIVRE']);
        $ocupado = $this->equipamento(['modelo' => 'BANCO DE BATERIAS SEP', 'numero_serie' => 'BB-OCUP', 'equipamento_pai_id' => $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-2'])->id]);
        $outroLocal = Local::create(['cliente_id' => $this->local->cliente_id, 'designacao' => 'DC2']);
        $longe = $this->equipamento(['modelo' => 'BANCO DE BATERIAS BTC', 'numero_serie' => 'BB-LONGE', 'local_id' => $outroLocal->id]);

        $sugeridos = Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $ups])
            ->viewData('bancosFiltrados')->pluck('numero_serie');

        $this->assertSame(['BB-LIVRE'], $sugeridos->all());
    }

    public function test_pesquisa_encontra_banco_por_serie_ou_local(): void
    {
        $ups = $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-1']);
        $outroLocal = Local::create(['cliente_id' => $this->local->cliente_id, 'designacao' => 'Armazém Norte']);
        $longe = $this->equipamento(['modelo' => 'BANCO DE BATERIAS BTC', 'numero_serie' => 'BB-XYZ', 'local_id' => $outroLocal->id]);

        // Por nº de série.
        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $ups])
            ->set('bancoBusca', 'BB-XYZ')
            ->assertViewHas('bancosFiltrados', fn ($b) => $b->pluck('numero_serie')->contains('BB-XYZ'));

        // Pelo local (designação).
        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $ups])
            ->set('bancoBusca', 'Armazém')
            ->assertViewHas('bancosFiltrados', fn ($b) => $b->pluck('numero_serie')->contains('BB-XYZ'));
    }

    public function test_listagem_filtra_por_banco_associado(): void
    {
        $comBanco = $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-COM']);
        $semBanco = $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-SEM']);
        $banco = $this->equipamento(['modelo' => 'BANCO DE BATERIAS', 'numero_serie' => 'BB-1', 'equipamento_pai_id' => $comBanco->id]);

        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('banco', 'com')
            ->assertSee('UPS-COM')->assertDontSee('UPS-SEM');

        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('banco', 'sem')
            ->assertSee('UPS-SEM')->assertDontSee('UPS-COM');

        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('banco', 'banco')
            ->assertSee('BB-1')->assertDontSee('UPS-SEM');
    }

    public function test_listagem_pesquisa_ups_pela_serie_do_banco(): void
    {
        $ups = $this->equipamento(['modelo' => 'NPW', 'numero_serie' => 'UPS-COM']);
        $this->equipamento(['modelo' => 'BANCO DE BATERIAS', 'numero_serie' => 'BB-777', 'equipamento_pai_id' => $ups->id]);

        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('pesquisa', 'BB-777')
            ->assertSee('UPS-COM');
    }
}
