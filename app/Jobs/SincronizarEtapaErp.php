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

// UMA etapa do sync do PHC (clientes, equipamentos, artigos, dossiês ou faturação). Dois usos:
//  - SOLTA, pedida pela API (/api/sync/{etapa}): regista o seu resultado, audita e acaba.
//  - EM CADEIA, como elo da corrida completa (CadeiaSincronizacaoCompletaErp): acumula o
//    resultado em cache e deixa a auditoria/email para o ResumoSincronizacaoCompletaErp no fim.
// O irmão pequeno do SincronizarErp: partilha o MESMO lock (nunca corre em cima da corrida
// encadeada, nem o contrário), o mesmo marcador "em curso" e a mesma cache de último resultado.
// Silencioso por email (como o botão).
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

    // Abaixo do retry_after do redis (2100 s em produção). Em modo completo a etapa mais lenta
    // (dossiês, ~21 min) cabe folgada — era o job único com as 5 que não cabia.
    public int $timeout = 1700;

    public function __construct(
        public string $etapa,
        public bool $completo = false,
        public string $origem = 'api',
        public bool $emCadeia = false,
    ) {}

    public function handle(): void
    {
        [$nome, $comando] = self::ETAPAS[$this->etapa];

        $lock = Cache::lock('erp-sync', 1800);
        if (! $lock->get()) {
            Log::info("Sync do ERP ({$nome}) ignorado: já há um em curso.");
            $this->acumular($nome, ['ok' => false, 'detalhe' => 'ignorada — já havia uma sincronização em curso.']);

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

            if ($this->emCadeia) {
                // Em cadeia, o fecho (último resultado, auditoria, email) é do job de resumo.
                $this->acumular($nome, $resultado);

                return;
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

    // Timeout/crash do worker (o handle nem chegou ao fim). Em cadeia, o Laravel PÁRA a cadeia
    // aqui — por isso é este failed() que dispara o resumo, para o email e a auditoria saírem
    // na mesma, com esta etapa como "interrompida" e as seguintes como "não chegou a correr".
    public function failed(?Throwable $e): void
    {
        [$nome] = self::ETAPAS[$this->etapa];
        Log::error("Sync do ERP ({$nome}): job falhou/interrompido.", ['erro' => $e?->getMessage()]);
        $resultado = ['ok' => false, 'detalhe' => 'o processo foi interrompido (timeout ou crash do worker) — detalhe no log.'];
        SincronizarErp::desmarcarEmCurso();

        if ($this->emCadeia) {
            $this->acumular($nome, $resultado);
            ResumoSincronizacaoCompletaErp::dispatch($this->origem);

            return;
        }

        SincronizarErp::registarUltimo([$nome => $resultado], true, $this->origem);
    }

    /** @param array{ok: bool, detalhe: string} $resultado */
    private function acumular(string $nome, array $resultado): void
    {
        if (! $this->emCadeia) {
            return;
        }

        $acumulado = Cache::get(CadeiaSincronizacaoCompletaErp::CACHE_ACUMULADOR, ['origem' => $this->origem, 'resultados' => []]);
        $acumulado['resultados'][$nome] = $resultado;
        Cache::put(CadeiaSincronizacaoCompletaErp::CACHE_ACUMULADOR, $acumulado, now()->addHours(3));
    }
}
