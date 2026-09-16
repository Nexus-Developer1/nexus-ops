<?php

namespace App\Enums;

// Papéis de acesso (RBAC) — ver secção 7 do CLAUDE.md.
enum PapelUtilizador: string
{
    case Admin = 'admin';
    case Tecnico = 'tecnico';
    // Só o módulo de despesas (set. 2026): vê e trata das despesas de toda a gente,
    // e não entra em mais nada da aplicação.
    case Financeiro = 'financeiro';
    case Cliente = 'cliente';
}
