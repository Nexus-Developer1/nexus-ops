<?php

namespace App\Jobs;

use App\Mail\ResultadoSincronizacaoErp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

// Sincronização de todos os dados do PHC. Corre os 3 syncs ENCADEADOS (cada um arranca
// quando o anterior acaba): clientes primeiro (equipamentos dependem de clientes.id_erp),
// faturação no fim (a pesada, ~20 min). Uma falha numa etapa não impede as seguintes.
//
// Dois modos:
//   - AGENDADO (cron 08h/13h/19h, routes/console.php): envia SEMPRE o email de resultado
//     ao suporte (config erp.email_sync) — sucesso ou falha.
//   - MANUAL (botão "Sincronizar PHC" do dashboard): silencioso — serve só para apressar
//     o sync sem esperar pela próxima hora; o resultado fica no log.
class SincronizarErp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Sem retries: repetir um sync completo do PHC à conta de uma falha transitória só
    // duplicaria carga — a corrida agendada seguinte volta a tentar de qualquer forma.
    public int $tries = 1;

    // Tem de ficar ABAIXO do retry_after da ligação redis (2100s em produção): se o job
    // vivesse para lá disso, a fila re-entregava-o a outro worker a meio do sync.
    public int $timeout = 1700;

    // Nome legível de cada etapa → comando (a ordem importa).
    private const ETAPAS = [
        'Clientes' => 'erp:sincronizar-clientes',
        'Equipamentos' => 'erp:sincronizar-equipamentos',
        'Faturação' => 'erp:sincronizar-faturacao',
    ];

    public function __construct(public bool $agendado = false) {}

    public function handle(): void
    {
        // Nunca dois syncs em simultâneo — o lock é partilhado entre o agendado e o botão,
        // por isso um clique em cima da hora do cron não duplica a carga no PHC (o segundo
        // é simplesmente ignorado). O TTL cobre o pior caso do timeout.
        $lock = Cache::lock('erp-sync', 1800);
        if (! $lock->get()) {
            Log::info('Sync do ERP ignorado: já há um em curso.');

            return;
        }

        try {
            // etapa → ['ok' => bool, 'detalhe' => resumo do comando ou mensagem de erro]
            $resultados = [];
            foreach (self::ETAPAS as $etapa => $comando) {
                try {
                    $codigo = Artisan::call($comando);
                } catch (Throwable $e) {
                    $resultados[$etapa] = ['ok' => false, 'detalhe' => $e->getMessage()];

                    continue;
                }

                $saida = trim((string) Artisan::output());
                if ($codigo === 0) {
                    // O comando termina com "Sincronização concluída: X criados, Y atualizados…".
                    preg_match('/Sincronização concluída: (.+)/u', $saida, $m);
                    $resultados[$etapa] = ['ok' => true, 'detalhe' => trim($m[1] ?? 'concluída.')];
                } else {
                    // O comando já loga o detalhe; para o email basta a última parte útil.
                    $resultados[$etapa] = ['ok' => false, 'detalhe' => $saida !== '' ? mb_substr($saida, -500) : "terminou com código {$codigo}"];
                }
            }

            $falhou = array_filter($resultados, fn ($r) => ! $r['ok']) !== [];

            if ($this->agendado) {
                Mail::to(config('erp.email_sync'))->send(new ResultadoSincronizacaoErp($resultados, $falhou));
            }

            Log::info('Sync do ERP (encadeado) terminado.', [
                'agendado' => $this->agendado,
                'falhas' => array_keys(array_filter($resultados, fn ($r) => ! $r['ok'])),
            ]);
        } finally {
            $lock->release();
        }
    }

    // Crash/timeout do worker (o handle nem chegou ao fim) — no agendado avisa na mesma;
    // no manual mantém-se silencioso (fica no log de jobs falhados).
    public function failed(?Throwable $e): void
    {
        if (! $this->agendado) {
            return;
        }

        Mail::to(config('erp.email_sync'))->send(new ResultadoSincronizacaoErp([
            'Sincronização' => ['ok' => false, 'detalhe' => $e?->getMessage() ?? 'o processo foi interrompido (timeout ou crash do worker)'],
        ], true));
    }
}
