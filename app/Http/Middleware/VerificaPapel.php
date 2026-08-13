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

        // Vaga 1: mudar a password invalida as sessões ANTERIORES à mudança — sem isto, uma
        // sessão roubada sobrevivia ao reset (que é exatamente quando se quer expulsá-la).
        // Sessões de antes do deploy (sem marca) só caem se a password mudar depois disto.
        if ($utilizador && $utilizador->password_alterada_em
            && (int) session('autenticado_em', 0) < $utilizador->password_alterada_em->timestamp) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        if ($utilizador && in_array($utilizador->papel->value, $papeis, true)) {
            return $next($request);
        }

        if ($utilizador) {
            return redirect()->route($utilizador->rotaInicial());
        }

        return redirect()->route('login');
    }
}
