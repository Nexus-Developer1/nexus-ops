<?php

namespace App\Services\Erp;

// Driver inativo — não devolve clientes. É o default seguro quando o ERP não
// está configurado (ERP_DRIVER vazio): o sync corre sem injetar dados fictícios.
class NullErpDriver implements ErpSyncDriver
{
    public function obterClientes(?int $limite = null): iterable
    {
        return [];
    }

    public function obterLinhasFatura(?int $limite = null): iterable
    {
        return [];
    }

    public function obterEquipamentos(?int $limite = null): iterable
    {
        return [];
    }

    public function obterArtigos(?int $limite = null): iterable
    {
        return [];
    }
}
