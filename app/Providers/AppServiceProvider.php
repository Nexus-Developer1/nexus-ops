<?php

namespace App\Providers;

use App\Mail\Transport\GraphTransport;
use App\Models\User;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use App\Services\Erp\NullErpDriver;
use App\Services\Erp\SqlServerErpDriver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\SqlServerConnector;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
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

        // Regista o driver de BD 'dblib' (SQL Server via pdo_dblib/FreeTDS), que o Laravel
        // não reconhece de origem. Reutiliza o connector e a connection do SQL Server — o
        // dialeto é o mesmo. O SqlServerConnector já gera um DSN 'dblib:...' quando o
        // pdo_dblib está disponível; só falta mapear o NOME 'dblib' para essas classes:
        //   1) connector → cria o PDO (ligação) usando o DSN dblib;
        //   2) resolver → embrulha o PDO numa SqlServerConnection (gramática SQL Server).
        $this->app->bind('db.connector.dblib', fn () => new SqlServerConnector());

        Connection::resolverFor('dblib', function ($connection, $database, $prefix, $config) {
            return new SqlServerConnection($connection, $database, $prefix, $config);
        });
    }

    public function boot(): void
    {
        // Única capacidade exclusiva do admin: gerir/convidar utilizadores. O técnico tem todo
        // o resto (ver grupos de rotas). Usada no componente (abort_unless) e para esconder o link.
        Gate::define('gerir-utilizadores', fn (User $utilizador) => $utilizador->ehAdmin());

        // Transporte de email 'graph' (Microsoft Graph, app-only). Lazy: o closure só corre
        // quando o mailer 'graph' é resolvido. Credenciais em config/services.php (via env).
        Mail::extend('graph', function () {
            $c = config('services.microsoft_graph');

            return new GraphTransport($c['tenant_id'], $c['client_id'], $c['client_secret'], $c['sender']);
        });

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
