<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronização do ERP 3x/dia (08h, 13h, 19h) para o site ter dados atualizados. Só corre a
// sério quando há um driver ERP configurado (com ERP_DRIVER vazio resolve o NullErpDriver).
// - runInBackground: cada sync corre em processo próprio → uma falha não parte a outra nem o
//   scheduler; o schedule:run não fica bloqueado à espera da faturação (a pesada).
// - withoutOverlapping: se o sync anterior ainda corre, o novo não arranca por cima.
// - Escalonados para NÃO haver duas ligações simultâneas ao PHC: clientes às :00, faturação às
//   :10, equipamentos às :20. Os equipamentos correm DEPOIS dos clientes porque dependem de
//   clientes.id_erp já estar sincronizado (correlação ma.no → clientes.id_erp).
Schedule::command('erp:sincronizar-clientes')
    ->cron('0 8,13,19 * * *')
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer()
    ->when(fn () => filled(config('erp.driver')));

Schedule::command('erp:sincronizar-faturacao')
    ->cron('10 8,13,19 * * *')
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer()
    ->when(fn () => filled(config('erp.driver')));

Schedule::command('erp:sincronizar-equipamentos')
    ->cron('20 8,13,19 * * *')
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer()
    ->when(fn () => filled(config('erp.driver')));

// Resumo diário de alertas proativos aos administradores (CLAUDE.md §9).
Schedule::command('alertas:verificar')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();
