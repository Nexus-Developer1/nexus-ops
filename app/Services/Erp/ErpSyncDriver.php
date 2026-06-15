<?php

namespace App\Services\Erp;

// Contrato de leitura do ERP. Implementado por um driver fake (dev) e,
// futuramente, por um driver SQL Server que lê das views read-only.
interface ErpSyncDriver
{
    /**
     * Devolve os clientes existentes no ERP.
     *
     * @return iterable<ClienteErp>
     */
    public function obterClientes(): iterable;
}
