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

    public function __construct(public Relatorio $relatorio) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Relatório de intervenção ' . $this->relatorio->numero,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.relatorio',
            with: [
                'relatorio' => $this->relatorio,
                'intervencao' => $this->relatorio->intervencao,
                'cliente' => $this->relatorio->intervencao->equipamento->local->cliente,
                'equipamento' => $this->relatorio->intervencao->equipamento,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        // PDF lido do object storage (nunca da BD — ver CLAUDE.md §2).
        return [
            Attachment::fromData(
                fn () => Storage::disk('s3')->get($this->relatorio->pdf_path),
                str_replace('/', '-', $this->relatorio->numero) . '.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
