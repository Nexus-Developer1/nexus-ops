<?php

namespace App\Services\Erp;

// DTO de uma linha de faturação tal como vem do ERP PHC. Imutável e tipado.
// Mapeamento PHC (tabela fi; data via ft por ftstamp) — só os campos da query validada:
//
//   fi.fistamp → idErp   (chave de correlação do upsert)
//   fi.nmdoc   → nmdoc   (tipo de documento)
//   fi.fno     → fno     (nº da fatura)
//   ft.fdata   → data    (via ftstamp)
//   fi.ref     → ref     (referência do artigo)
//   fi.design  → design  (descrição da linha)
//   fi.series  → series  (nº(s) de série — chave de cruzamento com equipamentos)
//   fi.qtt     → qtt     (quantidade)
final readonly class LinhaFaturaErp
{
    public function __construct(
        public string $idErp,
        public ?string $nmdoc = null,
        public ?int $fno = null,
        public ?string $data = null,   // 'Y-m-d'
        public ?string $ref = null,
        public ?string $design = null,
        public ?string $series = null,
        public ?float $qtt = null,
    ) {}
}
