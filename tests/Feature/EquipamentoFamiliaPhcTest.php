<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Enums\TipoEquipamento;
use App\Livewire\Equipamentos\Listagem;
use App\Livewire\Equipamentos\Novo;
use App\Models\Artigo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Criar equipamento à mão exige a FAMÍLIA do PHC (pedido da equipa, set. 2026) e o tipo deixou
// de vir «UPS» pré-escolhido — caixas de baterias e afins ficavam registadas como UPS. Uma
// família de UPS preenche o tipo sozinha (se ainda estiver por escolher). A listagem mostra a
// família por baixo do tipo.
class EquipamentoFamiliaPhcTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->cliente = Cliente::create(['nome' => 'PONTUAL', 'ativo' => true]);
        Artigo::create(['id_erp' => 'A1', 'designacao' => 'Bateria', 'familia' => '018B-UPS BATERIAS', 'faminome' => 'UPS BATERIAS']);
        Artigo::create(['id_erp' => 'A2', 'designacao' => 'Switch', 'familia' => '020-NETWORKING S', 'faminome' => 'NETWORKING SWITCH']);
    }

    private function form()
    {
        return Livewire::actingAs($this->admin)->test(Novo::class)->call('selecionarCliente', $this->cliente->id);
    }

    public function test_tipo_nao_vem_como_ups_e_familia_e_tipo_sao_obrigatorios(): void
    {
        $this->form()
            ->assertSet('tipo', '')           // já não nasce UPS
            ->assertSet('familia', '')
            ->call('guardar')
            ->assertHasErrors(['tipo' => 'required', 'familia' => 'required']);

        $this->assertSame(0, Equipamento::count());
    }

    public function test_familia_de_ups_preenche_o_tipo_e_grava_codigo_e_nome(): void
    {
        $this->form()
            ->call('escolherFamilia', '018B-UPS BATERIAS')
            ->assertSet('familia', '018B-UPS BATERIAS')
            ->assertSet('tipo', TipoEquipamento::Ups->value) // família de UPS → tipo UPS
            ->set('numero_serie', 'AROS-BX02')
            ->call('guardar')
            ->assertHasNoErrors();

        $eq = Equipamento::where('numero_serie', 'AROS-BX02')->firstOrFail();
        $this->assertSame('018B-UPS BATERIAS', $eq->familia);
        $this->assertSame('UPS BATERIAS', $eq->faminome); // o nome vem do catálogo, não do browser
    }

    public function test_familia_nao_troca_um_tipo_ja_escolhido(): void
    {
        $this->form()
            ->set('tipo', 'diversos')
            ->call('escolherFamilia', '018B-UPS BATERIAS')
            ->assertSet('tipo', 'diversos')
            // Família que não é de UPS nunca mexe no tipo.
            ->call('limparFamilia')->set('tipo', '')
            ->call('escolherFamilia', '020-NETWORKING S')
            ->assertSet('tipo', '');
    }

    public function test_familia_que_nao_existe_no_phc_e_recusada(): void
    {
        $this->form()
            ->call('escolherFamilia', 'INVENTADA')->assertSet('familia', '')   // pela ação: ignorada
            ->set('familia', 'INVENTADA')->set('tipo', 'ups')                  // forçada na propriedade
            ->call('guardar')
            ->assertHasErrors(['familia' => 'exists']);

        $this->assertSame(0, Equipamento::count());
    }

    public function test_sugere_as_familias_dos_equipamentos_e_pesquisa_no_catalogo(): void
    {
        $local = Local::create(['cliente_id' => $this->cliente->id, 'designacao' => 'Sede']);
        Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'familia' => '018B-UPS BATERIAS', 'faminome' => 'UPS BATERIAS']);

        $this->form()
            ->assertSee('UPS BATERIAS')              // sem texto: as que os equipamentos usam
            ->assertDontSee('NETWORKING SWITCH')
            ->set('familiaBusca', 'network')
            ->assertSee('NETWORKING SWITCH');        // com texto: todo o catálogo do PHC
    }

    public function test_listagem_mostra_a_familia_por_baixo_do_tipo(): void
    {
        $local = Local::create(['cliente_id' => $this->cliente->id, 'designacao' => 'Sede']);
        Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'numero_serie' => 'SN-FAM', 'familia' => '018B-UPS BATERIAS', 'faminome' => 'UPS BATERIAS']);

        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->assertSeeInOrder(['SN-FAM', 'UPS', 'UPS BATERIAS']);
    }
}
