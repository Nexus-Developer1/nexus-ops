<?php

namespace App\Jobs;

use App\Enums\EstadoEvento;
use App\Enums\EstadoRelatorio;
use App\Mail\RelatorioParaCliente;
use App\Models\EventoAgenda;
use App\Models\Relatorio;
use App\Services\GeradorRelatorio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Envio do relatório ao cliente por email — sempre em job assíncrono (CLAUDE.md §12).
// Destinatário, assunto e mensagem são escritos à mão na página de composição
// (Relatorios\Enviar); aqui garante-se o PDF, envia-se, e marca-se Enviado (§6).
class EnviarRelatorioPorEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Relatorio $relatorio,
        public string $para,
        public string $assunto,
        public string $mensagem,
    ) {}

    public function handle(GeradorRelatorio $gerador): void
    {
        // Defensivo: o destinatário é validado na composição, mas nunca envia em branco.
        if (blank($this->para)) {
            Log::warning('Envio de relatório sem destinatário.', ['relatorio' => $this->relatorio->numero]);

            return;
        }

        // Garante que o PDF existe no object storage antes de anexar.
        if (blank($this->relatorio->pdf_path)) {
            $gerador->gerarPdf($this->relatorio);
            $this->relatorio->refresh();
        }

        Mail::to($this->para)->send(new RelatorioParaCliente($this->relatorio, $this->assunto, $this->mensagem));

        $this->relatorio->update([
            'estado' => EstadoRelatorio::Enviado,
            'enviado_em' => now(),
            'enviado_para' => $this->para,
        ]);

        // Fecha o evento de agenda associado (regra de ouro §6): o evento fecha quando o relatório
        // é ENVIADO, não ao finalizar — um relatório finalizado ainda é editável, só o envio o
        // torna definitivo. Sem evento associado → nada a fazer. withoutGlobalScopes: transição de
        // sistema, não navegação.
        $eventoId = $this->relatorio->intervencao?->evento_agenda_id;
        if ($eventoId) {
            EventoAgenda::withoutGlobalScopes()
                ->whereKey($eventoId)
                ->update(['estado' => EstadoEvento::Concluido->value]);
        }

        // Auditoria de envios de relatórios (CLAUDE.md §11).
        Log::info('Relatório enviado ao cliente.', [
            'relatorio' => $this->relatorio->numero,
            'para' => $this->para,
        ]);
    }
}
