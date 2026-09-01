<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Email do processo de validação das despesas: um registo foi guardado (ou corrigido depois
// de rejeitado) e aguarda aprovação. Vai a quem criou, ao aprovador e ao financeiro. Pela
// FILA — o envio de email nunca entra no caminho do clique. Recebe um INSTANTÂNEO (array),
// não o modelo: o email descreve o registo tal como foi submetido.
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
        $nome = $notifiable->nome ?? null;

        $mail = (new MailMessage)
            ->subject('Despesa nº '.$r['id'].' · '.$r['colaborador'].' · '.$total.($this->reenvio ? ' — corrigida, aguarda aprovação' : ' — aguarda aprovação'))
            ->greeting($nome ? 'Olá '.$nome.',' : 'Olá,')
            ->line(($this->reenvio ? 'A despesa nº '.$r['id'].' foi corrigida e volta a aguardar aprovação.' : 'Foi registada uma despesa que aguarda aprovação.'))
            ->line('Colaborador: '.$this->semMarkdown($r['colaborador']).' · Total: '.$total);

        foreach (array_slice($r['linhas'], 0, 15) as $l) {
            $mail->line('• '.$l['data'].' · '.$this->semMarkdown($l['categoria']).' · '.$this->semMarkdown($l['descricao']).' — '.number_format($l['valor'], 2, ',', ' ').' €');
        }
        if (count($r['linhas']) > 15) {
            $mail->line('… e mais '.(count($r['linhas']) - 15).' linhas.');
        }

        return $mail->action('Ver despesa', $r['url'])
            ->line('A aprovação ou rejeição é feita na ficha da despesa, pelo aprovador.');
    }

    // Texto escrito por utilizadores num email markdown: escapar a pontuação para o
    // CommonMark não a interpretar (ver ResumoAlertas).
    private function semMarkdown(string $texto): string
    {
        return addcslashes($texto, '\\`*_{}[]()#+.!|<>~=-');
    }
}
