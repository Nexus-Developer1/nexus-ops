<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronização periódica dos clientes do ERP (periodicidade em config/erp.php).
Schedule::command('erp:sincronizar-clientes')
    ->cron(config('erp.sync_cron'))
    ->withoutOverlapping()
    ->onOneServer();

// Resumo diário de alertas proativos aos administradores (CLAUDE.md §9).
Schedule::command('alertas:verificar')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();
