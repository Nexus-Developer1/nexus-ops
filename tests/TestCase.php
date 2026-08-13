<?php

namespace Tests;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\VerificarCodigo;
use App\Models\User;
use App\Notifications\CodigoMfaNotification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // GUARDA-CHUVA DE SEGURANÇA: força sempre a BD de teste dedicada.
        // O container injeta o .env como variáveis reais do SO (env_file no
        // docker-compose), que vencem o phpunit.xml mesmo com force="true".
        // Sem isto, o RefreshDatabase corre na BD de DEV (`nexus`) e apaga-a.
        config([
            'database.connections.pgsql.database' => 'nexus_testing',
            'mail.default' => 'array',
            'queue.default' => 'sync',
        ]);

        // Tripwire: aborta se, por alguma razão, a BD de teste não for a esperada.
        if (config('database.connections.pgsql.database') !== 'nexus_testing') {
            throw new \RuntimeException('Os testes têm de correr na BD nexus_testing.');
        }

        return $app;
    }

    // Completa o login de duas etapas (MFA): faz a 1.ª etapa (email+password), captura o
    // código enviado por email e submete-o na 2.ª etapa. Devolve o componente
    // VerificarCodigo já depois de `verificar`, para o teste encadear as suas asserções
    // (ex.: ->assertRedirect(...)). Usa Notification::fake para ler o código em claro.
    protected function loginComMfa(string $email, string $password): Testable
    {
        Notification::fake();

        Livewire::test(Login::class)
            ->set('email', $email)
            ->set('password', $password)
            ->call('autenticar')
            ->assertRedirect(route('mfa.verificar'));

        $user = User::whereRaw('lower(email) = ?', [strtolower(trim($email))])->firstOrFail();

        $codigo = null;
        Notification::assertSentTo($user, CodigoMfaNotification::class, function ($notificacao) use (&$codigo) {
            $codigo = $notificacao->codigo;

            return true;
        });

        return Livewire::test(VerificarCodigo::class)
            ->set('codigo', $codigo)
            ->call('verificar');
    }
}
