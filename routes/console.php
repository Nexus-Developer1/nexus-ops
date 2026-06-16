<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronização diária dos clientes do ERP (hora em config/erp.php). Só sincroniza
// a sério quando há um driver ERP configurado — com ERP_DRIVER vazio resolve o
// NullErpDriver, por isso evitamos correr o comando à toa.
Schedule::command('erp:sincronizar-clientes')
    ->dailyAt(config('erp.sync_hora'))
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn () => filled(config('erp.driver')));

// Resumo diário de alertas proativos aos administradores (CLAUDE.md §9).
Schedule::command('alertas:verificar')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();
