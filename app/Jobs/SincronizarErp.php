<?php

namespace App\Jobs;

use App\Mail\SincronizacaoErpFalhou;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

// Sincronização de todos os dados do PHC — usada pelo AGENDADO (08h/13h/19h, ver
// routes/console.php) e pelo botão "Sincronizar PHC" do dashboard. Corre os 3 syncs
// ENCADEADOS (cada um arranca quando o anterior acaba): clientes primeiro (equipamentos
// dependem de clientes.id_erp), faturação no fim (a pesada, ~20 min). Uma falha numa
// etapa não impede as seguintes. Se algo falhar, avisa por email (config erp.email_falhas).
class SincronizarErp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Sem retries: repetir um sync completo do PHC à conta de uma falha transitória só
    // duplicaria carga — quem carregou no botão recebe o email de falha e volta a tentar.
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
            $falhas = [];
            foreach (self::ETAPAS as $etapa => $comando) {
                try {
                    $codigo = Artisan::call($comando);
                } catch (Throwable $e) {
                    $falhas[$etapa] = $e->getMessage();

                    continue;
                }

                if ($codigo !== 0) {
                    // O comando já loga o detalhe; para o email basta a última linha útil.
                    $saida = trim((string) Artisan::output());
                    $falhas[$etapa] = $saida !== '' ? mb_substr($saida, -500) : "terminou com código {$codigo}";
                }
            }

            if ($falhas !== []) {
                Mail::to(config('erp.email_falhas'))->send(new SincronizacaoErpFalhou($falhas));
            }

            Log::info('Sync do ERP (encadeado) terminado.', ['falhas' => array_keys($falhas)]);
        } finally {
            $lock->release();
        }
    }

    // Crash/timeout do worker (o handle nem chegou ao fim) — avisa na mesma.
    public function failed(?Throwable $e): void
    {
        Mail::to(config('erp.email_falhas'))->send(new SincronizacaoErpFalhou([
            'Sincronização' => $e?->getMessage() ?? 'o processo foi interrompido (timeout ou crash do worker)',
        ]));
    }
}
