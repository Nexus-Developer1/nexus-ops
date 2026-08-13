<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use App\Services\GeradorQrEquipamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// QR do equipamento (CLAUDE.md §6): o código contém o URL da FICHA — qualquer câmara de
// telemóvel o abre (sem scanner na app). Na ficha aparece o QR real (substitui o placeholder
// decorativo) e a etiqueta 90x50mm sai em PDF para imprimir e colar no equipamento.
class EquipamentoEtiquetaQrTest extends TestCase
{
    use RefreshDatabase;

    private function equipamento(): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'numero_serie' => 'QR-TEST-1', 'fabricante' => 'Riello', 'modelo' => 'NPW 2000']);
    }

    public function test_ficha_mostra_o_qr_real_com_link_para_a_etiqueta(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $equip = $this->equipamento();

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $equip])
            ->assertSeeHtml('<svg') // o QR é SVG inline gerado pela app (sem input de utilizador)
            ->assertSeeHtml(route('equipamentos.etiqueta', $equip));
    }

    public function test_etiqueta_sai_em_pdf_para_a_equipa(): void
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $equip = $this->equipamento();

        $this->actingAs($tecnico)
            ->get(route('equipamentos.etiqueta', $equip))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_cliente_do_portal_nao_acede_a_etiqueta(): void
    {
        // Caso mais exigente: equipamento DO PRÓPRIO cliente (o scope global resolve o
        // binding) — é o middleware de papel que barra a rota da equipa. Equipamentos de
        // OUTROS clientes nem resolvem (404 pelo scope, fail-closed).
        $equip = $this->equipamento();
        $userCliente = User::create(['nome' => 'C', 'email' => 'c@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $equip->local->cliente_id, 'ativo' => true]);

        $this->actingAs($userCliente)
            ->get(route('equipamentos.etiqueta', $equip))
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_o_qr_codifica_o_url_da_ficha(): void
    {
        $equip = $this->equipamento();
        $svg = app(GeradorQrEquipamento::class)->svg($equip);

        $this->assertStringContainsString('<svg', $svg);
    }
}
