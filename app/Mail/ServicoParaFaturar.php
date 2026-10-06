<?php

namespace App\Mail;

use App\Models\Relatorio;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Aviso ao COMERCIAL de que o serviço do relatório pode ser faturado (out. 2026): sai do job de
// envio, logo depois de o relatório chegar ao cliente (também nos envios agendados). Leva o nº
// da(s) encomenda(s) de peças ligadas à intervenção e o mesmo PDF que o cliente recebeu — o
// comercial não costuma ter conta na aplicação.
class ServicoParaFaturar extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $encomendas  ex.: ["Encomenda 123/2026", "Encomenda 45/2026 (ainda por chegar do PHC)"]
     */
    public function __construct(
        public Relatorio $relatorio,
        public array $encomendas,
        public ?string $enviadoPor,
        public string $pdfConteudo,
    ) {}

    public function envelope(): Envelope
    {
        $cliente = $this->relatorio->intervencao?->equipamento?->local?->cliente?->nome;

        return new Envelope(subject: 'Serviço para faturar — Relatório '.$this->relatorio->numero.($cliente ? ' · '.$cliente : ''));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.servico-para-faturar', with: [
            'relatorio' => $this->relatorio,
            'encomendas' => $this->encomendas,
            'enviadoPor' => $this->enviadoPor,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfConteudo, str_replace('/', '-', $this->relatorio->numero).'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
