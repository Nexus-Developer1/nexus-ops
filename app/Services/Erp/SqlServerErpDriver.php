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
        //
        // estab = 0: no PHC o mesmo nº de cliente (no) pode ter VÁRIOS estabelecimentos
        // (ex.: 9971 = Universidade do Porto com 11 faculdades). A app correlaciona por `no`,
        // por isso sem este filtro os estabelecimentos escreviam por cima uns dos outros a
        // cada sync (o cliente ficava com o nome/morada do que calhasse por último — e o sync
        // incremental via 179 "alterados" eternos). A SEDE (estab 0) é o registo canónico.
        $top = $limite !== null ? 'TOP ' . (int) $limite . ' ' : '';

        $sql = "SELECT {$top}no, nome, ncont, morada, codpost, email, telefone, tlmvl, vendedor, vendnm
                FROM cl
                WHERE estab = 0";

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

        // Valores (12/08), com os nomes REAIS deste PHC (confirmados na produção a 12/08):
        // linha = fi.epv / fi.desconto / fi.etiliquido (total líquido da linha, em euros —
        // NÃO existe fi.etotal); documento = ft.etotal (sem IVA) e ft.etotal + ft.ettiva
        // (com IVA — não há coluna única "com IVA" em euros neste esquema); ft.anulado.
        $sql = "SELECT {$top}fistamp, nmdoc, fno,
                       (SELECT fdata FROM ft WHERE ftstamp = fi.ftstamp) AS data,
                       (SELECT no FROM ft WHERE ftstamp = fi.ftstamp) AS cliente_no,
                       (SELECT etotal FROM ft WHERE ftstamp = fi.ftstamp) AS doc_total,
                       (SELECT etotal + ettiva FROM ft WHERE ftstamp = fi.ftstamp) AS doc_total_iva,
                       (SELECT anulado FROM ft WHERE ftstamp = fi.ftstamp) AS anulado,
                       ref, design, series, qtt, epv, desconto, etiliquido
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
                precoUnitario: $r->epv !== null ? (float) $r->epv : null,
                desconto: $r->desconto !== null ? (float) $r->desconto : null,
                totalLinha: $r->etiliquido !== null ? (float) $r->etiliquido : null,
                totalDocumento: $r->doc_total !== null ? (float) $r->doc_total : null,
                totalDocumentoIva: $r->doc_total_iva !== null ? (float) $r->doc_total_iva : null,
                anulada: (bool) $r->anulado,
            );
        }
    }

    public function obterArtigos(?int $limite = null): iterable
    {
        // Lê o catálogo de artigos da tabela st do PHC pela ligação 'erp' — a MESMA que os outros
        // syncs usam. Correlação por st.ref → id_erp. Só artigos com referência preenchida.
        //
        // Opção A (sem view): lê direto de st porque ainda não há acesso de ESCRITA ao PHC para
        // criar a view. §5 do CLAUDE.md: quando houver, envolver numa VIEW read-only dedicada
        // (vw_artigos) e ler dessa view, nunca da tabela bruta. (Igual aos restantes syncs.)
        //
        // SQL Server: o limite usa TOP (não LIMIT). É um inteiro, interpolado em segurança.
        $top = $limite !== null ? 'TOP ' . (int) $limite . ' ' : '';

        $sql = "SELECT {$top}ref, design, familia, faminome
                FROM st
                WHERE ref IS NOT NULL AND LTRIM(RTRIM(ref)) <> ''";

        foreach (DB::connection('erp')->select($sql) as $r) {
            // TRIM obrigatório: as colunas char do PHC vêm com padding de espaços à direita
            // (mesma lição do mastamp dos equipamentos — sem trim o id_erp não casava).
            yield new ArtigoErp(
                idErp: trim((string) $r->ref),
                designacao: $r->design !== null ? trim((string) $r->design) : null,
                familia: isset($r->familia) && trim((string) $r->familia) !== '' ? trim((string) $r->familia) : null,
                faminome: isset($r->faminome) && trim((string) $r->faminome) !== '' ? trim((string) $r->faminome) : null,
            );
        }
    }

    public function obterEquipamentos(?int $limite = null): iterable
    {
        // Lê os equipamentos da tabela ma do PHC pela ligação 'erp' (dblib/FreeTDS), a MESMA que
        // clientes e faturação já usam. Correlação por ma.mastamp → id_erp (é a chave única com que
        // os 16.761 já foram carregados — casar por aqui é o que torna o re-sync idempotente).
        // Cliente por ma.no → clientes.id_erp. Mapeamento PHC → aplicação:
        //
        //   ma.mastamp → id_erp          (chave de correlação do upsert)
        //   ma.serie   → numero_serie
        //   ma.design  → modelo
        //   ma.instal  → data_instalacao
        //   ma.no      → cliente (clientes.id_erp)
        //   ma.marca   → só filtro (fabricante fixa em 'Riello')
        //   st.familia / st.faminome → família do artigo (LEFT JOIN st ON st.ref = ma.ref)
        //
        // Filtro RIELLO server-side (só marca Riello atravessa a ligação) + série preenchida.
        // O filtro por nº de cliente SAIU: no PHC há faturas sem o cliente associado (erro
        // humano) e esses equipamentos nunca chegavam à app — agora vêm e ficam "por associar"
        // (o sync cria-os com local a null). Leitura direta da ma (§5: envolver numa VIEW
        // read-only quando houver acesso de escrita ao PHC para a criar). LEFT JOIN à st para a
        // família (a ligação ma.ref = st.ref é a mesma que o sync antigo em Python usava).
        //
        // SQL Server: o limite usa TOP (não LIMIT). É um inteiro, interpolado em segurança.
        $top = $limite !== null ? 'TOP ' . (int) $limite . ' ' : '';

        $sql = "SELECT {$top}ma.mastamp, ma.serie, ma.design, ma.instal, ma.no, ma.marca, ma.ousrdata, ma.ousrhora, st.familia, st.faminome
                FROM ma
                LEFT JOIN st ON st.ref = ma.ref
                WHERE ma.marca LIKE '%RIELLO%'
                  AND ma.serie IS NOT NULL AND LTRIM(RTRIM(ma.serie)) <> ''";

        foreach (DB::connection('erp')->select($sql) as $r) {
            // TRIM obrigatório nos campos de texto: as colunas char do PHC vêm com padding de
            // espaços à direita. Em especial o mastamp — os 16.761 já em produção foram carregados
            // pelo Python COM trim; sem trim aqui o id_erp não casava ("...001 " ≠ "...001") e o
            // updateOrCreate criava duplicados. Espelha o limpar()/.strip() do import_equip_riello.py.
            yield new EquipamentoErp(
                idErp: trim((string) $r->mastamp),
                numeroSerie: $r->serie !== null ? trim((string) $r->serie) : null,
                modelo: $r->design !== null ? trim((string) $r->design) : null,
                dataInstalacao: $r->instal ? \Illuminate\Support\Carbon::parse($r->instal)->format('Y-m-d') : null,
                // ma.no é numeric(10,0) → texto sem casas decimais, para casar com clientes.id_erp.
                clienteNo: $r->no !== null ? (string) (int) $r->no : null,
                marca: $r->marca !== null ? trim((string) $r->marca) : null,
                familia: isset($r->familia) && trim((string) $r->familia) !== '' ? trim((string) $r->familia) : null,
                faminome: isset($r->faminome) && trim((string) $r->faminome) !== '' ? trim((string) $r->faminome) : null,
                // Data de criação no PHC (ousrdata) + hora (ousrhora, char 'HH:MM[:SS]') —
                // é a "ordem do PHC" que a listagem usa nos "mais recentes".
                criadoEm: $r->ousrdata
                    ? \Illuminate\Support\Carbon::parse($r->ousrdata)->format('Y-m-d')
                        . ' ' . (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', trim((string) ($r->ousrhora ?? '')))
                            ? str_pad(trim((string) $r->ousrhora), 8, ':00')
                            : '00:00:00')
                    : null,
            );
        }
    }
}
