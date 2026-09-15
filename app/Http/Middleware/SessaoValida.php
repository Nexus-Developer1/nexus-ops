<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
//
// 22.ª revisão de segurança — mais duas razões para expulsar, em TODOS os pedidos:
//  - conta DESATIVADA (`ativo` a falso): o `ativo` só era olhado no login (no portal), e uma
//    sessão já aberta continuava a trabalhar na Nexus até caducar;
//  - ACESSO AO MÓDULO retirado no portal: o portal apaga a linha em `acessos` mas não muda
//    `utilizadores.papel`, e a Nexus só olhava para o papel — quem perdia o módulo continuava
//    a entrar por URL direta. A verificação lê as tabelas do portal (mesma BD). Sem essas
//    tabelas (instalação sem portal, testes) não se aplica.
class SessaoValida
{
    // Chave do módulo Nexus na tabela `aplicacoes` do portal.
    public const MODULO = 'nexus-infra';

    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();

        if ($utilizador && (! $utilizador->ativo || ! $this->temAcessoAoModulo($utilizador))) {
            return $this->expulsar($request);
        }

        if ($utilizador && $utilizador->password_alterada_em
            && (int) $request->session()->get('autenticado_em', 0) < $utilizador->password_alterada_em->timestamp) {
            return $this->expulsar($request);
        }

        return $next($request);
    }

    // Só a equipa (admin/técnico) precisa do módulo no portal; o portal de cliente é outra
    // coisa (papel `cliente`, sem linha em `acessos`).
    private function temAcessoAoModulo(User $utilizador): bool
    {
        if ($utilizador->ehCliente() || ! self::portalPresente()) {
            return true;
        }

        return DB::table('acessos')
            ->join('aplicacoes', 'aplicacoes.id', '=', 'acessos.aplicacao_id')
            ->where('acessos.utilizador_id', $utilizador->id)
            ->where('aplicacoes.chave', self::MODULO)
            ->exists();
    }

    // As tabelas são do portal (migrações dele, mesma BD) — pode não haver portal. A resposta
    // fica em cache 10 min para não custar duas consultas ao catálogo em cada pedido.
    public static function portalPresente(): bool
    {
        return Cache::remember('portal.tabelas-acessos', 600, fn () => Schema::hasTable('acessos') && Schema::hasTable('aplicacoes'));
    }

    private function expulsar(Request $request): Response
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Livewire (XHR): 403 → o front redireciona; navegação normal → login (portal).
        if ($request->hasHeader('X-Livewire')) {
            abort(403);
        }

        return redirect()->route('login');
    }
}
