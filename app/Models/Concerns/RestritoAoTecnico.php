<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

// Isolamento por técnico na camada de dados (CLAUDE.md §7: o técnico vê "as SUAS
// intervenções, agenda e relatórios"). Quando o utilizador autenticado é técnico,
// as queries do modelo são filtradas ao seu id. Admin e operações de sistema não filtram.
//
// Mutuamente exclusivo com RestritoAoCliente (um utilizador é técnico OU cliente).
// Cada modelo define como se liga ao técnico em restringirAoTecnico().
trait RestritoAoTecnico
{
    public static function bootRestritoAoTecnico(): void
    {
        static::addGlobalScope('tecnico', function (Builder $query) {
            $utilizador = auth()->user();

            if ($utilizador && $utilizador->ehTecnico()) {
                static::restringirAoTecnico($query, (int) $utilizador->id);
            }
        });
    }
}
