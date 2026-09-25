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

    /**
     * Devolve as linhas de faturação do ERP (só linhas com nº de série — equipamentos).
     *
     * @param  int|null  $limite  Nº máximo de linhas a devolver (null = decisão do driver).
     * @return iterable<LinhaFaturaErp>
     */
    public function obterLinhasFatura(?int $limite = null): iterable;

    /**
     * Devolve os equipamentos do ERP (tabela ma, só marca RIELLO — filtro server-side).
     *
     * @param  int|null  $limite  Nº máximo de equipamentos a devolver (null = decisão do driver).
     * @return iterable<EquipamentoErp>
     */
    public function obterEquipamentos(?int $limite = null): iterable;

    /**
     * Devolve os artigos do catálogo do ERP (tabela st — referência + designação + família).
     *
     * @param  int|null  $limite  Nº máximo de artigos a devolver (null = decisão do driver).
     * @return iterable<ArtigoErp>
     */
    public function obterArtigos(?int $limite = null): iterable;

    /**
     * Devolve os dossiês do ERP (tabela bo — tipos 1/3/7: propostas e encomendas).
     *
     * @param  int|null  $limite  Nº máximo de dossiês a devolver (null = decisão do driver).
     * @return iterable<DossierErp>
     */
    public function obterDossiers(?int $limite = null): iterable;

    /**
     * Devolve as LINHAS de um dossiê (tabela bi), lidas AO VIVO por bostamp. Ao contrário
     * dos cabeçalhos, as linhas não se sincronizam — buscam-se ao abrir o dossiê.
     *
     * @param  string  $bostamp  A chave do dossiê (dossiers.id_erp = bo.bostamp).
     * @return iterable<LinhaDossierErp>
     */
    public function obterLinhasDossier(string $bostamp): iterable;

    /**
     * Devolve o TOTAL (débito) de um dossiê, lido AO VIVO por bostamp (bo.etotaldeb). O dossiê
     * pode ter mudado no PHC depois da última sincronização — a ficha mostra este, que bate
     * certo com as linhas (também ao vivo). Null = dossiê não encontrado.
     */
    public function obterTotalDossier(string $bostamp): ?float;

    /**
     * Os totais (débito) de VÁRIOS dossiês numa só leitura ao vivo — para a listagem mostrar o
     * valor atual de cada linha da página, não o da última sincronização.
     *
     * @param  list<string>  $bostamps
     * @return array<string, float> bostamp => total (os que não estão no PHC ficam de fora)
     */
    public function obterTotaisDossiers(array $bostamps): array;
}
