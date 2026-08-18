<?php

namespace App\Jobs;

use App\Services\Auditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// UMA etapa do sync do PHC (clientes, equipamentos, artigos, dossiês ou faturação), pedida
// pela API de sincronização (/api/sync/{etapa}). O irmão pequeno do SincronizarErp: partilha
// o MESMO lock (nunca corre em cima da corrida encadeada, nem o contrário), o mesmo marcador
// "em curso" e a mesma cache de último resultado que o dashboard e o /api/sync/estado leem.
// Silencioso por email (como o botão): o resultado fica na cache, no log e na auditoria.
class SincronizarEtapaErp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Etapas disponíveis (chave = segmento do URL) → [nome legível, comando].
    public const ETAPAS = [
        'clientes' => ['Clientes', 'erp:sincronizar-clientes'],
        'equipamentos' => ['Equipamentos', 'erp:sincronizar-equipamentos'],
        'artigos' => ['Artigos', 'erp:sincronizar-artigos'],
        'dossiers' => ['Dossiês', 'erp:sincronizar-dossiers'],
        'faturacao' => ['Faturação', 'erp:sincronizar-faturacao'],
    ];

    public int $tries = 1;

    public int $timeout = 1700;

    public function __construct(public string $etapa, public bool $completo = false, public string $origem = 'api') {}

    public function handle(): void
    {
        [$nome, $comando] = self::ETAPAS[$this->etapa];

        $lock = Cache::lock('erp-sync', 1800);
        if (! $lock->get()) {
            Log::info("Sync do ERP ({$nome}) ignorado: já há um em curso.");

            return;
        }

        SincronizarErp::marcarEmCurso($this->origem, [$nome]);

        try {
            try {
                $codigo = $this->completo ? Artisan::call($comando, ['--completo' => true]) : Artisan::call($comando);
                $saida = trim((string) Artisan::output());
                preg_match('/Sincronização concluída: (.+)/u', $saida, $m);
                $resultado = $codigo === 0
                    ? ['ok' => true, 'detalhe' => trim($m[1] ?? 'concluída.')]
                    : ['ok' => false, 'detalhe' => SincronizarErp::DETALHE_FALHA];
            } catch (Throwable $e) {
                Log::error("Sync do ERP: etapa {$nome} rebentou.", ['erro' => $e->getMessage()]);
                $resultado = ['ok' => false, 'detalhe' => SincronizarErp::DETALHE_FALHA];
            }

            SincronizarErp::registarUltimo([$nome => $resultado], ! $resultado['ok'], $this->origem);
            Auditor::registar('sync_erp', detalhe: [
                'origem' => $this->origem,
                'etapa' => $nome,
                'completo' => $this->completo,
                'falhou' => ! $resultado['ok'],
                'resultados' => [$nome => $resultado['detalhe']],
            ]);
        } finally {
            SincronizarErp::desmarcarEmCurso();
            $lock->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        [$nome] = self::ETAPAS[$this->etapa];
        Log::error("Sync do ERP ({$nome}): job falhou/interrompido.", ['erro' => $e?->getMessage()]);
        SincronizarErp::registarUltimo([$nome => ['ok' => false, 'detalhe' => 'o processo foi interrompido (timeout ou crash do worker) — detalhe no log.']], true, $this->origem);
        SincronizarErp::desmarcarEmCurso();
    }
}
