<?php

namespace App\Providers;

use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use App\Services\Erp\NullErpDriver;
use App\Services\Erp\SqlServerErpDriver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolve o driver do ERP conforme a configuração (config/erp.php).
        $this->app->bind(ErpSyncDriver::class, function () {
            return match (config('erp.driver')) {
                'sqlsrv' => new SqlServerErpDriver(),
                'fake' => new FakeErpDriver(),
                // Default seguro (ERP_DRIVER vazio): não injeta dados fictícios.
                default => new NullErpDriver(),
            };
        });
    }

    public function boot(): void
    {
        // Email de recuperação de palavra-passe em português (broker nativo).
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);

            return (new MailMessage)
                ->subject('Redefinição de palavra-passe — Nexus Ops')
                ->greeting('Olá,')
                ->line('Recebemos um pedido para redefinir a palavra-passe da sua conta.')
                ->action('Redefinir palavra-passe', $url)
                ->line('Este link expira em ' . config('auth.passwords.users.expire') . ' minutos.')
                ->line('Se não foi você que fez este pedido, ignore este email.')
                ->salutation('Cumprimentos, equipa Nexus Ops');
        });
    }
}
