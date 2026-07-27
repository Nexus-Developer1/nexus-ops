<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Relatório de resultado da sincronização AGENDADA com o PHC (ver Jobs\SincronizarErp):
// vai sempre ao suporte às horas do cron — sucesso ou falha. O sync manual (botão) não envia.
class ResultadoSincronizacaoErp extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, array{ok: bool, detalhe: string}> $resultados etapa → resultado */
    public function __construct(public array $resultados, public bool $falhou) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->falhou
            ? 'Nexus Infra: sincronização com o PHC falhou'
            : 'Nexus Infra: sincronização com o PHC feita com sucesso');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sync-resultado', with: [
            'resultados' => $this->resultados,
            'falhou' => $this->falhou,
        ]);
    }
}
