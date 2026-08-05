<?php

namespace App\Console\Commands;

use App\Models\Artigo;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

// Sincroniza o catálogo de artigos a partir do ERP (PHC st): upsert na BD da aplicação,
// correlacionando por id_erp (st.ref). Operação read-only do lado do ERP e sempre em
// background (encadeada no job SincronizarErp). Nunca apaga artigos por estarem ausentes
// do ERP. Alimenta a pesquisa por referência ao compor componentes de um sistema.
class SincronizarArtigosErp extends Command
{
    protected $signature = 'erp:sincronizar-artigos {--limit= : Nº máximo de artigos a processar} {--completo : Ignora os hashes e reprocessa tudo}';

    protected $description = 'Sincroniza o catálogo de artigos a partir do ERP (read-only, upsert por id_erp).';

    public function handle(ErpSyncDriver $erp): int
    {
        $driver = config('erp.driver') ?: '(inativo)';
        $limite = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info("A sincronizar artigos a partir do ERP (driver: {$driver})...");

        $criados = 0;
        $atualizados = 0;
        $iguais = 0;
        $erros = 0;

        try {
            $this->sincronizar($erp, $limite, $criados, $atualizados, $iguais, $erros);
        } catch (Throwable $e) {
            // Falha de LIGAÇÃO/timeout (PHC em baixo) → loga e devolve FAILURE, sem rebentar
            // com exceção não tratada (não parte o scheduler nem os outros syncs).
            $this->error('Sync de artigos FALHOU: '.$e->getMessage());
            Log::error('Sync de artigos do ERP falhou.', ['driver' => $driver, 'erro' => $e->getMessage()]);

            return self::FAILURE;
        }

        $resumo = "{$criados} criados, {$atualizados} atualizados, {$iguais} iguais (saltados), {$erros} erros.";
        $this->info("Sincronização concluída: {$resumo}");

        // Auditoria do sync (CLAUDE.md §11).
        Log::info('Sync de artigos do ERP concluído.', [
            'driver' => $driver,
            'limite' => $limite,
            'criados' => $criados,
            'atualizados' => $atualizados,
            'iguais' => $iguais,
            'erros' => $erros,
        ]);

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }

    // Percorre o ERP e faz upsert INCREMENTAL: hash igual ao da última corrida → artigo
    // saltado sem nenhuma query (o mapa id_erp→hash carrega numa só leitura). Erros por linha
    // são contados (não param o sync); uma falha de ligação propaga para o handle() → FAILURE.
    private function sincronizar(ErpSyncDriver $erp, ?int $limite, int &$criados, int &$atualizados, int &$iguais, int &$erros): void
    {
        $forcarTudo = (bool) $this->option('completo');

        $hashes = Artigo::pluck('hash_sync', 'id_erp')
            ->mapWithKeys(fn ($h, $k) => [(string) $k => $h])
            ->all();

        foreach ($erp->obterArtigos($limite) as $artigoErp) {
            try {
                $dados = [
                    'designacao' => $artigoErp->designacao,
                    'familia' => $artigoErp->familia,
                    'faminome' => $artigoErp->faminome,
                ];
                $hash = md5((string) json_encode($dados, JSON_INVALID_UTF8_SUBSTITUTE));

                // Nada mudou no ERP desde a última corrida → salta (zero queries).
                if (! $forcarTudo && ($hashes[$artigoErp->idErp] ?? null) === $hash) {
                    $iguais++;

                    continue;
                }

                // Upsert por id_erp: existe → atualiza; não existe → cria.
                $artigo = Artigo::updateOrCreate(
                    ['id_erp' => $artigoErp->idErp],
                    $dados + ['hash_sync' => $hash],
                );

                $artigo->wasRecentlyCreated ? $criados++ : $atualizados++;
            } catch (Throwable $e) {
                $erros++;
                Log::warning('Falha a sincronizar artigo do ERP.', [
                    'id_erp' => $artigoErp->idErp ?? null,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }
}
