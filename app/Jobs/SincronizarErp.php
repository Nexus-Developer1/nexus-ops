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

// Sincronização MANUAL de todos os dados do PHC (botão no dashboard). Corre os 3 syncs
// pela mesma ordem do agendamento (equipamentos dependem de clientes.id_erp); uma falha
// num sync não impede os seguintes. Se algum falhar, avisa por email (config erp.email_falhas).
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
        'Faturação' => 'erp:sincronizar-faturacao',
        'Equipamentos' => 'erp:sincronizar-equipamentos',
    ];

    public function handle(): void
    {
        // Nunca dois syncs manuais em simultâneo (o TTL cobre o pior caso do timeout).
        $lock = Cache::lock('erp-sync-manual', 1800);
        if (! $lock->get()) {
            Log::info('Sync manual do ERP ignorado: já há um em curso.');

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

            Log::info('Sync manual do ERP terminado.', ['falhas' => array_keys($falhas)]);
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
