<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Cabeçalhos de segurança (CLAUDE.md §11) — versionados e testados, em vez de viverem só na
// config do reverse proxy (onde não eram revisáveis e podiam desaparecer num deploy sem ninguém
// dar conta). A CSP é a cópia exata da que já estava em produção.
//
// HSTS fica de fora de propósito: é específico de HTTPS/TLS e pertence à camada do reverse proxy
// (Apache/Caddy), que sabe se a ligação é segura — não à aplicação.
class CabecalhosSeguranca
{
    // Política igual à que o Apache já enviava: 'self' por defeito; Alpine/Livewire precisam de
    // 'unsafe-eval'/'unsafe-inline'; Google Fonts em style/font; imagens de data:/blob: (fotos e
    // ícones da PWA); nada de plugins nem de embutir o site noutro (anti-clickjacking).
    private const CSP = "default-src 'self'; "
        . "script-src 'self' 'unsafe-eval' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Não sobrepõe se algo mais específico dentro da app já a tiver definido (ex.: uma rota
        // que precise de uma política própria). O reverse proxy corre depois disto, por isso não
        // interfere aqui — se o Apache ainda a enviar, é ele que decide o header final (idêntico).
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::CSP);
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
