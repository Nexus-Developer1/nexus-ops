<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

// Sincroniza os clientes a partir do ERP: faz upsert na BD da aplicação,
// correlacionando por id_erp (PHC cl.no). Operação read-only do lado do ERP e
// sempre em background (agendada — ver routes/console.php). Nunca apaga clientes
// por estarem ausentes do ERP.
class SincronizarClientesErp extends Command
{
    protected $signature = 'erp:sincronizar-clientes {--limit= : Nº máximo de clientes a processar}';

    protected $description = 'Sincroniza os clientes a partir do ERP (read-only, upsert por id_erp).';

    public function handle(ErpSyncDriver $erp): int
    {
        $driver = config('erp.driver') ?: '(inativo)';
        $limite = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info("A sincronizar clientes a partir do ERP (driver: {$driver})...");

        $criados = 0;
        $atualizados = 0;
        $erros = 0;

        try {
            $this->sincronizar($erp, $limite, $criados, $atualizados, $erros);
        } catch (Throwable $e) {
            // Falha de LIGAÇÃO/timeout (PHC em baixo) → loga e devolve FAILURE, sem rebentar
            // com exceção não tratada (não parte o scheduler nem os outros syncs).
            $this->error('Sync de clientes FALHOU: '.$e->getMessage());
            Log::error('Sync de clientes do ERP falhou.', ['driver' => $driver, 'erro' => $e->getMessage()]);

            return self::FAILURE;
        }

        $resumo = "{$criados} criados, {$atualizados} atualizados, {$erros} erros.";
        $this->info("Sincronização concluída: {$resumo}");

        // Auditoria do sync (CLAUDE.md §11).
        Log::info('Sync de clientes do ERP concluído.', [
            'driver' => $driver,
            'limite' => $limite,
            'criados' => $criados,
            'atualizados' => $atualizados,
            'erros' => $erros,
        ]);

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }

    // Percorre o ERP e faz upsert. Erros por linha são contados (não param o sync); uma falha
    // de ligação propaga para o handle(), que a trata como FAILURE.
    private function sincronizar(ErpSyncDriver $erp, ?int $limite, int &$criados, int &$atualizados, int &$erros): void
    {
        foreach ($erp->obterClientes($limite) as $clienteErp) {
            try {
                // Upsert por id_erp: existe → atualiza; não existe → cria.
                $cliente = Cliente::updateOrCreate(
                    ['id_erp' => $clienteErp->idErp],
                    [
                        'nome' => $clienteErp->nome,
                        'nif' => $clienteErp->nif,
                        'email' => $clienteErp->email,
                        'telefone' => $clienteErp->telefone,
                        'tlmvl' => $clienteErp->tlmvl,
                        'morada' => $clienteErp->morada,
                        'codpost' => $clienteErp->codpost,
                        'vendedor' => $clienteErp->vendedor,
                        'vendnm' => $clienteErp->vendnm,
                    ],
                );

                $cliente->wasRecentlyCreated ? $criados++ : $atualizados++;
            } catch (Throwable $e) {
                $erros++;
                Log::warning('Falha a sincronizar cliente do ERP.', [
                    'id_erp' => $clienteErp->idErp ?? null,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }
}
