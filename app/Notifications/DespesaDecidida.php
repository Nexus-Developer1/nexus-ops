<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Email do processo de validação das despesas: o aprovador aprovou ou rejeitou o registo.
// Vai aos mesmos destinatários da submissão (quem criou, aprovador, financeiro). Pela fila.
// Template próprio da app (tema Nexus) — o Blade escapa o texto dos utilizadores (motivo).
class DespesaDecidida extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $registo instantâneo (FluxoAprovacaoDespesas::instantaneo) */
    public function __construct(public array $registo) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->registo;
        $aprovada = $r['estado'] === 'aprovada';
        $total = number_format($r['total'], 2, ',', ' ').' €';

        return (new MailMessage)
            ->subject('Despesa nº '.$r['id'].' · '.$r['colaborador'].' · '.$total.' — '.($aprovada ? 'APROVADA' : 'REJEITADA'))
            ->view('emails.despesa', [
                'modo' => 'decidida',
                'r' => $r,
                'nome' => $notifiable->nome ?? null,
                'reenvio' => false,
            ]);
    }
}
