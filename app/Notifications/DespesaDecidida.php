<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Email do processo de validação das despesas: o aprovador aprovou ou rejeitou o registo.
// Vai aos mesmos destinatários da submissão (quem criou, aprovador, financeiro). Pela fila.
class DespesaDecidida extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $registo instantâneo (FluxoAprovacaoDespesas::instantaneo) */
    public function __construct(public array $registo) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->registo;
        $aprovada = $r['estado'] === 'aprovada';
        $total = number_format($r['total'], 2, ',', ' ').' €';
        $nome = $notifiable->nome ?? null;

        $mail = (new MailMessage)
            ->subject('Despesa nº '.$r['id'].' · '.$r['colaborador'].' · '.$total.' — '.($aprovada ? 'APROVADA' : 'REJEITADA'))
            ->greeting($nome ? 'Olá '.$nome.',' : 'Olá,')
            ->line('A despesa nº '.$r['id'].' de '.$this->semMarkdown($r['colaborador']).' ('.$total.') foi '.($aprovada ? 'APROVADA' : 'REJEITADA')
                .' por '.$this->semMarkdown((string) ($r['decisor'] ?? '—')).($r['decidido_em'] ? ' em '.$r['decidido_em'] : '').'.');

        if (! $aprovada) {
            $mail->line('Motivo: '.$this->semMarkdown((string) ($r['motivo'] ?: '—')))
                ->line('Quem registou a despesa pode corrigi-la e voltar a guardar — fica de novo pendente de aprovação.');
        }

        return $mail->action('Ver despesa', $r['url']);
    }

    private function semMarkdown(string $texto): string
    {
        return addcslashes($texto, '\\`*_{}[]()#+.!|<>~=-');
    }
}
