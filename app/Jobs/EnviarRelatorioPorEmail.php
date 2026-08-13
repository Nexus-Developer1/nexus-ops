<?php

namespace App\Jobs;

use App\Enums\EstadoEvento;
use App\Enums\EstadoRelatorio;
use App\Mail\RelatorioParaCliente;
use App\Models\EventoAgenda;
use App\Models\Relatorio;
use App\Services\Auditor;
use App\Services\GeradorRelatorio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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

        // Garante que o PDF existe no object storage antes de anexar — path em branco OU
        // ficheiro em falta no disco (o get() de um caminho fantasma devolvia null e o
        // congelamento rebentava com TypeError).
        $disco = Storage::disk();
        if (blank($this->relatorio->pdf_path) || ! $disco->exists($this->relatorio->pdf_path)) {
            $gerador->gerarPdf($this->relatorio);
            $this->relatorio->refresh();
        }

        // CÓPIA IMUTÁVEL (Vaga 2): o pdf_path é documento de trabalho — pode ser regenerado
        // com o template atual numa reabertura. O que o cliente recebe fica CONGELADO numa
        // cópia própria com hash sha256 (prova do que foi emitido); reenvio = versão nova,
        // as anteriores nunca são tocadas. O portal serve sempre a última cópia congelada.
        $conteudo = (string) $disco->get($this->relatorio->pdf_path);
        $versao = ($this->relatorio->enviado_versao ?? 0) + 1;
        $caminho = 'relatorios/enviados/'.str_replace('/', '-', (string) $this->relatorio->numero)."-v{$versao}.pdf";
        $disco->put($caminho, $conteudo);
        $sha256 = hash('sha256', $conteudo);

        Mail::to($this->para)->send(new RelatorioParaCliente($this->relatorio, $this->assunto, $this->mensagem));

        $this->relatorio->update([
            'estado' => EstadoRelatorio::Enviado,
            'enviado_em' => now(),
            'enviado_para' => $this->para,
            'pdf_enviado_path' => $caminho,
            'pdf_enviado_sha256' => $sha256,
            'enviado_versao' => $versao,
        ]);

        // Auditoria da emissão: o hash prova, mais tarde, que o ficheiro não mudou.
        Auditor::registar('relatorio_pdf_congelado', $this->relatorio, [
            'numero' => $this->relatorio->numero,
            'versao' => $versao,
            'sha256' => $sha256,
        ]);

        // Fecha o evento de agenda associado (regra de ouro §6). A finalização já o fecha
        // (visita executada — ver Relatorios\Novo::persistir); aqui é reforço idempotente
        // para relatórios legados finalizados antes dessa regra. withoutGlobalScopes:
        // transição de sistema, não navegação.
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
