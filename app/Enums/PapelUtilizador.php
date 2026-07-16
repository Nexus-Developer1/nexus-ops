<?php

namespace App\Enums;

// Papéis de acesso (RBAC) — ver secção 7 do CLAUDE.md.
enum PapelUtilizador: string
{
    case Admin = 'admin';
    case Tecnico = 'tecnico';
    case Cliente = 'cliente';
}
