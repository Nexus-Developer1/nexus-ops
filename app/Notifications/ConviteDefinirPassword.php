<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Convite para uma NOVA conta: o utilizador define a sua palavra-passe através de um link
// seguro (mecanismo de reset, broker 'invites'). Texto de CONVITE — não de recuperação.
class ConviteDefinirPassword extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Link para a página de definir password (token no URL; email como query, nunca o id).
        $url = route('convite.definir', ['token' => $this->token]).'?email='.urlencode($notifiable->getEmailForPasswordReset());

        $validade = (int) (config('auth.passwords.invites.expire') / (60 * 24)); // dias

        // View HTML própria (verde/branco, no tema do site) em vez do markdown genérico.
        return (new MailMessage)
            ->subject('Convite para o Nexus Ops — defina a sua palavra-passe')
            ->view('emails.convite', [
                'nome' => $notifiable->nome,
                'url' => $url,
                'validade' => $validade,
            ]);
    }
}
