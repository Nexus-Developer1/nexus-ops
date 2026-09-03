<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Acesso à agenda no Outlook, pedido pelo próprio na página da Agenda.
 *
 * O URL do feed é o SEGREDO (quem o tiver vê a agenda dessa pessoa), por isso este email só é
 * enviado para o endereço da própria conta — nunca para terceiros.
 */
class AcessoAgendaOutlook extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nexus Infra · Acesso à agenda no Outlook')
            ->view('emails.acesso-agenda', [
                'nome' => $notifiable->nome,
                'url' => $this->url,
            ]);
    }
}
