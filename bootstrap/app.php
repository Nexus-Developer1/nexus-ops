<?php

use App\Http\Middleware\CabecalhosSeguranca;
use App\Http\Middleware\ChaveApi;
use App\Http\Middleware\SessaoValida;
use App\Http\Middleware\VerificaPapel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API de sincronização PHC → Nexus (chave partilhada; só dispara/monitoriza os syncs).
        // Grupo `api`: sem sessão nem CSRF. Ver routes/api.php.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'papel' => VerificaPapel::class,
            'chave.api' => ChaveApi::class,
        ]);

        // Cabeçalhos de segurança em todas as respostas web (CSP, nosniff, X-Frame, Referrer).
        // Versionado e testado — antes vivia só na config do Apache. Ver CabecalhosSeguranca.
        $middleware->web(append: CabecalhosSeguranca::class);

        // Invalidação de sessão à mudança de password — no grupo `web` para cobrir também as
        // ações Livewire (/livewire/update), não só os GET de página (19.ª revisão).
        $middleware->web(append: SessaoValida::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
