<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Auditoria\Listagem;
use App\Livewire\Auth\AceitarConvite;
use App\Livewire\Utilizadores\Adicionar;
use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

// Vaga 1 (segurança): política de passwords a sério (min 10 + letras + números; HIBP em
// produção), mudar a password invalida as sessões antigas, e a gestão de utilizadores
// fica auditada.
class Vaga1SegurancaTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_fraca_e_recusada_no_convite(): void
    {
        $novo = User::create(['nome' => 'Novo', 'email' => 'novo@nexus.pt', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $token = Password::broker('invites')->createToken($novo);

        // 8 chars (o mínimo antigo) e sem números: as duas regras novas recusam.
        Livewire::test(AceitarConvite::class, ['token' => $token])
            ->set('email', 'novo@nexus.pt')
            ->set('password', 'fraquita')
            ->set('password_confirmation', 'fraquita')
            ->call('definir')
            ->assertHasErrors(['password']);

        $this->assertNull($novo->fresh()->password);
    }

    public function test_mudar_password_invalida_sessoes_antigas(): void
    {
        $user = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'antiga12345', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Sessão autenticada ANTES da mudança de password (marca antiga).
        $this->actingAs($user);
        session(['autenticado_em' => now()->subHour()->timestamp]);
        $this->get('/ativos')->assertOk(); // sessão válida

        // A password muda (noutro dispositivo) → esta sessão é expulsa no pedido seguinte.
        $user->forceFill(['password_alterada_em' => now()])->save();
        $this->get('/ativos')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sessao_autenticada_depois_da_mudanca_sobrevive(): void
    {
        $user = User::create(['nome' => 'Téc', 'email' => 't2@nexus.pt', 'password' => 'antiga12345', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $user->forceFill(['password_alterada_em' => now()->subHour()])->save();

        $this->actingAs($user);
        session(['autenticado_em' => now()->timestamp]); // login DEPOIS da mudança

        $this->get('/ativos')->assertOk();
    }

    public function test_gestao_de_utilizadores_fica_auditada(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        session(['autenticado_em' => now()->timestamp]);

        // Convidar + eliminar deixam rasto (a eliminação é permanente e era silenciosa).
        Livewire::actingAs($admin)->test(Adicionar::class)
            ->set('nome', 'Técnico Novo')
            ->set('email', 'tn@nexus.pt')
            ->call('convidar');
        $this->assertTrue(Auditoria::where('acao', 'utilizador_convidado')->exists());

        $alvo = User::where('email', 'tn@nexus.pt')->firstOrFail();
        Livewire::actingAs($admin)->test(Adicionar::class)
            ->call('eliminar', $alvo->id);

        $registo = Auditoria::where('acao', 'utilizador_eliminado')->firstOrFail();
        $this->assertSame('tn@nexus.pt', $registo->detalhe['email']);
        $this->assertNull(User::find($alvo->id));
    }

    public function test_auditoria_pesquisa_por_id_de_entidade(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        Auditoria::create(['acao' => 'contrato_mudou_estado', 'entidade_tipo' => 'Contrato', 'entidade_id' => 42, 'detalhe' => ['numero' => 'C-42']]);
        Auditoria::create(['acao' => 'contrato_mudou_estado', 'entidade_tipo' => 'Contrato', 'entidade_id' => 99, 'detalhe' => ['numero' => 'C-99']]);

        Livewire::actingAs($admin)->test(Listagem::class)
            ->set('pesquisa', '#42')
            ->assertSee('Contrato #42')
            ->assertDontSee('Contrato #99');
    }
}
