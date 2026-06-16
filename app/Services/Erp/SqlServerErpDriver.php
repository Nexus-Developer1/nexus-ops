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
    public function obterClientes(?int $limite = null): iterable
    {
        // Forma final quando houver acesso ao PHC. A ligação 'erp' (sqlsrv, read-only)
        // fica definida em config/database.php e lê de uma VIEW dedicada, nunca da
        // tabela bruta. Mapeamento PHC (tabela cl) → campos da aplicação:
        //
        //   cl.no       → id_erp      (nº de cliente; chave de correlação do upsert)
        //   cl.nome     → nome
        //   cl.ncont    → nif
        //   cl.morada   → morada
        //   cl.codpost  → codpost
        //   cl.email    → email
        //   cl.telefone → telefone
        //   cl.tlmvl    → tlmvl
        //   cl.vendedor → vendedor
        //   cl.vendnm   → vendnm
        //
        // return DB::connection('erp')
        //     ->table('vw_clientes')             // view dedicada, nunca a tabela bruta
        //     ->select('no', 'nome', 'ncont', 'morada', 'codpost', 'email', 'telefone', 'tlmvl', 'vendedor', 'vendnm')
        //     ->when($limite, fn ($q) => $q->limit($limite))
        //     ->cursor()
        //     ->map(fn ($r) => new ClienteErp(
        //         idErp: (string) $r->no,
        //         nome: $r->nome,
        //         nif: $r->ncont,
        //         email: $r->email,
        //         telefone: $r->telefone,
        //         morada: $r->morada,
        //         codpost: $r->codpost,
        //         tlmvl: $r->tlmvl,
        //         vendedor: $r->vendedor !== null ? (int) $r->vendedor : null,
        //         vendnm: $r->vendnm,
        //     ));

        throw new \RuntimeException(
            'Driver SQL Server do ERP ainda não implementado. Definir ERP_DRIVER=fake ou configurar a ligação read-only.'
        );
    }
}
