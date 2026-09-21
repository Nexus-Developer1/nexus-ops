<?php

namespace App\Jobs;

use App\Enums\EstadoEvento;
use App\Enums\EstadoRelatorio;
use App\Mail\FalhaEnvioRelatorio;
use App\Mail\RelatorioParaCliente;
use App\Models\EventoAgenda;
use App\Models\Relatorio;
use App\Services\Auditor;
use App\Services\GeradorRelatorio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
        public ?string $cc = null, // quem envia recebe cópia (set. 2026)
    ) {}

    // UMA tentativa (22.ª revisão de segurança): o worker corre com --tries=3, e se o email
    // saísse e a gravação a seguir falhasse, a repetição mandava o relatório ao cliente uma
    // segunda vez, com outra versão congelada. Uma falha vai para failed() e avisa quem enviou.
    public int $tries = 1;

    public int $timeout = 300;

    // Quanto tempo o 2.º «Enviar» do mesmo relatório espera pela vez do 1.º. O envio demora
    // segundos (gerar o PDF, se faltar, e falar com o Graph); 2 minutos é folga larga e fica
    // dentro do $timeout. Esgotado, o job falha e quem enviou é avisado (failed()).
    public const ESPERA_SEGUNDOS = 120;

    // Tempo máximo que o cadeado vive se o worker morrer a meio (nunca fica preso para sempre).
    private const CADEADO_SEGUNDOS = 600;

    // Um relatório de cada vez (22.ª revisão de segurança): dois «Enviar» em simultâneo
    // calculavam a mesma versão da cópia congelada e um sobrescrevia o outro. O 2.º ESPERA
    // AQUI pela vez, dentro do próprio job — a primeira versão devolvia-o à fila
    // (WithoutOverlapping::releaseAfter), mas cada devolução conta como tentativa e, com
    // $tries = 1, o 2.º morria sem enviar e quem carregou recebia um email de «falha»
    // (relatório externo de 21/09). Os dois envios acontecem, com versões distintas.
    public function handle(GeradorRelatorio $gerador): void
    {
        // Defensivo: o destinatário é validado na composição, mas nunca envia em branco.
        if (blank($this->para)) {
            Log::warning('Envio de relatório sem destinatário.', ['relatorio' => $this->relatorio->numero]);

            return;
        }

        Cache::lock('relatorio-envio:'.$this->relatorio->getKey(), self::CADEADO_SEGUNDOS)
            ->block(self::ESPERA_SEGUNDOS, fn () => $this->enviar($gerador));
    }

    private function enviar(GeradorRelatorio $gerador): void
    {
        // O estado é verificado na composição, mas entre o clique e a fila o relatório pode
        // ter sido reaberto (voltou a rascunho) — um rascunho nunca sai para o cliente.
        $this->relatorio->refresh();
        if ($this->relatorio->estado === EstadoRelatorio::Rascunho) {
            Log::warning('Envio de relatório cancelado: voltou a rascunho antes de sair.', ['relatorio' => $this->relatorio->numero]);

            return;
        }

        // Apagado entre o clique e a fila: o refresh() (como a reidratação da fila) ignora o
        // SoftDeletes, por isso o modelo chega aqui na mesma — e só se eliminar os ENVIADOS é
        // que está bloqueado, um finalizado apaga-se. Um relatório que já não existe na
        // aplicação nunca sai para o cliente (relatório externo de 21/09).
        if ($this->relatorio->trashed()) {
            Log::warning('Envio de relatório cancelado: foi eliminado antes de sair.', ['relatorio' => $this->relatorio->numero]);

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

        // «Para» pode trazer vários emails separados por «;» (set. 2026); a cópia vai para
        // quem enviou, se não for já um dos destinatários.
        $destinatarios = array_values(array_filter(array_map('trim', explode(';', $this->para))));
        $mail = Mail::to($destinatarios);
        if ($this->cc && ! in_array(mb_strtolower($this->cc), array_map('mb_strtolower', $destinatarios), true)) {
            $mail->cc($this->cc);
        }
        // O anexo é EXATAMENTE a cópia congelada (o mesmo $conteudo que ficou arquivado com o
        // hash) — antes o email voltava a ler o pdf_path, que entretanto podia ser regenerado.
        $mail->send(new RelatorioParaCliente($this->relatorio, $this->assunto, $this->mensagem, $conteudo));

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

        // Auditoria de envios de relatórios (CLAUDE.md §11). Sem o destinatário no log — já
        // está na auditoria (relatorio_enviado) e não se repete PII nos ficheiros de log.
        Log::info('Relatório enviado ao cliente.', ['relatorio' => $this->relatorio->numero]);
    }

    // Falha definitiva (exceção, timeout ou crash do worker): fica na auditoria e quem enviou
    // recebe um aviso — antes ficava só em failed_jobs e o técnico julgava o relatório enviado.
    public function failed(?Throwable $e): void
    {
        Log::error('Envio de relatório falhou.', ['relatorio' => $this->relatorio->numero, 'erro' => $e?->getMessage()]);

        Auditor::registar('relatorio_envio_falhou', $this->relatorio, [
            'numero' => $this->relatorio->numero,
            'para' => $this->para,
            'erro' => mb_substr((string) $e?->getMessage(), 0, 500),
        ]);

        $avisar = array_values(array_unique(array_filter([$this->cc, config('erp.email_sync')])));
        if ($avisar === []) {
            return;
        }
        try {
            Mail::to($avisar)->send(new FalhaEnvioRelatorio($this->relatorio, $this->para, (string) $e?->getMessage()));
        } catch (Throwable $falhaAviso) {
            Log::error('Não foi possível avisar da falha de envio.', ['erro' => $falhaAviso->getMessage()]);
        }
    }
}
