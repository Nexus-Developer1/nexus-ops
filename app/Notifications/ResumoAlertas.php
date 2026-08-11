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
            ->subject('Nexus Infra · ' . $this->alertas->count() . ' alertas requerem atenção')
            ->greeting('Olá ' . $notifiable->nome . ',')
            ->line('Há ' . $this->alertas->count() . ' alertas em aberto na operação:');

        // Até 10 alertas no corpo do email.
        foreach ($this->alertas->take(10) as $alerta) {
            $mail->line('• ' . $this->semMarkdown($alerta['titulo']) . ' — ' . $this->semMarkdown($alerta['descricao']));
        }

        return $mail->action('Ver alertas', route('alertas'));
    }

    // Este email é um mailable markdown: o vendor escapa o HTML das linhas, mas a sintaxe
    // markdown É interpretada — "[label](url)" num texto editável de alerta viraria um link
    // clicável num email com remetente de confiança. O backslash escapa a pontuação para o
    // CommonMark, que o remove ao renderizar — o texto exibido fica exatamente igual.
    private function semMarkdown(string $texto): string
    {
        return addcslashes($texto, '\\`*_{}[]()#+.!|<>~=-');
    }
}
