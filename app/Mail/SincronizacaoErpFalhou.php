<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Aviso ao suporte quando a sincronização manual com o PHC falha (ver Jobs\SincronizarErp).
class SincronizacaoErpFalhou extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, string> $falhas etapa → mensagem de erro */
    public function __construct(public array $falhas) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nexus Infra: sincronização com o PHC falhou');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sync-falhou', with: ['falhas' => $this->falhas]);
    }
}
