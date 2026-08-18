<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Autenticação da API de sincronização por CHAVE PARTILHADA (config api.chave, do .env do
// servidor). Quem chama é um sistema (botão no PHC, cron, NXSync) — não há utilizadores,
// papéis nem sessão. Aceita `Authorization: Bearer <chave>` ou `X-Api-Key: <chave>` (o PHC
// pode não conseguir pôr um Authorization header; um header simples resolve).
//
// Fail-closed: sem chave configurada, a API está DESLIGADA (503) — nunca "aberta por engano"
// num ambiente onde ninguém definiu a chave. Comparação em tempo constante (hash_equals).
// Respostas sempre em JSON — um script não sabe o que fazer com um redirect para o login.
class ChaveApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $esperada = (string) config('api.chave');

        if ($esperada === '') {
            return response()->json(['mensagem' => 'API de sincronização desligada neste ambiente (sem chave configurada).'], 503);
        }

        $recebida = (string) ($request->bearerToken() ?? $request->header('X-Api-Key', ''));

        if ($recebida === '' || ! hash_equals($esperada, $recebida)) {
            return response()->json(['mensagem' => 'Chave de API em falta ou inválida.'], 401);
        }

        return $next($request);
    }
}
