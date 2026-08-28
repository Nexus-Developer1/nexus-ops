<?php

use App\Jobs\CadeiaSincronizacaoCompletaErp;
use App\Jobs\SincronizarErp;
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
// garantida pelo encadeamento + lock partilhado com o botão) e uma falha numa etapa não
// impede as seguintes. `agendado: true` → envia SEMPRE o email de resultado ao suporte
// (erp.email_sync), sucesso ou falha; o botão dispara sem email (só apressa o sync).
// Corre na fila (worker), não no processo do scheduler. Só com driver ERP configurado.
// timezone Europe/Lisbon: o servidor/app estão em UTC — sem isto, as "8h/13h/19h" eram
// UTC e, com a hora de verão, os syncs corriam às 9h/14h/20h portuguesas.
Schedule::job(new SincronizarErp(agendado: true))
    ->timezone('Europe/Lisbon')
    ->cron('0 8,13,19 * * *')
    ->onOneServer()
    ->when(fn () => filled(config('erp.driver')));

// Rede de segurança semanal: corrida COMPLETA (ignora os hashes do incremental) ao domingo
// de manhã cedo — realinha qualquer drift local em campos do ERP (10.ª revisão de segurança:
// sem isto, um registo adulterado na BD da app ou com hash "envenenado" ficava stale para
// sempre; o incremental só reescreve quando o PHC muda). Demora ~20 min, ninguém a usar.
// Em CADEIA (um job por etapa + resumo): em modo completo cada etapa reescreve todas as linhas
// (dossiês ~21 min, faturação ~20 min) e o job único rebentava o timeout de 1700 s a meio da
// faturação — TODOS os domingos (16/08, 23/08): email de falha, sem auditoria, faturação nunca
// realinhada. Ver CadeiaSincronizacaoCompletaErp. `->name()` é obrigatório no onOneServer com closure.
Schedule::call(fn () => CadeiaSincronizacaoCompletaErp::despachar('agendado-completo'))
    ->name('erp-sync-completo')
    ->timezone('Europe/Lisbon')
    ->cron('0 6 * * 0')
    ->onOneServer()
    ->when(fn () => filled(config('erp.driver')));

// Resumo diário de alertas proativos aos administradores (CLAUDE.md §9), às 08h de Lisboa.
Schedule::command('alertas:verificar')
    ->timezone('Europe/Lisbon')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();
