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

    // O que o email diz quando uma etapa falha — o detalhe técnico (que pode expor query/host
    // do ERP) fica só no log da aplicação.
    private const DETALHE_FALHA = 'falhou — o detalhe técnico está no log da aplicação.';

    // Nome legível de cada etapa → comando (a ordem importa).
    private const ETAPAS = [
        'Clientes' => 'erp:sincronizar-clientes',
        'Equipamentos' => 'erp:sincronizar-equipamentos',
        'Artigos' => 'erp:sincronizar-artigos',
        'Faturação' => 'erp:sincronizar-faturacao',
    ];

    public function __construct(public bool $agendado = false, public bool $completo = false) {}

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
                    // O modo completo (rede de segurança semanal) ignora os hashes do incremental.
                    $codigo = $this->completo ? Artisan::call($comando, ['--completo' => true]) : Artisan::call($comando);
                } catch (Throwable $e) {
                    // Detalhe técnico (query/host do ERP numa QueryException) fica SÓ no log —
                    // email é um canal sem confidencialidade e o destino é configurável.
                    Log::error("Sync do ERP: etapa {$etapa} rebentou.", ['erro' => $e->getMessage()]);
                    $resultados[$etapa] = ['ok' => false, 'detalhe' => self::DETALHE_FALHA];

                    continue;
                }

                $saida = trim((string) Artisan::output());
                if ($codigo === 0) {
                    // O comando termina com "Sincronização concluída: X criados, Y atualizados…".
                    preg_match('/Sincronização concluída: (.+)/u', $saida, $m);
                    $resultados[$etapa] = ['ok' => true, 'detalhe' => trim($m[1] ?? 'concluída.')];
                } else {
                    // O comando já logou o detalhe (Log::error) — o email fica genérico.
                    $resultados[$etapa] = ['ok' => false, 'detalhe' => self::DETALHE_FALHA];
                }
            }

            $falhou = array_filter($resultados, fn ($r) => ! $r['ok']) !== [];

            if ($this->agendado) {
                Mail::to(config('erp.email_sync'))->send(new ResultadoSincronizacaoErp($resultados, $falhou));
            }

            // Último resultado em cache — o dashboard (botão "Sincronizar PHC") faz poll disto
            // para mostrar o que foi criado/atualizado assim que a corrida termina.
            Cache::put('erp-sync:ultimo', [
                'terminado_em' => now()->toIso8601String(),
                'falhou' => $falhou,
                'resultados' => $resultados,
            ], now()->addDay());

            Log::info('Sync do ERP (encadeado) terminado.', [
                'agendado' => $this->agendado,
                'falhas' => array_keys(array_filter($resultados, fn ($r) => ! $r['ok'])),
            ]);
            // Auditoria: uma linha por corrida (quem = null → sistema; detalhe = resumo por etapa).
            \App\Services\Auditor::registar('sync_erp', detalhe: [
                'agendado' => $this->agendado,
                'falhou' => $falhou,
                'resultados' => array_map(fn ($r) => $r['detalhe'], $resultados),
            ]);
        } finally {
            $lock->release();
        }
    }

    // Crash/timeout do worker (o handle nem chegou ao fim) — regista sempre o desfecho em
    // cache (o dashboard mostra-o); o EMAIL só no agendado (o manual é silencioso por email).
    public function failed(?Throwable $e): void
    {
        Log::error('Sync do ERP: job falhou/interrompido.', ['erro' => $e?->getMessage()]);
        $resultados = ['Sincronização' => ['ok' => false, 'detalhe' => 'o processo foi interrompido (timeout ou crash do worker) — detalhe no log.']];
        Cache::put('erp-sync:ultimo', [
            'terminado_em' => now()->toIso8601String(),
            'falhou' => true,
            'resultados' => $resultados,
        ], now()->addDay());
        if (! $this->agendado) {
            return;
        }
        Mail::to(config('erp.email_sync'))->send(new ResultadoSincronizacaoErp($resultados, true));
    }
}
