<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Console\Command;

// Sincroniza os clientes a partir do ERP: faz upsert na BD da aplicação,
// correlacionando por id_erp. Operação read-only do lado do ERP.
class SincronizarClientesErp extends Command
{
    protected $signature = 'erp:sincronizar-clientes';

    protected $description = 'Sincroniza os clientes a partir do ERP (read-only, upsert por id_erp).';

    public function handle(ErpSyncDriver $erp): int
    {
        $this->info('A sincronizar clientes a partir do ERP (driver: ' . config('erp.driver') . ')...');

        $total = 0;
        $novos = 0;

        foreach ($erp->obterClientes() as $clienteErp) {
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

            $total++;
            if ($cliente->wasRecentlyCreated) {
                $novos++;
            }
        }

        $this->info("Sincronização concluída: {$total} clientes processados ({$novos} novos).");

        return self::SUCCESS;
    }
}
