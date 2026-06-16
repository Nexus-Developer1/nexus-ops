<?php

namespace App\Services\Erp;

// Contrato de leitura do ERP. Implementado por um driver fake (dev) e,
// futuramente, por um driver SQL Server que lê das views read-only.
interface ErpSyncDriver
{
    /**
     * Devolve os clientes existentes no ERP.
     *
     * @param  int|null  $limite  Nº máximo de clientes a devolver (null = decisão do driver).
     * @return iterable<ClienteErp>
     */
    public function obterClientes(?int $limite = null): iterable;
}
