<?php

namespace App\Mail;

use App\Models\Relatorio;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Aviso interno: o job de envio de um relatório ao cliente falhou de vez (exceção, timeout ou
// crash do worker). Vai a quem enviou e ao suporte — antes a falha ficava só em failed_jobs e
// o técnico julgava o relatório entregue (22.ª revisão de segurança).
class FalhaEnvioRelatorio extends Mailable
{
    public function __construct(
        public Relatorio $relatorio,
        public string $para,
        public string $erro,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Nexus] Envio do relatório {$this->relatorio->numero} falhou");
    }

    public function content(): Content
    {
        $texto = "O envio do relatório {$this->relatorio->numero} para {$this->para} FALHOU e não chegou ao cliente.\n\n"
            .'Erro: '.($this->erro !== '' ? $this->erro : 'desconhecido')."\n\n"
            .'Volte a enviar a partir da página do relatório. Se o erro se repetir, contacte o suporte.';

        return new Content(htmlString: nl2br(e($texto)));
    }
}
