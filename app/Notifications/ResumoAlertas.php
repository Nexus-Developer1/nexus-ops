<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

// Resumo diário dos alertas proativos enviado aos administradores (CLAUDE.md §9).
class ResumoAlertas extends Notification
{
    use Queueable;

    public function __construct(public Collection $alertas) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Nexus Ops · ' . $this->alertas->count() . ' alertas requerem atenção')
            ->greeting('Olá ' . $notifiable->nome . ',')
            ->line('Há ' . $this->alertas->count() . ' alertas em aberto na operação:');

        // Até 10 alertas no corpo do email.
        foreach ($this->alertas->take(10) as $alerta) {
            $mail->line('• ' . $alerta['titulo'] . ' — ' . $alerta['descricao']);
        }

        return $mail->action('Ver alertas', route('alertas'));
    }
}
