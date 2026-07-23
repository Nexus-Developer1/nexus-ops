<?php

namespace App\Notifications;

use App\Models\EventoAgenda;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Notifica o técnico de que lhe foi atribuído um novo evento na agenda (CLAUDE.md §6).
// Em fila (§12): o envio de email não pode atrasar o guardar do evento.
class EventoAtribuido extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public EventoAgenda $evento) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Novo agendamento: ' . $this->evento->titulo)
            ->greeting('Olá ' . $notifiable->nome . ',')
            ->line('Foi-lhe atribuído um novo evento na agenda da Nexus Infra.')
            ->line('**' . $this->evento->titulo . '**')
            ->line('Quando: ' . $this->evento->inicio->translatedFormat('d/m/Y H:i') . ' – ' . $this->evento->fim->format('H:i'))
            ->action('Ver agenda', route('agenda'));
    }
}
