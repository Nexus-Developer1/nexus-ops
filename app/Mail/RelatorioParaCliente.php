<?php

namespace App\Mail;

use App\Models\Relatorio;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

// Email que entrega o relatório de intervenção ao cliente, com o PDF anexado.
// Última etapa da cadeia do domínio: Relatório → enviado ao Cliente (ver CLAUDE.md §1 e §6).
class RelatorioParaCliente extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Relatorio $relatorio,
        public string $assunto,
        public string $mensagem,
    ) {}

    public function envelope(): Envelope
    {
        // Assunto escrito à mão na página de composição.
        return new Envelope(subject: $this->assunto);
    }

    public function content(): Content
    {
        // O corpo é a mensagem escrita à mão; o PDF vai em anexo. View HTML própria
        // (verde/branco, no tema do site) em vez do markdown genérico.
        return new Content(
            view: 'emails.relatorio',
            with: [
                'relatorio' => $this->relatorio,
                'mensagem' => $this->mensagem,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        // PDF lido do object storage (nunca da BD — ver CLAUDE.md §2).
        return [
            Attachment::fromData(
                fn () => Storage::disk()->get($this->relatorio->pdf_path),
                str_replace('/', '-', $this->relatorio->numero).'.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
