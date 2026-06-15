<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

// Driver real — lê os clientes das VIEWS dedicadas read-only do ERP.
// NUNCA acede às tabelas brutas nem escreve no ERP (ver secção 5/11 do CLAUDE.md).
//
// Stub: a ligação 'erp' (sqlsrv) e a view só são configuradas quando houver
// acesso ao ERP. Requer a extensão pdo_sqlsrv no container.
class SqlServerErpDriver implements ErpSyncDriver
{
    public function obterClientes(): iterable
    {
        // Exemplo da forma final (a ligação 'erp' fica definida em config/database.php):
        //
        // return DB::connection('erp')
        //     ->table('vw_clientes')              // view dedicada, nunca a tabela bruta
        //     ->select('id_erp', 'nome', 'nif', 'email', 'telefone', 'morada')
        //     ->cursor()
        //     ->map(fn ($r) => new ClienteErp(
        //         idErp: (string) $r->id_erp,
        //         nome: $r->nome,
        //         nif: $r->nif,
        //         email: $r->email,
        //         telefone: $r->telefone,
        //         morada: $r->morada,
        //     ));

        throw new \RuntimeException(
            'Driver SQL Server do ERP ainda não implementado. Definir ERP_DRIVER=fake ou configurar a ligação read-only.'
        );
    }
}
