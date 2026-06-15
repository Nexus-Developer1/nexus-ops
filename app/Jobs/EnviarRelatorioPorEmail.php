<?php

namespace App\Jobs;

use App\Enums\EstadoRelatorio;
use App\Mail\RelatorioParaCliente;
use App\Models\Relatorio;
use App\Services\GeradorRelatorio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Envio do relatório ao cliente por email — sempre em job assíncrono (CLAUDE.md §12).
// Garante o PDF, envia, regista enviado_em/enviado_para e marca o estado Enviado (§6).
class EnviarRelatorioPorEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(public Relatorio $relatorio) {}

    public function handle(GeradorRelatorio $gerador): void
    {
        $this->relatorio->load('intervencao.equipamento.local.cliente');

        $cliente = $this->relatorio->intervencao->equipamento->local->cliente;
        $destinatario = $cliente->email;

        // Sem email do cliente não há para onde enviar — aborta sem marcar Enviado.
        if (blank($destinatario)) {
            Log::warning('Relatório sem destinatário: cliente sem email.', [
                'relatorio' => $this->relatorio->numero,
                'cliente_id' => $cliente->id,
            ]);

            return;
        }

        // Garante que o PDF existe no object storage antes de anexar.
        if (blank($this->relatorio->pdf_path)) {
            $gerador->gerarPdf($this->relatorio);
            $this->relatorio->refresh();
        }

        Mail::to($destinatario)->send(new RelatorioParaCliente($this->relatorio));

        $this->relatorio->update([
            'estado' => EstadoRelatorio::Enviado,
            'enviado_em' => now(),
            'enviado_para' => $destinatario,
        ]);

        // Auditoria de envios de relatórios (CLAUDE.md §11).
        Log::info('Relatório enviado ao cliente.', [
            'relatorio' => $this->relatorio->numero,
            'para' => $destinatario,
        ]);
    }
}
