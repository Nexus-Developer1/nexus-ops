<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// Um 419 («page expired») fica no log com contexto. O Laravel não regista a falha de CSRF
// de propósito (é ruído em sites públicos), mas esta é uma aplicação interna: um 419 a meio
// de uma sessão viva é um sintoma, não ruído — a 21/09 um técnico apanhou três seguidos na
// agenda e não havia rasto nenhum. Fica o caminho, o componente Livewire e os métodos
// chamados; os tokens NUNCA vão para o log. É o PRIMEIRO middleware (prepend) para ver a
// resposta final, venha o 419 de onde vier (CSRF, versão do Livewire, propriedade bloqueada).
class Regista419
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 419) {
            $tokenPedido = (string) ($request->header('X-CSRF-TOKEN') ?: $request->input('_token'));

            Log::warning('Resposta 419 (page expired).', [
                'caminho' => $request->path(),
                'metodo' => $request->method(),
                'livewire' => $request->hasHeader('X-Livewire'),
                'sessao_iniciada' => $request->hasSession() && $request->session()->isStarted(),
                'sessao_tem_utilizador' => (bool) $request->user(),
                'pedido_traz_token' => $tokenPedido !== '',
                'tokens_iguais' => $request->hasSession() && hash_equals((string) $request->session()->token(), $tokenPedido),
                'componentes' => $this->componentes($request),
                'referer' => $request->headers->get('referer'),
            ]);
        }

        return $response;
    }

    /**
     * Componentes Livewire do pedido: nome, métodos chamados e propriedades atualizadas.
     *
     * @return list<array{componente: ?string, metodos: list<mixed>, atualizacoes: list<string>}>
     */
    private function componentes(Request $request): array
    {
        return collect($request->input('components', []))
            ->map(function ($c) {
                $memo = json_decode((string) ($c['snapshot'] ?? ''), true)['memo'] ?? [];

                return [
                    'componente' => $memo['name'] ?? null,
                    'metodos' => collect($c['calls'] ?? [])->pluck('method')->all(),
                    'atualizacoes' => array_keys($c['updates'] ?? []),
                ];
            })->values()->all();
    }
}
