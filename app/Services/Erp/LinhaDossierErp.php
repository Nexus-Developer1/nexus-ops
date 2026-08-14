<?php

namespace App\Services\Erp;

// DTO de uma linha de dossiê tal como vem do ERP PHC (tabela `bi`). Imutável e tipado.
// Estas linhas são lidas AO VIVO (não sincronizadas): buscam-se ao PHC no momento em que se
// abre o dossiê. Mapeamento PHC → aplicação (só os campos da query validada):
//
//   bi.ref     → ref
//   bi.usr6    → pn            (part number)
//   bi.usr1    → marca
//   bi.design  → descricao
//   bi.binum1  → faltas
//   bi.qtt     → qtt
//   bi.qtt2    → movimentado
//   bi.series  → series
//   bi.edebito → valorUnitario
//   bi.ettdeb  → total
final readonly class LinhaDossierErp
{
    public function __construct(
        public ?string $ref = null,
        public ?string $pn = null,
        public ?string $marca = null,
        public ?string $descricao = null,
        public ?float $faltas = null,
        public ?float $qtt = null,
        public ?float $movimentado = null,
        public ?string $series = null,
        public ?float $valorUnitario = null,
        public ?float $total = null,
    ) {}
}
