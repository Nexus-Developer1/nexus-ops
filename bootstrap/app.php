<?php

use App\Http\Middleware\CabecalhosSeguranca;
use App\Http\Middleware\VerificaPapel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'papel' => VerificaPapel::class,
        ]);

        // Cabeçalhos de segurança em todas as respostas web (CSP, nosniff, X-Frame, Referrer).
        // Versionado e testado — antes vivia só na config do Apache. Ver CabecalhosSeguranca.
        $middleware->web(append: CabecalhosSeguranca::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
