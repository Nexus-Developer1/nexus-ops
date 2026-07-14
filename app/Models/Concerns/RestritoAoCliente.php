<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

// Isolamento por cliente imposto na CAMADA DE DADOS (invariante §2 #3 do CLAUDE.md):
// quando o utilizador autenticado é um cliente, todas as queries do modelo são
// filtradas para o seu cliente_id — independentemente de onde a query é escrita.
// Admin/técnico não são afetados; operações de sistema (jobs/console, sem auth) também não.
//
// Cada modelo que usa o trait define como se liga a um cliente em restringirAoCliente().
trait RestritoAoCliente
{
    public static function bootRestritoAoCliente(): void
    {
        static::addGlobalScope('cliente', function (Builder $query) {
            $utilizador = auth()->user();

            if (! $utilizador || ! $utilizador->ehCliente()) {
                return; // admin/técnico e operações de sistema (sem auth) não são filtrados
            }

            if ($utilizador->cliente_id) {
                static::restringirAoCliente($query, (int) $utilizador->cliente_id);

                return;
            }

            // Cliente sem cliente_id: FAIL-CLOSED. Nunca deixar passar tudo — não vê nada.
            // Evita o fail-open em que uma conta de cliente mal formada veria todos os dados.
            $query->whereRaw('1 = 0');
        });
    }
}
