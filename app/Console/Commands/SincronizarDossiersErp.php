<?php

namespace App\Console\Commands;

use App\Models\Dossier;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

// Sincroniza os dossiês a partir do ERP (PHC, tabela bo): faz upsert na BD da aplicação,
// correlacionando por id_erp (bo.bostamp). Só os tipos 1/3/7 (o driver aplica o WHERE ndos).
// Operação read-only do lado do ERP e sempre em background (agendada — ver routes/console.php).
// Nunca apaga dossiês por estarem ausentes do ERP.
class SincronizarDossiersErp extends Command
{
    protected $signature = 'erp:sincronizar-dossiers {--limit= : Nº máximo de dossiês a processar} {--completo : Ignora os hashes e reprocessa tudo}';

    protected $description = 'Sincroniza os dossiês (propostas e encomendas) a partir do ERP (read-only, upsert por id_erp).';

    public function handle(ErpSyncDriver $erp): int
    {
        $driver = config('erp.driver') ?: '(inativo)';
        $limite = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info("A sincronizar dossiês a partir do ERP (driver: {$driver})...");

        $criados = 0;
        $atualizados = 0;
        $iguais = 0;
        $erros = 0;

        try {
            $this->sincronizar($erp, $limite, $criados, $atualizados, $iguais, $erros);
        } catch (Throwable $e) {
            // Falha de LIGAÇÃO/timeout (PHC em baixo) → loga e devolve FAILURE, sem rebentar
            // com exceção não tratada (não parte o scheduler nem os outros syncs).
            $this->error('Sync de dossiês FALHOU: '.$e->getMessage());
            Log::error('Sync de dossiês do ERP falhou.', ['driver' => $driver, 'erro' => $e->getMessage()]);

            return self::FAILURE;
        }

        $resumo = "{$criados} criados, {$atualizados} atualizados, {$iguais} iguais (saltados), {$erros} erros.";
        $this->info("Sincronização concluída: {$resumo}");

        // Auditoria do sync (CLAUDE.md §11).
        Log::info('Sync de dossiês do ERP concluído.', [
            'driver' => $driver,
            'limite' => $limite,
            'criados' => $criados,
            'atualizados' => $atualizados,
            'iguais' => $iguais,
            'erros' => $erros,
        ]);

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }

    // Percorre o ERP e faz upsert INCREMENTAL: o hash dos dados da última corrida vive em
    // dossiers.hash_sync; hash igual → dossiê saltado sem nenhuma query. Erros por linha são
    // contados (não param o sync); uma falha de ligação propaga para o handle() → FAILURE.
    private function sincronizar(ErpSyncDriver $erp, ?int $limite, int &$criados, int &$atualizados, int &$iguais, int &$erros): void
    {
        $forcarTudo = (bool) $this->option('completo');

        // Mapa id_erp → hash da última corrida (UMA query para a tabela toda).
        $hashes = Dossier::whereNotNull('id_erp')->pluck('hash_sync', 'id_erp')
            ->mapWithKeys(fn ($h, $k) => [(string) $k => $h])
            ->all();

        foreach ($erp->obterDossiers($limite) as $dossierErp) {
            try {
                $dados = [
                    'ndos' => $dossierErp->ndos,
                    'nmdos' => $dossierErp->nmdos,
                    'obrano' => $dossierErp->obrano,
                    'data' => $dossierErp->data,
                    'ano' => $dossierErp->ano,
                    'cliente_no' => $dossierErp->clienteNo,
                    'nome' => $dossierErp->nome,
                    'total_debito' => $dossierErp->totalDebito,
                    'fechada' => $dossierErp->fechada,
                    'u_relat' => $dossierErp->uRelat,
                ];
                $hash = md5((string) json_encode($dados, JSON_INVALID_UTF8_SUBSTITUTE));

                // Nada mudou no ERP desde a última corrida → salta (zero queries).
                if (! $forcarTudo && ($hashes[$dossierErp->idErp] ?? null) === $hash) {
                    $iguais++;

                    continue;
                }

                // Upsert por id_erp: existe → atualiza; não existe → cria.
                $dossier = Dossier::updateOrCreate(
                    ['id_erp' => $dossierErp->idErp],
                    $dados + ['hash_sync' => $hash, 'synced_at' => now()],
                );

                $dossier->wasRecentlyCreated ? $criados++ : $atualizados++;
            } catch (Throwable $e) {
                $erros++;
                Log::warning('Falha a sincronizar dossiê do ERP.', [
                    'id_erp' => $dossierErp->idErp ?? null,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }
}
