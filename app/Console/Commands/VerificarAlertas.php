<?php

namespace App\Console\Commands;

use App\Enums\PapelUtilizador;
use App\Models\User;
use App\Notifications\ResumoAlertas;
use App\Services\Alertas\ServicoAlertas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

// Verifica os alertas proativos e envia um resumo aos administradores (CLAUDE.md §9) e, a
// cada técnico, o resumo dos alertas ATRIBUÍDOS a ele. Agendado diariamente (routes/console.php).
class VerificarAlertas extends Command
{
    protected $signature = 'alertas:verificar';

    protected $description = 'Recolhe os alertas proativos e notifica os administradores.';

    public function handle(ServicoAlertas $servico): int
    {
        $alertas = $servico->recolher();

        $this->info($alertas->count().' alertas recolhidos.');

        if ($alertas->isEmpty()) {
            return self::SUCCESS;
        }

        $admins = User::where('papel', PapelUtilizador::Admin)->where('ativo', true)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ResumoAlertas($alertas));
            $this->info('Resumo enviado a '.$admins->count().' administrador(es).');
        }

        // Técnicos: só os alertas atribuídos a cada um (os da equipa vão no resumo dos admins).
        $tecnicos = User::where('papel', PapelUtilizador::Tecnico)->where('ativo', true)->whereNotNull('email')->get();
        $enviados = 0;
        foreach ($tecnicos as $tecnico) {
            $meus = $alertas->filter(fn ($a) => in_array($tecnico->id, $a['atribuido_a'], true))->values();
            if ($meus->isNotEmpty()) {
                $tecnico->notify(new ResumoAlertas($meus));
                $enviados++;
            }
        }
        if ($enviados > 0) {
            $this->info('Resumo de alertas atribuídos enviado a '.$enviados.' técnico(s).');
        }

        return self::SUCCESS;
    }
}
