<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Email do processo de validação das despesas: um registo foi guardado (ou corrigido depois
// de rejeitado) e aguarda aprovação. Vai a quem criou, ao aprovador e ao financeiro. Pela
// FILA — o envio de email nunca entra no caminho do clique. Recebe um INSTANTÂNEO (array),
// não o modelo: o email descreve o registo tal como foi submetido. Template próprio da app
// (tema Nexus, como os emails da agenda) — o Blade escapa o texto escrito pelos utilizadores.
class DespesaSubmetida extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $registo instantâneo (FluxoAprovacaoDespesas::instantaneo) */
    public function __construct(public array $registo, public bool $reenvio = false) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->registo;
        $total = number_format($r['total'], 2, ',', ' ').' €';

        return (new MailMessage)
            ->subject('Despesa nº '.$r['id'].' · '.$r['colaborador'].' · '.$total.($this->reenvio ? ' — corrigida, aguarda aprovação' : ' — aguarda aprovação'))
            ->view('emails.despesa', [
                'modo' => 'submetida',
                'r' => $r,
                'nome' => $notifiable->nome ?? null,
                'reenvio' => $this->reenvio,
            ]);
    }
}
