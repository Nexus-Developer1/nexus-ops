<?php

namespace App\Services\Graph;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Cliente mínimo do Microsoft Graph (app-only / client credentials), partilhado pelo calendário
// da agenda. Credenciais de config('services.microsoft_graph') — nunca aqui. O token é o MESMO
// (mesma chave de cache) que o transporte de email usa: um token, várias permissões.
class ClienteGraph
{
    private const URL_TOKEN = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    public const BASE = 'https://graph.microsoft.com/v1.0';

    public const CHAVE_CACHE_TOKEN = 'ms_graph_token';

    public function get(string $caminho, array $query = []): Response
    {
        return Http::withToken($this->token())->acceptJson()->timeout(20)->get(self::BASE.$caminho, $query);
    }

    public function post(string $caminho, array $corpo): Response
    {
        return Http::withToken($this->token())->acceptJson()->timeout(20)->post(self::BASE.$caminho, $corpo);
    }

    public function patch(string $caminho, array $corpo): Response
    {
        return Http::withToken($this->token())->acceptJson()->timeout(20)->patch(self::BASE.$caminho, $corpo);
    }

    public function delete(string $caminho): Response
    {
        return Http::withToken($this->token())->acceptJson()->timeout(20)->delete(self::BASE.$caminho);
    }

    /** Permissões de aplicação concedidas (claim `roles` do token) — para diagnóstico. */
    public function permissoes(): array
    {
        $partes = explode('.', $this->token());
        $claims = json_decode(base64_decode(strtr($partes[1] ?? '', '-_', '+/')) ?: '{}', true);

        return $claims['roles'] ?? [];
    }

    private function token(): string
    {
        if ($token = Cache::get(self::CHAVE_CACHE_TOKEN)) {
            return $token;
        }

        $c = config('services.microsoft_graph');
        $resposta = Http::asForm()->timeout(20)->post(sprintf(self::URL_TOKEN, $c['tenant_id']), [
            'client_id' => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'grant_type' => 'client_credentials',
            'scope' => 'https://graph.microsoft.com/.default',
        ]);

        if ($resposta->failed()) {
            throw new RuntimeException('Falha a obter token do Microsoft Graph: '.$resposta->status());
        }

        $token = (string) $resposta->json('access_token');
        Cache::put(self::CHAVE_CACHE_TOKEN, $token, now()->addSeconds(max(60, (int) $resposta->json('expires_in', 3600) - 60)));

        return $token;
    }
}
