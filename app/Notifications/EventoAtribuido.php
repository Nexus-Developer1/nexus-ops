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
        // View HTML própria (verde/branco, no tema do site — igual ao convite e ao envio de
        // relatórios) em vez do template markdown genérico do Laravel.
        return (new MailMessage)
            ->subject('Novo agendamento: ' . $this->evento->titulo)
            ->view('emails.evento-atribuido', [
                'evento' => $this->evento->loadMissing(['cliente', 'local', 'equipamento', 'tecnicosAdicionais']),
                'nome' => $notifiable->nome,
                'url' => route('agenda'),
            ]);
    }
}
