<?php

namespace App\Services\Erp;

// DTO de um artigo do catálogo tal como vem do ERP PHC. Imutável e tipado.
// Mapeamento PHC (tabela st) → campos da aplicação:
//
//   st.ref      → idErp      (referência do artigo; chave de correlação = artigos.id_erp)
//   st.design   → designacao (descrição do artigo)
//   st.familia  → familia    (código da família)
//   st.faminome → faminome   (nome da família)
final readonly class ArtigoErp
{
    public function __construct(
        public string $idErp,
        public ?string $designacao = null,
        public ?string $familia = null,
        public ?string $faminome = null,
    ) {}
}
