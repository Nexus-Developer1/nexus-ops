<?php

namespace App\Services\Erp;

// DTO de uma linha de faturação tal como vem do ERP PHC. Imutável e tipado.
// Mapeamento PHC (tabela fi; data via ft por ftstamp) — só os campos da query validada:
//
//   fi.fistamp → idErp     (chave de correlação do upsert)
//   ft.no      → clienteNo (via ftstamp; = clientes.id_erp)
//   fi.nmdoc   → nmdoc     (tipo de documento)
//   fi.fno     → fno       (nº da fatura)
//   ft.fdata   → data      (via ftstamp)
//   fi.ref     → ref       (referência do artigo)
//   fi.design  → design    (descrição da linha)
//   fi.series  → series    (nº(s) de série — chave de cruzamento com equipamentos)
//   fi.qtt     → qtt       (quantidade)
//   fi.epv     → precoUnitario     (preço unitário, sem IVA)
//   fi.desconto→ desconto          (percentagem)
//   fi.etotal  → totalLinha        (total da linha, sem IVA)
//   ft.etotal  → totalDocumento    (via ftstamp; total do documento SEM IVA)
//   ft.ettotal → totalDocumentoIva (via ftstamp; total do documento COM IVA)
//   ft.anulado → anulada           (via ftstamp; documento anulado no PHC)
final readonly class LinhaFaturaErp
{
    public function __construct(
        public string $idErp,
        public ?string $clienteNo = null,   // PHC ft.no (nº de cliente) = clientes.id_erp
        public ?string $nmdoc = null,
        public ?int $fno = null,
        public ?string $data = null,   // 'Y-m-d'
        public ?string $ref = null,
        public ?string $design = null,
        public ?string $series = null,
        public ?float $qtt = null,
        public ?float $precoUnitario = null,
        public ?float $desconto = null,
        public ?float $totalLinha = null,
        public ?float $totalDocumento = null,
        public ?float $totalDocumentoIva = null,
        public bool $anulada = false,
    ) {}
}
