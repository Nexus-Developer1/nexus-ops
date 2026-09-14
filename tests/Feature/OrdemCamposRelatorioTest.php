<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Campos reordenáveis no editor de relatórios (pedido da equipa, set. 2026): cada utilizador
// organiza os blocos do cartão «Equipamento e Intervenção» mediante a importância. A ordem fica
// nas preferências do utilizador (BD, segue-o entre dispositivos) e é revalidada contra a
// whitelist — a vista faz @include por chave, uma chave forjada nunca pode ser renderizada.
class OrdemCamposRelatorioTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tecnico = User::create(['nome' => 'Rui', 'email' => 'rui@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private function editor()
    {
        return Livewire::actingAs($this->tecnico)->test(Novo::class);
    }

    public function test_ordem_de_fabrica_e_o_botao_de_organizar(): void
    {
        $this->editor()
            ->assertSet('ordemCampos', Novo::CAMPOS)
            ->assertSee('Organizar campos')
            ->assertSeeInOrder(['Tipo de relatório', 'Tipo de intervenção', 'Datas da intervenção', 'Horas', 'Técnicos', 'Encomendas de peças']);
    }

    public function test_arrastar_guarda_a_ordem_no_utilizador_e_reabre_com_ela(): void
    {
        $nova = ['tecnicos', 'datas', 'horas', 'modo', 'origem', 'tipo', 'encomendas'];

        $this->editor()
            ->call('reordenarCampos', $nova)
            ->assertSet('ordemCampos', $nova)
            // Depois de um call() o HTML vem JSON-escapado (acentos → é), por isso confere-se
            // a ordem pelas wire:key dos blocos e não pelos rótulos.
            ->assertSeeHtmlInOrder(['wire:key="campo-tecnicos"', 'wire:key="campo-datas"', 'wire:key="campo-horas"', 'wire:key="campo-modo"', 'wire:key="campo-origem"', 'wire:key="campo-tipo"', 'wire:key="campo-encomendas"']);

        $this->assertSame($nova, $this->tecnico->fresh()->preferencia(Novo::PREF_ORDEM_CAMPOS));

        // Um editor novo (outro dia, outro dispositivo) nasce já com a ordem do utilizador.
        $this->editor()->assertSet('ordemCampos', $nova);
    }

    public function test_setas_movem_um_bloco_e_param_nas_pontas(): void
    {
        $this->editor()
            ->call('moverCampo', 'tecnicos', -1)
            ->assertSet('ordemCampos', ['modo', 'origem', 'tipo', 'datas', 'tecnicos', 'horas', 'encomendas'])
            ->call('moverCampo', 'modo', -1) // já é o primeiro — não mexe
            ->assertSet('ordemCampos', ['modo', 'origem', 'tipo', 'datas', 'tecnicos', 'horas', 'encomendas'])
            ->call('moverCampo', 'encomendas', 1) // já é o último
            ->assertSet('ordemCampos', ['modo', 'origem', 'tipo', 'datas', 'tecnicos', 'horas', 'encomendas'])
            ->call('moverCampo', 'inexistente', 1)
            ->assertSet('ordemCampos', ['modo', 'origem', 'tipo', 'datas', 'tecnicos', 'horas', 'encomendas'])
            ->call('reporOrdemCampos')
            ->assertSet('ordemCampos', Novo::CAMPOS);

        $this->assertSame(Novo::CAMPOS, $this->tecnico->fresh()->preferencia(Novo::PREF_ORDEM_CAMPOS));
    }

    public function test_ordem_forjada_e_saneada_chaves_estranhas_fora_e_as_em_falta_no_fim(): void
    {
        $this->editor()
            ->call('reordenarCampos', ['encomendas', '../../.env', 'encomendas', 42, 'tipo'])
            ->assertOk()
            ->assertSet('ordemCampos', ['encomendas', 'tipo', 'modo', 'origem', 'datas', 'horas', 'tecnicos']);

        // Preferência corrompida na BD também não rebenta o editor.
        $this->tecnico->guardarPreferencia(Novo::PREF_ORDEM_CAMPOS, 'lixo');
        $this->editor()->assertOk()->assertSet('ordemCampos', Novo::CAMPOS);
    }

    public function test_a_ordem_e_de_cada_utilizador(): void
    {
        $this->editor()->call('reordenarCampos', ['horas', 'modo', 'origem', 'tipo', 'datas', 'tecnicos', 'encomendas']);

        $outro = User::create(['nome' => 'Ana', 'email' => 'ana@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        Livewire::actingAs($outro)->test(Novo::class)->assertSet('ordemCampos', Novo::CAMPOS);
    }
}
