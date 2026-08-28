<?php

namespace App\Jobs;

use App\Mail\ResultadoSincronizacaoErp;
use App\Services\Auditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Fecho da CADEIA da corrida completa (ver CadeiaSincronizacaoCompletaErp): junta os resultados
// que cada etapa acumulou em cache, regista UMA linha de auditoria (como o job encadeado),
// atualiza o "último resultado" (dashboard e /api/sync/estado) e envia o email de resultado —
// só no agendado, como sempre (a API é silenciosa por email). Etapas que não chegaram a correr
// (cadeia interrompida por timeout/crash) entram como falhadas, para o email não mentir por
// omissão.
class ResumoSincronizacaoCompletaErp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $origem) {}

    public function handle(): void
    {
        $acumulado = Cache::pull(CadeiaSincronizacaoCompletaErp::CACHE_ACUMULADOR) ?? [];
        $resultados = $acumulado['resultados'] ?? [];

        foreach (SincronizarEtapaErp::ETAPAS as [$nome]) {
            $resultados[$nome] ??= ['ok' => false, 'detalhe' => 'não chegou a correr — a cadeia foi interrompida antes (detalhe no log).'];
        }
        // Na ordem das etapas, não na ordem de chegada.
        $resultados = array_merge(array_fill_keys(array_column(SincronizarEtapaErp::ETAPAS, 0), null), $resultados);

        $falhou = array_filter($resultados, fn ($r) => ! $r['ok']) !== [];

        SincronizarErp::registarUltimo($resultados, $falhou, $this->origem);
        SincronizarErp::desmarcarEmCurso();

        Log::info('Sync do ERP (completo, em cadeia) terminado.', [
            'origem' => $this->origem,
            'falhas' => array_keys(array_filter($resultados, fn ($r) => ! $r['ok'])),
        ]);
        Auditor::registar('sync_erp', detalhe: [
            'origem' => $this->origem,
            'completo' => true,
            'agendado' => str_starts_with($this->origem, 'agendado'),
            'falhou' => $falhou,
            'resultados' => array_map(fn ($r) => $r['detalhe'], $resultados),
        ]);

        if (str_starts_with($this->origem, 'agendado')) {
            Mail::to(config('erp.email_sync'))->send(new ResultadoSincronizacaoErp($resultados, $falhou));
        }
    }
}
