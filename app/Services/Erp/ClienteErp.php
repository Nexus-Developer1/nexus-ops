<?php

namespace App\Services\Erp;

// DTO de um cliente tal como vem do ERP. Imutável e tipado.
final readonly class ClienteErp
{
    public function __construct(
        public string $idErp,
        public string $nome,
        public ?string $nif = null,
        public ?string $email = null,
        public ?string $telefone = null,
        public ?string $morada = null,
    ) {}
}
