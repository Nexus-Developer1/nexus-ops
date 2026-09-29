<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Equipamentos;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Tests\TestCase;

// Lista de equipamentos do cliente: chips por FAMÍLIA PHC (só as famílias que o cliente tem,
// com contagem; clicar filtra, reclicar limpa) e cada linha abre a ficha do equipamento
// (nome e seta são links para equipamentos.ficha, com o mastamp no URL).
class ClienteEquipamentosFamiliaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $cliente;

    private Equipamento $ups;

    private Equipamento $bateria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->cliente = Cliente::create(['nome' => 'WISDOM', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $this->cliente->id, 'designacao' => 'Sede']);
        $mk = fn (string $sn, string $fam, string $nome, string $modelo) => Equipamento::create([
            'local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'fabricante' => 'Riello', 'modelo' => $modelo, 'numero_serie' => $sn,
            'familia' => $fam, 'faminome' => $nome, 'id_erp' => 'M-'.$sn,
        ]);
        $this->ups = $mk('SN-U1', 'F01', 'UPS', 'SDH 3000');
        $mk('SN-U2', 'F01', 'UPS', 'SDU 6000');
        $this->bateria = $mk('SN-B1', 'F02', 'BATERIAS', 'Banco 40x');
    }

    public function test_chips_das_familias_filtram_e_reclicar_limpa(): void
    {
        Livewire::actingAs($this->admin)->test(Equipamentos::class, ['cliente' => $this->cliente])
            ->assertSee('BATERIAS')->assertSee('(2)')->assertSee('(1)') // chips com contagem
            ->assertSee('SN-U1')->assertSee('SN-B1')
            ->call('filtrarFamilia', 'F02')
            ->assertSee('SN-B1')->assertDontSee('SN-U1')->assertDontSee('SN-U2')
            ->call('filtrarFamilia', 'F02') // reclicar limpa
            ->assertSet('familia', '')
            ->assertSee('SN-U1')->assertSee('SN-B1');
    }

    public function test_linha_tem_link_para_a_ficha_do_equipamento(): void
    {
        Livewire::actingAs($this->admin)->test(Equipamentos::class, ['cliente' => $this->cliente])
            ->assertSeeHtml(route('equipamentos.ficha', $this->ups));   // URL com o mastamp (id_erp)
    }

    // 26.ª revisão de segurança: a família vem do PHC e entrava entre plicas num wire:click (que
    // é avaliado como JavaScript) — um apóstrofo partia o botão e um nome feito de propósito
    // corria código no browser. Agora vai por @js, sempre como texto.
    public function test_familia_com_apostrofo_ou_codigo_vai_como_texto(): void
    {
        $local = Local::where('cliente_id', $this->cliente->id)->firstOrFail();
        $maliciosa = "');alert(1)//";
        foreach (["D'Ávila", $maliciosa] as $k => $fam) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
                'fabricante' => 'Riello', 'modelo' => 'X', 'numero_serie' => 'SN-X'.$k, 'familia' => $fam, 'faminome' => 'FAM'.$k]);
        }

        Livewire::actingAs($this->admin)->test(Equipamentos::class, ['cliente' => $this->cliente])
            ->assertSeeHtml('wire:click="filtrarFamilia('.Js::from($maliciosa).')"')
            ->assertDontSeeHtml("filtrarFamilia('".$maliciosa."')")
            ->call('filtrarFamilia', "D'Ávila")   // o filtro funciona com o apóstrofo
            ->assertSee('SN-X0')->assertDontSee('SN-U1');
    }

    public function test_cliente_com_uma_so_familia_nao_mostra_chips(): void
    {
        $c2 = Cliente::create(['nome' => 'Mono', 'ativo' => true]);
        $l2 = Local::create(['cliente_id' => $c2->id, 'designacao' => 'Sede']);
        Equipamento::create(['local_id' => $l2->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-M1', 'familia' => 'F01', 'faminome' => 'UPS']);

        Livewire::actingAs($this->admin)->test(Equipamentos::class, ['cliente' => $c2])
            ->assertDontSee('Todas');
    }
}
