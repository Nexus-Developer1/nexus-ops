<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Invalidação de sessão à mudança de password (Vaga 1, reforçado na 19.ª revisão de
// segurança). SEM parâmetros e no grupo `web` de propósito: corre em TODAS as requisições
// web, incluindo o endpoint `/livewire/update` das ações Livewire — o `VerificaPapel` só
// corria nos GET de página inteira, deixando as ações (guardar, eliminar, enviar) abertas a
// uma sessão roubada mesmo depois do reset.
//
// Uma sessão cuja marca de autenticação (`autenticado_em`, gravada no login pós-MFA) é
// ANTERIOR à última mudança de password do utilizador é expulsa. Direção fail-safe: no pior
// caso um utilizador legítimo volta a entrar. Sessões sem marca (anteriores ao deploy da
// Vaga 1) contam como 0 → só caem se a password tiver mudado depois.
class SessaoValida
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();

        if ($utilizador && $utilizador->password_alterada_em
            && (int) $request->session()->get('autenticado_em', 0) < $utilizador->password_alterada_em->timestamp) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Livewire (XHR): 403 → o front redireciona; navegação normal → login.
            if ($request->hasHeader('X-Livewire')) {
                abort(403);
            }

            return redirect()->route('login');
        }

        return $next($request);
    }
}
