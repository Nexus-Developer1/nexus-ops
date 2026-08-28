<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Bus;

// A corrida COMPLETA do PHC (ignora os hashes do incremental — a rede de segurança semanal de
// domingo às 06:00, e o `?completo=1` da API) como CADEIA de jobs: um por etapa + um resumo.
//
// Porquê uma cadeia e não o SincronizarErp com `completo: true`: em modo completo cada etapa
// REESCREVE todas as linhas (dossiês: 200 923 em ~21 min; faturação: 192 142 em ~20 min) — o
// total (~45 min) rebentava o timeout de 1700 s do job único, que morria sempre a meio da
// faturação. Aconteceu TODOS os domingos (16/08, 23/08…): email de falha ao suporte, sem linha
// de auditoria, e a faturação nunca chegava a ser realinhada. Um job por etapa fica cada um
// folgado dentro do timeout (que TEM de ficar abaixo do retry_after=2100 s do redis — senão a
// fila reentrega o job a meio). O lock `erp-sync` é o mesmo em todos: nunca há sobreposição.
//
// Cada etapa acumula o seu resultado em cache; o ResumoSincronizacaoCompletaErp no fim junta
// tudo, regista UMA linha de auditoria, atualiza o "último resultado" e envia o email (no
// agendado). Se uma etapa for interrompida (timeout/crash), o seu failed() dispara o resumo na
// mesma — a cadeia pára, mas o email e a auditoria saem com "interrompida".
class CadeiaSincronizacaoCompletaErp
{
    public const CACHE_ACUMULADOR = 'erp-sync:cadeia';

    public static function despachar(string $origem): void
    {
        Bus::chain([
            ...array_map(
                fn (string $etapa) => new SincronizarEtapaErp($etapa, completo: true, origem: $origem, emCadeia: true),
                array_keys(SincronizarEtapaErp::ETAPAS),
            ),
            new ResumoSincronizacaoCompletaErp($origem),
        ])->dispatch();
    }
}
