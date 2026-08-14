<?php

namespace App\Services\Erp;

// DTO de um dossiê tal como vem do ERP PHC (tabela `bo`). Imutável e tipado.
// Mapeamento PHC → aplicação (só os campos da query validada):
//
//   bo.bostamp   → idErp        (chave de correlação do upsert)
//   bo.ndos      → ndos         (tipo: 1 = Encomenda Peças, 3 = Proposta, 7 = Encomenda Produção)
//   bo.nmdos     → nmdos        (nome do tipo de dossiê)
//   bo.obrano    → obrano       (nº do dossiê/obra)
//   bo.dataobra  → data         ('Y-m-d')
//   bo.boano     → ano
//   bo.no        → clienteNo    (= clientes.id_erp)
//   bo.nome      → nome         (cliente, denormalizado)
//   bo.etotaldeb → totalDebito
//   bo.fechada   → fechada
//   bo.u_relat   → uRelat       (campo de utilizador)
final readonly class DossierErp
{
    public function __construct(
        public string $idErp,
        public ?int $ndos = null,
        public ?string $nmdos = null,
        public ?int $obrano = null,
        public ?string $data = null,   // 'Y-m-d'
        public ?int $ano = null,
        public ?string $clienteNo = null,
        public ?string $nome = null,
        public ?float $totalDebito = null,
        public bool $fechada = false,
        public ?string $uRelat = null,
    ) {}
}
