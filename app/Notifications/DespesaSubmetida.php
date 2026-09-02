<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Email do processo de validação das despesas: um registo foi guardado (ou corrigido depois
// de rejeitado) e aguarda aprovação. Três variantes, uma por papel (pedido da equipa):
//   aprovador   → pedido de aprovação ("aguarda a SUA aprovação", botão para decidir)
//   criador     → confirmação de submissão ("será avisado da decisão")
//   informativo → registo para o financeiro (sem a parte de aprovar)
// Pela FILA — o envio de email nunca entra no caminho do clique. Recebe um INSTANTÂNEO
// (array), não o modelo. Template próprio da app (tema Nexus) — o Blade escapa o texto
// escrito pelos utilizadores.
class DespesaSubmetida extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $registo  instantâneo (FluxoAprovacaoDespesas::instantaneo)
     * @param  'aprovador'|'criador'|'informativo'  $variante
     */
    public function __construct(public array $registo, public bool $reenvio = false, public string $variante = 'aprovador') {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->registo;
        $total = number_format($r['total'], 2, ',', ' ').' €';

        $sufixo = match ($this->variante) {
            'aprovador' => $this->reenvio ? ' — corrigida, aguarda a sua aprovação' : ' — aguarda a sua aprovação',
            'criador' => $this->reenvio ? ' — corrigida e reenviada para aprovação' : ' — submetida para aprovação',
            default => $this->reenvio ? ' — corrigida, aguarda aprovação' : ' — aguarda aprovação',
        };

        return (new MailMessage)
            ->subject('Despesa nº '.$r['id'].' · '.$r['colaborador'].' · '.$total.$sufixo)
            ->view('emails.despesa', [
                'modo' => 'submetida',
                'variante' => $this->variante,
                'r' => $r,
                'nome' => $notifiable->nome ?? null,
                'reenvio' => $this->reenvio,
            ]);
    }
}
