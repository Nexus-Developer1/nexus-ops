<?php

namespace App\Services\Erp;

// DTO de um equipamento tal como vem do ERP PHC. Imutável e tipado.
// Mapeamento PHC (tabela ma, só marca RIELLO — WHERE server-side) → campos da aplicação:
//
//   ma.mastamp → idErp          (chave de correlação do upsert; = equipamentos.id_erp)
//   ma.serie   → numeroSerie    (nº de série)
//   ma.design  → modelo         (descrição do artigo)
//   ma.instal  → dataInstalacao (data de instalação, 'Y-m-d')
//   ma.no      → clienteNo      (nº de cliente; = clientes.id_erp)
//   ma.marca   → marca          (só Riello chega até aqui; fabricante fixa em 'Riello')
final readonly class EquipamentoErp
{
    public function __construct(
        public string $idErp,
        public ?string $numeroSerie = null,
        public ?string $modelo = null,
        public ?string $dataInstalacao = null,   // 'Y-m-d'
        public ?string $clienteNo = null,          // PHC ma.no = clientes.id_erp
        public ?string $marca = null,
    ) {}
}
