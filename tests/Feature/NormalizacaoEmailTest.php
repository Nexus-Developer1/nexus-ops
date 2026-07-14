<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Auth\EsqueciPassword;
use App\Livewire\Utilizadores\Adicionar;
use App\Models\User;
use App\Notifications\ConviteDefinirPassword;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Emails são case-insensitive: guardados sempre em minúsculas (mutator) e o input é
// normalizado nos pontos de auth (login, reset, convite).
class NormalizacaoEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutator_grava_email_em_minusculas(): void
    {
        $u = User::create(['nome' => 'X', 'email' => '  Suporte@NXS.pt ', 'password' => 'segredo123', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        $this->assertSame('suporte@nxs.pt', $u->fresh()->email); // minúsculas + trim
    }

    public function test_login_com_maiusculas_autentica_a_conta_minuscula(): void
    {
        User::create(['nome' => 'Admin', 'email' => 'suporte@nxs.pt', 'password' => 'segredo123', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        // Login em duas etapas: a capitalização diferente encontra a conta minúscula,
        // envia o código e, após verificação, autentica a conta certa.
        $this->loginComMfa('Suporte@NXS.pt', 'segredo123')   // capitalização diferente
            ->assertHasNoErrors();

        $this->assertAuthenticated();
        $this->assertSame('suporte@nxs.pt', auth()->user()->email);
    }

    public function test_convite_bloqueia_duplicado_por_capitalizacao(): void
    {
        Notification::fake();
        $admin = User::create(['nome' => 'Admin', 'email' => 'admin@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        User::create(['nome' => 'Já existe', 'email' => 'joao@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        Livewire::actingAs($admin)->test(Adicionar::class)
            ->set('nome', 'Outro')
            ->set('email', 'JOAO@nexus.pt')     // mesmo email, capitalização diferente
            ->call('convidar')
            ->assertHasErrors(['email' => 'unique']);

        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['joao@nexus.pt'])->count());
        Notification::assertNothingSent();
    }

    public function test_convite_com_maiusculas_grava_em_minusculas(): void
    {
        Notification::fake();
        $admin = User::create(['nome' => 'Admin', 'email' => 'admin@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        Livewire::actingAs($admin)->test(Adicionar::class)
            ->set('nome', 'Nova')
            ->set('email', 'Nova.Pessoa@NXS.pt')
            ->call('convidar')
            ->assertHasNoErrors();

        $novo = User::where('email', 'nova.pessoa@nxs.pt')->firstOrFail();
        Notification::assertSentTo($novo, ConviteDefinirPassword::class);
    }

    public function test_pedir_reset_com_maiusculas_encontra_a_conta(): void
    {
        Notification::fake();
        $u = User::create(['nome' => 'Admin', 'email' => 'suporte@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        Livewire::test(EsqueciPassword::class)
            ->set('email', 'SUPORTE@nxs.pt')
            ->call('enviarLink')
            ->assertHasNoErrors();

        // O broker encontrou a conta e enviou a notificação de reset.
        Notification::assertSentTo($u, ResetPassword::class);
    }
}
