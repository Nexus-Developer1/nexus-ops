<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Agenda\GeradorIcs;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

// Feed ICS da agenda para subscrição no Outlook (só leitura). Sem sessão: o Outlook novo e o
// OWA fazem o fetch do lado da Microsoft, por isso o hostname é público e a autenticação é o
// TOKEN no URL, validado contra a BD em cada pedido — revogar/regenerar na página "Feeds da
// agenda" invalida o URL antigo de imediato (um URL assinado não permitia isso). Um token
// desconhecido ou de conta inativa dá 404 (não distingue "não existe" de "revogado").
//
// O Outlook bate no endpoint repetidamente: a resposta fica em cache 10 min por token e leva
// ETag/Last-Modified — um pedido condicional com o mesmo estado sai em 304 sem gerar nada.
class FeedAgendaController
{
    private const CACHE_MINUTOS = 10;

    public function __invoke(Request $request, string $token, GeradorIcs $gerador): Response
    {
        $subscritor = User::query()
            ->where('agenda_feed_token', $token)
            ->where('ativo', true)
            ->first();

        abort_unless($subscritor && strlen($token) >= 32, 404);

        $ultima = $gerador->ultimaAlteracao();
        $etag = '"'.sha1($token.'|'.($ultima?->timestamp ?? 0)).'"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        $ics = Cache::remember("agenda-feed:{$subscritor->id}:{$etag}", now()->addMinutes(self::CACHE_MINUTOS), fn () => $gerador->feed($subscritor));

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agenda-nexus-infra.ics"',
            'Cache-Control' => 'private, max-age='.(self::CACHE_MINUTOS * 60),
            'ETag' => $etag,
            'Last-Modified' => ($ultima ?? now())->toRfc7231String(),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
