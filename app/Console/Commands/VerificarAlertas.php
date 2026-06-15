<?php

namespace App\Console\Commands;

use App\Enums\PapelUtilizador;
use App\Models\User;
use App\Notifications\ResumoAlertas;
use App\Services\Alertas\ServicoAlertas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

// Verifica os alertas proativos e envia um resumo aos administradores (CLAUDE.md §9).
// Agendado diariamente (ver routes/console.php).
class VerificarAlertas extends Command
{
    protected $signature = 'alertas:verificar';

    protected $description = 'Recolhe os alertas proativos e notifica os administradores.';

    public function handle(ServicoAlertas $servico): int
    {
        $alertas = $servico->recolher();

        $this->info($alertas->count() . ' alertas recolhidos.');

        if ($alertas->isEmpty()) {
            return self::SUCCESS;
        }

        $admins = User::where('papel', PapelUtilizador::Admin)->where('ativo', true)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ResumoAlertas($alertas));
            $this->info('Resumo enviado a ' . $admins->count() . ' administrador(es).');
        }

        return self::SUCCESS;
    }
}
