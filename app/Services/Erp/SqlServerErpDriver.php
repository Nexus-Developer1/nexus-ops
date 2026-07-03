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
        // Lê os clientes da tabela cl do PHC pela ligação 'erp' (dblib/FreeTDS), a MESMA que a
        // faturação já usa com sucesso. Correlação por cl.no → id_erp. Mapeamento PHC → aplicação:
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
        // Opção A (sem view): lê direto de cl porque ainda não há acesso de ESCRITA ao PHC para
        // criar a view. §5 do CLAUDE.md: quando houver, envolver numa VIEW read-only dedicada
        // (vw_clientes) e ler dessa view, nunca da tabela bruta. (Igual à faturação — ver abaixo.)
        //
        // SQL Server: o limite usa TOP (não LIMIT). É um inteiro, interpolado em segurança.
        $top = $limite !== null ? 'TOP ' . (int) $limite . ' ' : '';

        $sql = "SELECT {$top}no, nome, ncont, morada, codpost, email, telefone, tlmvl, vendedor, vendnm
                FROM cl";

        foreach (DB::connection('erp')->select($sql) as $r) {
            yield new ClienteErp(
                idErp: (string) $r->no,
                nome: $r->nome,
                nif: $r->ncont,
                email: $r->email,
                telefone: $r->telefone,
                morada: $r->morada,
                codpost: $r->codpost,
                tlmvl: $r->tlmvl,
                vendedor: $r->vendedor !== null ? (int) $r->vendedor : null,
                vendnm: $r->vendnm,
            );
        }
    }

    public function obterLinhasFatura(?int $limite = null): iterable
    {
        // Query VALIDADA: linhas de fatura (fi) com a data vinda de ft por ftstamp, só linhas
        // com nº de série preenchido (equipamentos físicos). Correlação por fi.fistamp → id_erp.
        // Lida crua (DB::select) porque a subconsulta da data não se exprime bem no query builder.
        //
        // Opção A (sem view): lê direto de fi/ft porque ainda não há acesso de ESCRITA ao PHC
        // para criar a view. §5 do CLAUDE.md: quando houver, envolver esta query numa VIEW
        // read-only dedicada (vw_linhas_fatura) e ler dessa view, nunca das tabelas brutas.
        //
        // SQL Server: o limite usa TOP (não LIMIT). É um inteiro, interpolado em segurança.
        $top = $limite !== null ? 'TOP ' . (int) $limite . ' ' : '';

        $sql = "SELECT {$top}fistamp, nmdoc, fno,
                       (SELECT fdata FROM ft WHERE ftstamp = fi.ftstamp) AS data,
                       (SELECT no FROM ft WHERE ftstamp = fi.ftstamp) AS cliente_no,
                       ref, design, series, qtt
                FROM fi
                WHERE series NOT LIKE ''";

        foreach (DB::connection('erp')->select($sql) as $r) {
            yield new LinhaFaturaErp(
                idErp: (string) $r->fistamp,
                clienteNo: $r->cliente_no !== null ? (string) $r->cliente_no : null,
                nmdoc: $r->nmdoc,
                fno: $r->fno !== null ? (int) $r->fno : null,
                data: $r->data ? \Illuminate\Support\Carbon::parse($r->data)->format('Y-m-d') : null,
                ref: $r->ref,
                design: $r->design,
                series: $r->series,
                qtt: $r->qtt !== null ? (float) $r->qtt : null,
            );
        }
    }
}
