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

        return (new MailMessage)
            ->subject('Convite para o Nexus Ops — defina a sua palavra-passe')
            ->greeting('Olá '.$notifiable->nome.',')
            ->line('Foi convidado(a) para aceder ao Nexus Ops.')
            ->line('Para ativar a sua conta, defina a sua palavra-passe no botão abaixo.')
            ->action('Definir palavra-passe', $url)
            ->line('Este convite expira em '.$validade.' dias.')
            ->line('Se não estava à espera deste convite, ignore este email.')
            ->salutation('Cumprimentos, equipa Nexus Ops');
    }
}
