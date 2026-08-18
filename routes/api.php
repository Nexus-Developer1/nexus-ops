<?php

use App\Jobs\SincronizarErp;
use App\Jobs\SincronizarEtapaErp;
use App\Models\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de sincronização PHC → Nexus Infra
|--------------------------------------------------------------------------
| O equivalente, para o Nexus Infra, do NXSync do Configurador (soon-srv2:8081): uma lista de
| URLs que DISPARAM e MONITORIZAM a sincronização do PHC — chamáveis de fora do browser (botão
| no PHC, cron externo, NXSync, curl). NÃO é uma API de dados nem de escrita: não traz nada
| de novo do PHC e não escreve no PHC. Reutiliza os 5 comandos, o job encadeado, o lock, os
| hashes incrementais e a auditoria que já corriam.
|
|  GET|POST /api/sync/tudo[?completo=1]     corrida encadeada (o botão do dashboard / o cron)
|  GET|POST /api/sync/{etapa}[?completo=1]  clientes | equipamentos | artigos | dossiers | faturacao
|  GET      /api/sync/estado                em curso? último resultado, últimas corridas, agenda
|
| Regras: (1) os disparos NUNCA correm o sync no pedido — vai para a fila e o pedido volta logo
| (202); a faturação demora ~20 min e o PHC nunca entra no caminho de um pedido HTTP (§5);
| (2) um sync em curso → 409 (o lock partilhado garante-o mesmo que o 409 falhe por corrida);
| (3) chave partilhada (ChaveApi) + throttle; (4) GET aceite nos disparos porque o PHC/NXSync
| só sabem fazer GET (o NXSync é todo GET) — POST é o "correto", ambos funcionam.
*/

Route::prefix('sync')->middleware(['chave.api', 'throttle:30,1'])->group(function () {

    // Há sync em curso? Marcador escrito pelos jobs (ver SincronizarErp::marcarEmCurso).
    $emCurso = fn (): ?array => Cache::get('erp-sync:em-curso');

    // Resposta comum aos disparos: 409 se já há um em curso, 202 quando entra na fila.
    $disparar = function (Request $r, string $descricao, callable $dispatch) use ($emCurso) {
        if (blank(config('erp.driver'))) {
            return response()->json(['mensagem' => 'A ligação ao PHC não está configurada neste ambiente.'], 503);
        }
        if ($atual = $emCurso()) {
            return response()->json(['mensagem' => 'Já há uma sincronização em curso — aguarde e volte a pedir.', 'em_curso' => $atual], 409);
        }

        $dispatch();

        return response()->json([
            'mensagem' => "Sincronização de {$descricao} enviada para a fila. Consulte /api/sync/estado.",
            'completo' => $r->boolean('completo'),
            'pedido_em' => now()->toIso8601String(),
        ], 202);
    };

    // Corrida ENCADEADA: clientes → equipamentos → artigos → dossiês → faturação (o mesmo job do
    // botão e do cron; silencioso por email — o resultado fica em /estado, no log e na auditoria).
    Route::match(['get', 'post'], '/tudo', fn (Request $r) => $disparar($r, 'todos os dados do PHC',
        fn () => SincronizarErp::dispatch(agendado: false, completo: $r->boolean('completo'), origem: 'api')
    ))->name('api.sync.tudo');

    // Estado — o que se consulta a seguir a um disparo.
    Route::get('/estado', function () use ($emCurso) {
        $ultimo = Cache::get('erp-sync:ultimo');

        // Últimas corridas (auditoria — uma linha por corrida, sistema).
        $corridas = Auditoria::query()->where('acao', 'sync_erp')->latest('id')->limit(10)->get()
            ->map(fn (Auditoria $a) => [
                'em' => $a->created_at?->toIso8601String(),
                'origem' => $a->detalhe['origem'] ?? (($a->detalhe['agendado'] ?? false) ? 'agendado' : 'dashboard'),
                'falhou' => (bool) ($a->detalhe['falhou'] ?? false),
                'resultados' => $a->detalhe['resultados'] ?? [],
            ]);

        return [
            'ligacao_phc' => filled(config('erp.driver')),
            'em_curso' => $emCurso(),
            'ultimo' => $ultimo,
            'ultimas_corridas' => $corridas,
            'agendado' => [
                'encadeado' => '08:00, 13:00 e 19:00 (Europe/Lisbon), todos os dias',
                'completo' => '06:00 (Europe/Lisbon), domingos — ignora os hashes',
            ],
            'etapas_disponiveis' => array_keys(SincronizarEtapaErp::ETAPAS),
        ];
    })->name('api.sync.estado');

    // UMA etapa (o /x/syncall do NXSync): /api/sync/clientes, /equipamentos, /artigos, /dossiers, /faturacao.
    Route::match(['get', 'post'], '/{etapa}', function (Request $r, string $etapa) use ($disparar) {
        return $disparar($r, SincronizarEtapaErp::ETAPAS[$etapa][0],
            fn () => SincronizarEtapaErp::dispatch($etapa, $r->boolean('completo'), 'api')
        );
    })->whereIn('etapa', array_keys(SincronizarEtapaErp::ETAPAS))->name('api.sync.etapa');
});
