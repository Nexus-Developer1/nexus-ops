<?php

namespace App\Services\Erp;

// DTO de um cliente tal como vem do ERP. Imutável e tipado.
final readonly class ClienteErp
{
    public function __construct(
        public string $idErp,
        public string $nome,
        public ?string $nif = null,        // PHC cl.ncont
        public ?string $email = null,
        public ?string $telefone = null,
        public ?string $morada = null,
        public ?string $codpost = null,    // PHC cl.codpost
        public ?string $tlmvl = null,      // PHC cl.tlmvl
        public ?int $vendedor = null,      // PHC cl.vendedor (código)
        public ?string $vendnm = null,     // PHC cl.vendnm
    ) {}
}
