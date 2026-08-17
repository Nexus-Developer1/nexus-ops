<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// O URL da ficha passa a mostrar o MASTAMP do PHC (id_erp) em vez do id interno:
// /ativos/Mic23091346621,906000001 em vez de /ativos/17822. O id interno TEM de continuar
// a resolver — as etiquetas QR já impressas e coladas nos equipamentos levam-no dentro do
// código e não se reimprimem. Ver Equipamento::getRouteKey/resolveRouteBinding.
class EquipamentoUrlMastampTest extends TestCase
{
    use RefreshDatabase;

    // Formato real do PHC (ma.mastamp): prefixo + dígitos + vírgula + dígitos.
    private const MASTAMP = 'Mic23091346621,906000001';

    private function equipamento(?string $mastamp = self::MASTAMP): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'id_erp' => $mastamp, 'numero_serie' => 'S-1', 'fabricante' => 'Riello', 'modelo' => 'NPW 2000']);
    }

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    public function test_url_da_ficha_traz_o_mastamp_e_nao_o_id_interno(): void
    {
        $equip = $this->equipamento();

        $url = route('equipamentos.ficha', $equip);

        $this->assertStringEndsWith('/equipamentos/'.self::MASTAMP, $url);
        // A vírgula do mastamp fica literal no URL (não escapada) — é o que o Davide vê na barra.
        $this->assertStringNotContainsString('%2C', $url);
        $this->assertStringNotContainsString('/equipamentos/'.$equip->id, $url);
    }

    public function test_ficha_abre_pelo_mastamp(): void
    {
        $equip = $this->equipamento();

        $this->actingAs($this->tecnico())
            ->get(route('equipamentos.ficha', $equip))
            ->assertOk()
            ->assertSee('NPW 2000');
    }

    // A garantia que protege as etiquetas QR já impressas: o id interno continua a resolver —
    // e redireciona para o URL canónico, para a barra do browser mostrar sempre o mastamp.
    public function test_id_interno_redireciona_para_o_url_do_mastamp(): void
    {
        $equip = $this->equipamento();
        $tecnico = $this->tecnico();

        $this->actingAs($tecnico)
            ->get('/equipamentos/'.$equip->id)
            ->assertRedirect(route('equipamentos.ficha', $equip));

        $this->actingAs($tecnico)
            ->followingRedirects()
            ->get('/equipamentos/'.$equip->id)
            ->assertOk()
            ->assertSee('NPW 2000');
    }

    // Equipamento manual ("não vendido por nós") não tem mastamp — o URL cai para o id.
    public function test_equipamento_manual_sem_mastamp_usa_o_id(): void
    {
        $equip = $this->equipamento(null);

        $this->assertStringEndsWith('/equipamentos/'.$equip->id, route('equipamentos.ficha', $equip));

        $this->actingAs($this->tecnico())
            ->get(route('equipamentos.ficha', $equip))
            ->assertOk();
    }

    // Defesa: um mastamp com '/' partiria o segmento do URL — cai para o id em vez de dar 404.
    public function test_mastamp_com_barra_cai_para_o_id(): void
    {
        $equip = $this->equipamento('MA/2309/001');

        $this->assertStringEndsWith('/equipamentos/'.$equip->id, route('equipamentos.ficha', $equip));
    }

    public function test_etiqueta_qr_tambem_usa_o_mastamp(): void
    {
        $equip = $this->equipamento();

        $this->assertStringEndsWith('/equipamentos/'.self::MASTAMP.'/etiqueta', route('equipamentos.etiqueta', $equip));

        $this->actingAs($this->tecnico())
            ->get(route('equipamentos.etiqueta', $equip))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_mastamp_inexistente_da_404(): void
    {
        $this->equipamento();

        $this->actingAs($this->tecnico())
            ->get('/equipamentos/NAO-EXISTE,000')
            ->assertNotFound();
    }

    // O caminho antigo /ativos/... (etiquetas QR impressas, favoritos) redireciona permanente
    // para /equipamentos/..., segmento a segmento — e a listagem antiga também.
    public function test_caminho_antigo_ativos_redireciona_para_equipamentos(): void
    {
        $equip = $this->equipamento();
        $tecnico = $this->tecnico();

        $this->actingAs($tecnico)->get('/ativos')
            ->assertMovedPermanently('/equipamentos');
        $this->actingAs($tecnico)->get('/ativos/'.$equip->id)
            ->assertMovedPermanently('/equipamentos/'.$equip->id);
        $this->actingAs($tecnico)->get('/ativos/'.self::MASTAMP.'/etiqueta')
            ->assertMovedPermanently('/equipamentos/'.self::MASTAMP.'/etiqueta');

        // A viagem completa de um QR impresso: /ativos/<id> → /equipamentos/<id> → mastamp.
        $this->actingAs($tecnico)
            ->followingRedirects()
            ->get('/ativos/'.$equip->id)
            ->assertOk()
            ->assertSee('NPW 2000');
    }

    // Fail-closed: um equipamento apagado não se abre nem pelo mastamp nem pelo id.
    public function test_equipamento_apagado_nao_resolve(): void
    {
        $equip = $this->equipamento();
        $id = $equip->id;
        $equip->delete();
        $tecnico = $this->tecnico();

        $this->actingAs($tecnico)->get('/equipamentos/'.self::MASTAMP)->assertNotFound();
        $this->actingAs($tecnico)->get('/equipamentos/'.$id)->assertNotFound();
    }

    // Isolamento por cliente mantém-se com a chave nova: o mastamp de OUTRO cliente não resolve.
    public function test_cliente_do_portal_nao_resolve_mastamp_de_outro_cliente(): void
    {
        $equip = $this->equipamento();
        $outro = Cliente::create(['nome' => 'Outro', 'ativo' => true]);
        $userCliente = User::create(['nome' => 'C', 'email' => 'c@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $outro->id, 'ativo' => true]);

        $this->actingAs($userCliente)
            ->get('/equipamentos/'.self::MASTAMP)
            ->assertNotFound();
    }
}
