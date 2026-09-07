<?php

namespace App\Services\Alertas;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Quantos dias faltam para o certificado HTTPS expirar.
 *
 * Lê o certificado que está MESMO a ser servido (liga-se ao próprio site), e não o ficheiro
 * em disco: apanha também o caso de o certificado ter sido renovado mas o Apache não ter
 * recarregado — que é servir na mesma o antigo.
 *
 * Existe porque a renovação deste servidor é manual (validação por DNS à mão): o certbot
 * corre todos os dias, falha todos os dias em silêncio, e só se dava por isso quando os
 * browsers começassem a avisar os utilizadores. Set. 2026.
 */
class CertificadoTls
{
    // O resultado muda uma vez por dia, quando muito: uma ligação TLS por hora chega e sobra.
    private const CACHE_MINUTOS = 60;

    /** Dias até expirar (negativo se já expirou); null se não se conseguiu verificar. */
    public function diasParaExpirar(?string $url = null): ?int
    {
        $url = $url ?: (string) config('app.url');
        $partes = parse_url($url);
        $host = $partes['host'] ?? null;

        if (! $host || ($partes['scheme'] ?? 'https') !== 'https') {
            return null; // sem HTTPS não há nada a vigiar (ambientes locais)
        }

        $porta = (int) ($partes['port'] ?? 443);

        return Cache::remember("certificado-tls:{$host}:{$porta}", now()->addMinutes(self::CACHE_MINUTOS),
            fn () => $this->consultar($host, $porta));
    }

    private function consultar(string $host, int $porta): ?int
    {
        try {
            // verify_peer a false de propósito: o que interessa é a DATA do certificado, e um
            // certificado já expirado (o caso que queremos apanhar) faria a verificação falhar.
            $contexto = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ]]);

            $ligacao = @stream_socket_client(
                "ssl://{$host}:{$porta}", $errno, $erro, 8, STREAM_CLIENT_CONNECT, $contexto
            );

            if (! $ligacao) {
                Log::warning('Vigia do certificado: não foi possível ligar.', ['host' => $host, 'porta' => $porta, 'erro' => $erro]);

                return null;
            }

            $params = stream_context_get_params($ligacao);
            fclose($ligacao);

            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);
            if (! isset($cert['validTo_time_t'])) {
                return null;
            }

            return (int) now()->startOfDay()->diffInDays(
                Carbon::createFromTimestamp($cert['validTo_time_t'])->startOfDay(), false
            );
        } catch (Throwable $e) {
            Log::warning('Vigia do certificado falhou.', ['host' => $host, 'erro' => $e->getMessage()]);

            return null;
        }
    }
}
