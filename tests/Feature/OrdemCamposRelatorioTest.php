<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
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
            ->assertSet('ordemCampos.gerais', $nova)
            // Depois de um call() o HTML vem JSON-escapado (acentos → é), por isso confere-se
            // a ordem pelas wire:key dos blocos e não pelos rótulos.
            ->assertSeeHtmlInOrder(['wire:key="campo-tecnicos"', 'wire:key="campo-datas"', 'wire:key="campo-horas"', 'wire:key="campo-modo"', 'wire:key="campo-origem"', 'wire:key="campo-tipo"', 'wire:key="campo-encomendas"']);

        $this->assertSame($nova, $this->tecnico->fresh()->preferencia(Novo::PREF_ORDEM_CAMPOS)['gerais']);

        // Um editor novo (outro dia, outro dispositivo) nasce já com a ordem do utilizador.
        $this->editor()->assertSet('ordemCampos.gerais', $nova);
    }

    public function test_setas_movem_um_bloco_e_param_nas_pontas(): void
    {
        $this->editor()
            ->call('moverCampo', 'tecnicos', -1)
            ->assertSet('ordemCampos.gerais', ['modo', 'origem', 'tipo', 'datas', 'tecnicos', 'horas', 'encomendas'])
            ->call('moverCampo', 'modo', -1) // já é o primeiro — não mexe
            ->assertSet('ordemCampos.gerais', ['modo', 'origem', 'tipo', 'datas', 'tecnicos', 'horas', 'encomendas'])
            ->call('moverCampo', 'encomendas', 1) // já é o último
            ->assertSet('ordemCampos.gerais', ['modo', 'origem', 'tipo', 'datas', 'tecnicos', 'horas', 'encomendas'])
            ->call('moverCampo', 'inexistente', 1)
            ->assertSet('ordemCampos.gerais', ['modo', 'origem', 'tipo', 'datas', 'tecnicos', 'horas', 'encomendas'])
            ->call('reporOrdemCampos')
            ->assertSet('ordemCampos', Novo::CAMPOS);

        $this->assertSame(Novo::CAMPOS, $this->tecnico->fresh()->preferencia(Novo::PREF_ORDEM_CAMPOS));
    }

    public function test_ordem_forjada_e_saneada_chaves_estranhas_fora_e_as_em_falta_no_fim(): void
    {
        $this->editor()
            ->call('reordenarCampos', ['encomendas', '../../.env', 'encomendas', 42, 'tipo'])
            ->assertOk()
            ->assertSet('ordemCampos.gerais', ['encomendas', 'tipo', 'modo', 'origem', 'datas', 'horas', 'tecnicos']);

        // Preferência corrompida na BD também não rebenta o editor.
        $this->tecnico->guardarPreferencia(Novo::PREF_ORDEM_CAMPOS, 'lixo');
        $this->editor()->assertOk()->assertSet('ordemCampos', Novo::CAMPOS);
    }

    // O mesmo na ficha de medições de cada equipamento (grupo ficha_ups) — pedido da equipa.
    public function test_ficha_ups_tambem_se_reordena_e_o_grupo_e_independente(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $ups = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'SU3000', 'numero_serie' => 'YS0239']);

        $editor = $this->editor()->call('selecionarCliente', $cliente->id)
            ->assertSeeHtmlInOrder(['campo-fichas.'.$ups->id.'-identificacao', 'campo-fichas.'.$ups->id.'-medicoes', 'campo-fichas.'.$ups->id.'-recomendacoes'])
            ->call('moverCampo', 'medicoes', -1, 'ficha_ups')
            ->assertSet('ordemCampos.ficha_ups', ['identificacao', 'configuracao', 'medicoes', 'modulos', 'verificacoes', 'descarga', 'conclusao', 'recomendacoes'])
            ->call('reordenarCampos', ['recomendacoes', 'medicoes', 'lixo'], 'ficha_ups')
            ->assertSet('ordemCampos.ficha_ups', ['recomendacoes', 'medicoes', 'identificacao', 'configuracao', 'modulos', 'verificacoes', 'descarga', 'conclusao'])
            ->assertSeeHtmlInOrder(['campo-fichas.'.$ups->id.'-recomendacoes', 'campo-fichas.'.$ups->id.'-medicoes', 'campo-fichas.'.$ups->id.'-identificacao'])
            ->assertSet('ordemCampos.gerais', Novo::CAMPOS['gerais']); // os gerais não mexem

        $editor->call('reporOrdemCampos', 'ficha_ups')->assertSet('ordemCampos.ficha_ups', Novo::CAMPOS['ficha_ups']);

        // Grupo desconhecido é ignorado.
        $editor->call('reordenarCampos', ['modo'], 'outro')->assertOk()->assertSet('ordemCampos', Novo::CAMPOS);
    }

    public function test_preferencia_na_forma_antiga_lista_simples_conta_como_gerais(): void
    {
        $this->tecnico->guardarPreferencia(Novo::PREF_ORDEM_CAMPOS, ['horas', 'modo']);

        $this->editor()
            ->assertSet('ordemCampos.gerais', ['horas', 'modo', 'origem', 'tipo', 'datas', 'tecnicos', 'encomendas'])
            ->assertSet('ordemCampos.ficha_ups', Novo::CAMPOS['ficha_ups']);
    }

    public function test_a_ordem_e_de_cada_utilizador(): void
    {
        $this->editor()->call('reordenarCampos', ['horas', 'modo', 'origem', 'tipo', 'datas', 'tecnicos', 'encomendas']);

        $outro = User::create(['nome' => 'Ana', 'email' => 'ana@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        Livewire::actingAs($outro)->test(Novo::class)->assertSet('ordemCampos', Novo::CAMPOS);
    }
}
