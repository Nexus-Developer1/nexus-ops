<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use App\Services\Auditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Auditoria consultável (18.ª análise de segurança → melhoria #1): tabela append-only
// escrita via Auditor::registar nas ações sensíveis, com ecrã SÓ ADMIN em /auditoria.
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_login_falhado_fica_registado(): void
    {
        Livewire::test(\App\Livewire\Auth\Login::class)
            ->set('email', 'atacante@x.pt')
            ->set('password', 'errada')
            ->call('autenticar');

        $registo = Auditoria::where('acao', 'login_falhado')->firstOrFail();
        $this->assertSame('atacante@x.pt', $registo->detalhe['email']);
        $this->assertNull($registo->user_id); // anónimo — sem sessão iniciada
    }

    public function test_mudar_cliente_do_equipamento_fica_registado(): void
    {
        $admin = $this->admin();
        $c1 = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $c2 = Cliente::create(['nome' => 'Beta', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $c1->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'AUD-1']);

        Livewire::actingAs($admin)->test(\App\Livewire\Equipamentos\Ficha::class, ['equipamento' => $equip])
            ->call('mudarCliente', $c2->id);

        $registo = Auditoria::where('acao', 'equipamento_mudou_cliente')->firstOrFail();
        $this->assertSame('a@nexus.pt', $registo->email);
        $this->assertSame('Equipamento', $registo->entidade_tipo);
        $this->assertSame('ACME', $registo->detalhe['de']);
        $this->assertSame('Beta', $registo->detalhe['para']);
    }

    public function test_falha_na_auditoria_nao_rebenta_a_acao(): void
    {
        // Sem a tabela (simula BD degradada), o registar loga e segue — a ação nunca parte.
        \Illuminate\Support\Facades\Schema::drop('auditoria');

        Auditor::registar('teste');

        $this->assertTrue(true); // chegou aqui sem exceção
    }

    public function test_ecra_e_so_para_admin(): void
    {
        // Ordem deliberada (cliente → técnico → admin): o abort(403) dentro do mount de um
        // componente Livewire deixa o binding do redirector trocado para o pedido SEGUINTE
        // do mesmo teste (artefacto de teste — em produção cada pedido tem contentor novo).
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $userCliente = User::create(['nome' => 'C', 'email' => 'c@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $cliente->id, 'ativo' => true]);
        $this->actingAs($userCliente)->get('/auditoria')->assertRedirect(route('portal.dashboard'));

        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->actingAs($tecnico)->get('/auditoria')->assertForbidden();

        $this->actingAs($this->admin())->get('/auditoria')->assertOk()->assertSee('Auditoria');
    }

    public function test_ecra_filtra_por_acao_e_pesquisa(): void
    {
        $admin = $this->admin();
        Auditor::registar('login_falhado', detalhe: ['email' => 'x@x.pt']);
        $this->actingAs($admin); // as ações seguintes ficam com o email do admin
        Auditor::registar('relatorio_enviado', detalhe: ['numero' => '2026/0042', 'para' => 'cliente@acme.pt']);

        Livewire::actingAs($admin)->test(\App\Livewire\Auditoria\Listagem::class)
            ->assertSee('login falhado')
            ->assertSee('relatorio enviado')
            ->set('acao', 'login_falhado')
            ->assertSee('login falhado')
            ->assertDontSee('2026/0042');
    }
}
