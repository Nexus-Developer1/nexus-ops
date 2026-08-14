<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// RBAC ao nível da rota (CLAUDE.md §7) — complementa o isolamento na camada de dados.
// Encaminha cada utilizador para a sua área (portal vs. aplicação) em vez de 403.
class VerificaPapel
{
    public function handle(Request $request, Closure $next, string ...$papeis): Response
    {
        $utilizador = $request->user();

        // A invalidação de sessão à mudança de password vive agora no middleware SessaoValida
        // (grupo `web` — cobre também as ações Livewire, que esta rota-middleware não vê).

        if ($utilizador && in_array($utilizador->papel->value, $papeis, true)) {
            return $next($request);
        }

        if ($utilizador) {
            return redirect()->route($utilizador->rotaInicial());
        }

        return redirect()->route('login');
    }
}
