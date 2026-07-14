<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\VerificarCodigo;
use App\Models\CodigoMfa;
use App\Models\User;
use App\Notifications\CodigoMfaNotification;
use App\Services\Auth\ServicoMfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Verificação em duas etapas (MFA por email): após email+password, o utilizador recebe
// um código de 6 dígitos e só entra depois de o introduzir. Ver secção 7 do CLAUDE.md.
class MfaTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email = 'admin@nexus.pt'): User
    {
        return User::create([
            'nome' => 'Admin',
            'email' => $email,
            'password' => 'segredo123',
            'papel' => PapelUtilizador::Admin,
            'ativo' => true,
        ]);
    }

    public function test_password_certa_envia_codigo_e_nao_autentica(): void
    {
        Notification::fake();
        $user = $this->utilizador();

        Livewire::test(Login::class)
            ->set('email', 'admin@nexus.pt')
            ->set('password', 'segredo123')
            ->call('autenticar')
            ->assertRedirect(route('mfa.verificar'));

        // Ainda NÃO está autenticado — falta a 2.ª etapa.
        $this->assertGuest();
        $this->assertSame($user->id, session('mfa.user_id'));
        $this->assertDatabaseHas('codigos_mfa', ['user_id' => $user->id, 'usado_em' => null]);
        Notification::assertSentTo($user, CodigoMfaNotification::class);
    }

    public function test_password_errada_nao_envia_codigo(): void
    {
        Notification::fake();
        $this->utilizador();

        Livewire::test(Login::class)
            ->set('email', 'admin@nexus.pt')
            ->set('password', 'errada')
            ->call('autenticar')
            ->assertHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('codigos_mfa', 0);
        Notification::assertNothingSent();
    }

    public function test_codigo_correto_completa_o_login(): void
    {
        $this->utilizador();

        $this->loginComMfa('admin@nexus.pt', 'segredo123')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_codigo_incorreto_falha_e_nao_autentica(): void
    {
        Notification::fake();
        $user = $this->utilizador();

        $codigo = $this->emitirEObter($user);
        $errado = $codigo === '000000' ? '111111' : '000000';

        Livewire::test(VerificarCodigo::class)
            ->set('codigo', $errado)
            ->call('verificar')
            ->assertHasErrors('codigo');

        $this->assertGuest();
        $this->assertSame(1, CodigoMfa::where('user_id', $user->id)->first()->tentativas);
    }

    public function test_codigo_expirado_e_recusado(): void
    {
        Notification::fake();
        $user = $this->utilizador();
        $codigo = $this->emitirEObter($user);

        // Força a expiração do código emitido.
        CodigoMfa::where('user_id', $user->id)->update(['expira_em' => now()->subMinute()]);

        Livewire::test(VerificarCodigo::class)
            ->set('codigo', $codigo)
            ->call('verificar')
            ->assertHasErrors('codigo');

        $this->assertGuest();
    }

    public function test_demasiadas_tentativas_queima_o_codigo(): void
    {
        Notification::fake();
        $user = $this->utilizador();
        $codigo = $this->emitirEObter($user);
        $errado = $codigo === '000000' ? '111111' : '000000';

        $componente = Livewire::test(VerificarCodigo::class);

        // Esgota o limite de tentativas com códigos errados.
        for ($i = 0; $i <= ServicoMfa::MAX_TENTATIVAS; $i++) {
            $componente->set('codigo', $errado)->call('verificar');
        }

        // Código queimado: mesmo o correto já não serve.
        $componente->set('codigo', $codigo)->call('verificar')->assertHasErrors('codigo');
        $this->assertGuest();
        $this->assertNotNull(CodigoMfa::where('user_id', $user->id)->first()->usado_em);
    }

    public function test_pagina_verificar_sem_sessao_pendente_volta_ao_login(): void
    {
        Livewire::test(VerificarCodigo::class)
            ->assertRedirect(route('login'));
    }

    public function test_reenviar_dentro_do_cooldown_avisa(): void
    {
        Notification::fake();
        $user = $this->utilizador();
        $this->emitirEObter($user); // 1.º envio, imediatamente antes

        Livewire::test(VerificarCodigo::class)
            ->call('reenviar')
            ->assertHasErrors('codigo');
    }

    // Emite um código para o utilizador (como faz o login) e devolve o valor em claro,
    // deixando o estado MFA pendente na sessão para o componente VerificarCodigo o ler.
    private function emitirEObter(User $user): string
    {
        app(ServicoMfa::class)->enviar($user);

        session([
            'mfa.user_id' => $user->id,
            'mfa.remember' => false,
            'mfa.email' => $user->email,
        ]);

        $codigo = null;
        Notification::assertSentTo($user, CodigoMfaNotification::class, function ($n) use (&$codigo) {
            $codigo = $n->codigo;

            return true;
        });

        return $codigo;
    }
}
