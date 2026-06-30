<?php

namespace App\Console\Commands;

use App\Models\LinhaFatura;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

// Sincroniza as linhas de faturação a partir do ERP (PHC, tabela fi): faz upsert na BD
// da aplicação, correlacionando por id_erp (fi.fistamp). Só linhas com nº de série
// (equipamentos físicos — o driver aplica o WHERE series). Operação read-only do lado
// do ERP e sempre em background (agendada — ver routes/console.php). Nunca apaga linhas
// por estarem ausentes do ERP.
class SincronizarFaturacaoErp extends Command
{
    protected $signature = 'erp:sincronizar-faturacao {--limit= : Nº máximo de linhas a processar}';

    protected $description = 'Sincroniza as linhas de faturação a partir do ERP (read-only, upsert por id_erp).';

    public function handle(ErpSyncDriver $erp): int
    {
        $driver = config('erp.driver') ?: '(inativo)';
        $limite = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info("A sincronizar faturação a partir do ERP (driver: {$driver})...");

        $criados = 0;
        $atualizados = 0;
        $erros = 0;

        foreach ($erp->obterLinhasFatura($limite) as $linhaErp) {
            try {
                // Upsert por id_erp: existe → atualiza; não existe → cria.
                $linha = LinhaFatura::updateOrCreate(
                    ['id_erp' => $linhaErp->idErp],
                    [
                        'nmdoc' => $linhaErp->nmdoc,
                        'fno' => $linhaErp->fno,
                        'data' => $linhaErp->data,
                        'ref' => $linhaErp->ref,
                        'design' => $linhaErp->design,
                        'series' => $linhaErp->series,
                        'qtt' => $linhaErp->qtt,
                        'synced_at' => now(),
                    ],
                );

                $linha->wasRecentlyCreated ? $criados++ : $atualizados++;
            } catch (Throwable $e) {
                $erros++;
                Log::warning('Falha a sincronizar linha de faturação do ERP.', [
                    'id_erp' => $linhaErp->idErp ?? null,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        $resumo = "{$criados} criadas, {$atualizados} atualizadas, {$erros} erros.";
        $this->info("Sincronização concluída: {$resumo}");

        // Auditoria do sync (CLAUDE.md §11).
        Log::info('Sync de faturação do ERP concluído.', [
            'driver' => $driver,
            'limite' => $limite,
            'criados' => $criados,
            'atualizados' => $atualizados,
            'erros' => $erros,
        ]);

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }
}
