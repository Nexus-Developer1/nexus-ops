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
//   st.familia → familia        (código da família do artigo; via LEFT JOIN st ON st.ref = ma.ref)
//   st.faminome→ faminome       (nome da família — usado para o filtro na listagem)
final readonly class EquipamentoErp
{
    public function __construct(
        public string $idErp,
        public ?string $numeroSerie = null,
        public ?string $modelo = null,
        public ?string $dataInstalacao = null,   // 'Y-m-d'
        public ?string $clienteNo = null,          // PHC ma.no = clientes.id_erp
        public ?string $marca = null,
        public ?string $familia = null,            // PHC st.familia (via ma.ref = st.ref)
        public ?string $faminome = null,           // PHC st.faminome
    ) {}
}
