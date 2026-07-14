<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Código de verificação em duas etapas, enviado por email após a palavra-passe.
// NÃO implementa ShouldQueue de propósito: o código tem de chegar de imediato ao
// login, sem depender de um worker de fila.
class CodigoMfaNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $codigo,
        public int $validadeMinutos,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('O seu código de acesso — Nexus Infra')
            ->view('emails.codigo-mfa', [
                'nome' => $notifiable->nome,
                'codigo' => $this->codigo,
                'validade' => $this->validadeMinutos,
            ]);
    }
}
