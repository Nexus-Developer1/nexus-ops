<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronização do ERP 3x/dia (08h, 13h, 19h): UMA corrida encadeada — clientes →
// equipamentos → faturação, cada etapa arranca quando a anterior acaba — via o MESMO job
// do botão "Sincronizar PHC" do dashboard (Jobs\SincronizarErp). Antes eram 3 crons
// desfasados (:00/:10/:20) e a faturação (~20 min) sobrepunha-se ao sync de equipamentos
// das :20 — duas ligações simultâneas ao PHC. Agora: nunca há sobreposição (a ordem é
// garantida pelo encadeamento + lock partilhado com o botão), uma falha numa etapa não
// impede as seguintes, e QUALQUER falha avisa o suporte por email (erp.email_falhas).
// Corre na fila (worker), não no processo do scheduler. Só com driver ERP configurado.
Schedule::job(new App\Jobs\SincronizarErp)
    ->cron('0 8,13,19 * * *')
    ->onOneServer()
    ->when(fn () => filled(config('erp.driver')));

// Resumo diário de alertas proativos aos administradores (CLAUDE.md §9).
Schedule::command('alertas:verificar')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();
