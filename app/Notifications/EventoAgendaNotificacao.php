<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

// Email ao técnico associado a um evento da agenda: criado, alterado ou removido. Vai pela
// FILA (o envio por Graph não entra no caminho do clique — CLAUDE.md §12). O evento vai como
// INSTANTÂNEO (array), não como modelo: no "removido" o registo já não existe quando o
// worker pega no job, e no "alterado" precisa-se do ANTES tal como estava.
class EventoAgendaNotificacao extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'criado'|'alterado'|'removido'  $tipo
     * @param  array<string, mixed>  $evento  instantâneo atual (NotificadorAgenda::instantaneo)
     * @param  array<string, mixed>|null  $antes  instantâneo anterior (só no "alterado")
     */
    public function __construct(
        public string $tipo,
        public array $evento,
        public ?array $antes,
        public string $autor,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $quando = $this->quando($this->evento);

        $assunto = match ($this->tipo) {
            'criado' => "Novo evento: {$this->evento['titulo']} — {$quando}",
            'alterado' => "Evento alterado: {$this->evento['titulo']} — {$quando}",
            default => "Evento removido: {$this->evento['titulo']} — {$quando}",
        };

        return (new MailMessage)
            ->subject($assunto)
            ->view('emails.evento-agenda', [
                'nome' => $notifiable->nome,
                'tipo' => $this->tipo,
                'evento' => $this->evento,
                'antes' => $this->antes,
                'autor' => $this->autor,
                'alteracoes' => $this->antes ? $this->alteracoes($this->antes, $this->evento) : [],
                'url' => route('agenda'),
            ]);
    }

    /** "05/09/2026 08:00–09:00" ou "05/09/2026 08:00 → 06/09/2026 17:00". */
    public static function quando(array $e): string
    {
        $ini = Carbon::parse($e['inicio']);
        $fim = Carbon::parse($e['fim']);

        return $ini->isSameDay($fim)
            ? $ini->format('d/m/Y H:i').'–'.$fim->format('H:i')
            : $ini->format('d/m/Y H:i').' → '.$fim->format('d/m/Y H:i');
    }

    /**
     * Linhas "campo: antes → depois" só para o que mudou.
     *
     * @return list<array{campo: string, antes: string, depois: string}>
     */
    private function alteracoes(array $antes, array $depois): array
    {
        $linhas = [];
        $campos = [
            'titulo' => ['Tipo de evento', fn ($e) => (string) $e['titulo']],
            'quando' => ['Quando', fn ($e) => self::quando($e)],
            'tecnicos' => ['Técnicos', fn ($e) => $e['tecnicos_nomes'] ?: '—'],
            'cliente' => ['Cliente', fn ($e) => $e['cliente'] ?: '—'],
            'equipamento' => ['Equipamento', fn ($e) => $e['equipamento'] ?: '—'],
            'contrato' => ['Contrato', fn ($e) => $e['contrato'] ?: '—'],
        ];
        foreach ($campos as [$rotulo, $ler]) {
            $a = $ler($antes);
            $d = $ler($depois);
            if ($a !== $d) {
                $linhas[] = ['campo' => $rotulo, 'antes' => $a, 'depois' => $d];
            }
        }

        return $linhas;
    }
}
