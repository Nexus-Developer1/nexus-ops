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
    protected $signature = 'erp:sincronizar-clientes {--limit= : Nº máximo de clientes a processar} {--completo : Ignora os hashes e reprocessa tudo}';

    protected $description = 'Sincroniza os clientes a partir do ERP (read-only, upsert por id_erp).';

    public function handle(ErpSyncDriver $erp): int
    {
        $driver = config('erp.driver') ?: '(inativo)';
        $limite = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info("A sincronizar clientes a partir do ERP (driver: {$driver})...");

        $criados = 0;
        $atualizados = 0;
        $iguais = 0;
        $erros = 0;

        try {
            $this->sincronizar($erp, $limite, $criados, $atualizados, $iguais, $erros);
        } catch (Throwable $e) {
            // Falha de LIGAÇÃO/timeout (PHC em baixo) → loga e devolve FAILURE, sem rebentar
            // com exceção não tratada (não parte o scheduler nem os outros syncs).
            $this->error('Sync de clientes FALHOU: '.$e->getMessage());
            Log::error('Sync de clientes do ERP falhou.', ['driver' => $driver, 'erro' => $e->getMessage()]);

            return self::FAILURE;
        }

        $resumo = "{$criados} criados, {$atualizados} atualizados, {$iguais} iguais (saltados), {$erros} erros.";
        $this->info("Sincronização concluída: {$resumo}");

        // Auditoria do sync (CLAUDE.md §11).
        Log::info('Sync de clientes do ERP concluído.', [
            'driver' => $driver,
            'limite' => $limite,
            'criados' => $criados,
            'atualizados' => $atualizados,
            'iguais' => $iguais,
            'erros' => $erros,
        ]);

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }

    // Percorre o ERP e faz upsert INCREMENTAL: hash igual ao da última corrida → cliente
    // saltado sem nenhuma query (o mapa id_erp→hash carrega numa só leitura). Erros por linha
    // são contados (não param o sync); uma falha de ligação propaga para o handle() → FAILURE.
    private function sincronizar(ErpSyncDriver $erp, ?int $limite, int &$criados, int &$atualizados, int &$iguais, int &$erros): void
    {
        $forcarTudo = (bool) $this->option('completo');

        $hashes = Cliente::whereNotNull('id_erp')->pluck('hash_sync', 'id_erp')
            ->mapWithKeys(fn ($h, $k) => [(string) $k => $h])
            ->all();

        foreach ($erp->obterClientes($limite) as $clienteErp) {
            try {
                $dados = [
                    'nome' => $clienteErp->nome,
                    'nif' => $clienteErp->nif,
                    'email' => $clienteErp->email,
                    'telefone' => $clienteErp->telefone,
                    'tlmvl' => $clienteErp->tlmvl,
                    'morada' => $clienteErp->morada,
                    'codpost' => $clienteErp->codpost,
                    'vendedor' => $clienteErp->vendedor,
                    'vendnm' => $clienteErp->vendnm,
                ];
                $hash = md5((string) json_encode($dados, JSON_INVALID_UTF8_SUBSTITUTE));

                // Nada mudou no ERP desde a última corrida → salta (zero queries).
                if (! $forcarTudo && ($hashes[$clienteErp->idErp] ?? null) === $hash) {
                    $iguais++;

                    continue;
                }

                // Upsert por id_erp: existe → atualiza; não existe → cria.
                $cliente = Cliente::updateOrCreate(
                    ['id_erp' => $clienteErp->idErp],
                    $dados + ['hash_sync' => $hash],
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
