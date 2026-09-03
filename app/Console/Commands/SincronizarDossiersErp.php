<?php

namespace App\Console\Commands;

use App\Models\Dossier;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

// Sincroniza os dossiês a partir do ERP (PHC, tabela bo): faz upsert na BD da aplicação,
// correlacionando por id_erp (bo.bostamp). Só os tipos 1/3/7 (o driver aplica o WHERE ndos).
// Operação read-only do lado do ERP e sempre em background (agendada — ver routes/console.php).
//
// No fim de cada corrida COMPLETA (sem --limit) faz a CONFERÊNCIA com o PHC (pedido da equipa,
// set. 2026): marca os dossiês que já lá não existem (órfãos) e desmarca os que reapareceram.
// Nunca apaga — o espelho é read-only e pode ter ligações locais; a marca serve para se ver na
// listagem e para o resumo do email. As ALTERAÇÕES vindas do PHC ficam registadas no próprio
// dossiê (alterado_erp_em + alteracoes_erp: campo, de, para), em vez de serem reescritas em
// silêncio como antes.
class SincronizarDossiersErp extends Command
{
    protected $signature = 'erp:sincronizar-dossiers {--limit= : Nº máximo de dossiês a processar} {--completo : Ignora os hashes e reprocessa tudo}';

    protected $description = 'Sincroniza os dossiês (propostas e encomendas) a partir do ERP (read-only, upsert por id_erp).';

    public function handle(ErpSyncDriver $erp): int
    {
        $driver = config('erp.driver') ?: '(inativo)';
        $limite = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info("A sincronizar dossiês a partir do ERP (driver: {$driver})...");

        $criados = 0;
        $atualizados = 0;
        $iguais = 0;
        $erros = 0;
        $conferencia = ['orfaos' => 0, 'reencontrados' => 0, 'saltada' => null];

        try {
            $this->sincronizar($erp, $limite, $criados, $atualizados, $iguais, $erros, $conferencia);
        } catch (Throwable $e) {
            // Falha de LIGAÇÃO/timeout (PHC em baixo) → loga e devolve FAILURE, sem rebentar
            // com exceção não tratada (não parte o scheduler nem os outros syncs).
            $this->error('Sync de dossiês FALHOU: '.$e->getMessage());
            Log::error('Sync de dossiês do ERP falhou.', ['driver' => $driver, 'erro' => $e->getMessage()]);

            return self::FAILURE;
        }

        $resumo = "{$criados} criados, {$atualizados} atualizados, {$iguais} iguais (saltados), {$erros} erros";
        $resumo .= $conferencia['saltada']
            ? ' (conferência com o PHC saltada: '.$conferencia['saltada'].').'
            : ", {$conferencia['orfaos']} ausentes do PHC, {$conferencia['reencontrados']} reencontrados.";
        $this->info("Sincronização concluída: {$resumo}");

        // Auditoria do sync (CLAUDE.md §11).
        Log::info('Sync de dossiês do ERP concluído.', [
            'driver' => $driver,
            'limite' => $limite,
            'criados' => $criados,
            'atualizados' => $atualizados,
            'iguais' => $iguais,
            'erros' => $erros,
            'ausentes_do_erp' => $conferencia['orfaos'],
            'reencontrados' => $conferencia['reencontrados'],
            'conferencia_saltada' => $conferencia['saltada'],
        ]);

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }

    // Percorre o ERP e faz upsert INCREMENTAL: o hash dos dados da última corrida vive em
    // dossiers.hash_sync; hash igual → dossiê saltado sem nenhuma query. Erros por linha são
    // contados (não param o sync); uma falha de ligação propaga para o handle() → FAILURE.
    private function sincronizar(ErpSyncDriver $erp, ?int $limite, int &$criados, int &$atualizados, int &$iguais, int &$erros, array &$conferencia): void
    {
        $forcarTudo = (bool) $this->option('completo');

        // id_erp de tudo o que o PHC devolveu nesta corrida — base da conferência do fim.
        $vistos = [];

        // Mapa id_erp → hash da última corrida (UMA query para a tabela toda).
        $hashes = Dossier::whereNotNull('id_erp')->pluck('hash_sync', 'id_erp')
            ->mapWithKeys(fn ($h, $k) => [(string) $k => $h])
            ->all();

        foreach ($erp->obterDossiers($limite) as $dossierErp) {
            try {
                $dados = [
                    'ndos' => $dossierErp->ndos,
                    'nmdos' => $dossierErp->nmdos,
                    'obrano' => $dossierErp->obrano,
                    'data' => $dossierErp->data,
                    'ano' => $dossierErp->ano,
                    'cliente_no' => $dossierErp->clienteNo,
                    'nome' => $dossierErp->nome,
                    'total_debito' => $dossierErp->totalDebito,
                    'fechada' => $dossierErp->fechada,
                    'u_relat' => $dossierErp->uRelat,
                ];
                $hash = md5((string) json_encode($dados, JSON_INVALID_UTF8_SUBSTITUTE));

                // Visto no PHC (mesmo que se salte a escrita) — senão a conferência do fim
                // dava todos os inalterados como órfãos.
                $vistos[(string) $dossierErp->idErp] = true;

                // Nada mudou no ERP desde a última corrida → salta (zero queries).
                if (! $forcarTudo && ($hashes[$dossierErp->idErp] ?? null) === $hash) {
                    $iguais++;

                    continue;
                }

                // Upsert por id_erp: existe → atualiza; não existe → cria. Em vez de
                // updateOrCreate, firstOrNew + fill (mesmas queries) para se poder ver o que
                // MUDOU antes de gravar e deixar registo da alteração no próprio dossiê.
                $dossier = Dossier::firstOrNew(['id_erp' => $dossierErp->idErp]);
                $novo = ! $dossier->exists;
                $dossier->fill($dados);

                if (! $novo) {
                    $mudancas = [];
                    foreach (array_keys($dossier->getDirty()) as $campo) {
                        if (! in_array($campo, Dossier::CAMPOS_ERP, true)) {
                            continue; // hashes e carimbos do sync não são alterações do PHC
                        }
                        $mudancas[$campo] = [
                            'de' => $this->valorLegivel($dossier->getOriginal($campo)),
                            'para' => $this->valorLegivel($dossier->$campo),
                        ];
                    }

                    if ($mudancas !== []) {
                        $dossier->alterado_erp_em = now();
                        $dossier->alteracoes_erp = $mudancas;
                        Log::info('Dossiê alterado no PHC.', [
                            'id_erp' => $dossierErp->idErp,
                            'obrano' => $dossier->obrano,
                            'alteracoes' => $mudancas,
                        ]);
                    }
                }

                $dossier->hash_sync = $hash;
                $dossier->synced_at = now();
                $dossier->ausente_do_erp_em = null; // veio do PHC nesta corrida
                $dossier->save();

                $novo ? $criados++ : $atualizados++;
            } catch (Throwable $e) {
                $erros++;
                Log::warning('Falha a sincronizar dossiê do ERP.', [
                    'id_erp' => $dossierErp->idErp ?? null,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        $this->conferirComOErp($vistos, $limite, $conferencia);
    }

    /**
     * Conferência: quem cá está e já não está no PHC (órfão), e quem reapareceu.
     *
     * Só faz sentido depois de ver o PHC INTEIRO: com --limit o que falta é o que não foi
     * pedido, não o que foi apagado. E se o PHC não devolveu NADA (query partida, tabela
     * vazia), marcar tudo como ausente seria um falso alarme do tamanho da tabela.
     *
     * @param  array<string, true>  $vistos
     * @param  array{orfaos: int, reencontrados: int, saltada: string|null}  $conferencia
     */
    private function conferirComOErp(array $vistos, ?int $limite, array &$conferencia): void
    {
        if ($limite !== null) {
            $conferencia['saltada'] = 'corrida parcial (--limit)';

            return;
        }

        if ($vistos === []) {
            $conferencia['saltada'] = 'o PHC não devolveu dossiês';
            Log::warning('Conferência de dossiês saltada: o PHC não devolveu nenhum dossiê.');

            return;
        }

        $marcar = [];
        $limpar = [];

        // Uma passagem pela tabela (cursor: não carrega tudo em memória).
        foreach (Dossier::select('id', 'id_erp', 'obrano', 'ausente_do_erp_em')->cursor() as $d) {
            $noErp = isset($vistos[(string) $d->id_erp]);

            if (! $noErp && $d->ausente_do_erp_em === null) {
                $marcar[] = $d->id;
                Log::info('Dossiê deixou de existir no PHC.', ['id_erp' => $d->id_erp, 'obrano' => $d->obrano]);
            } elseif ($noErp && $d->ausente_do_erp_em !== null) {
                $limpar[] = $d->id;
            }
        }

        foreach (array_chunk($marcar, 500) as $lote) {
            Dossier::whereIn('id', $lote)->update(['ausente_do_erp_em' => now()]);
        }

        foreach (array_chunk($limpar, 500) as $lote) {
            Dossier::whereIn('id', $lote)->update(['ausente_do_erp_em' => null]);
        }

        $conferencia['orfaos'] = count($marcar);
        $conferencia['reencontrados'] = count($limpar);
    }

    // Valor para o registo da alteração: datas e booleanos legíveis, o resto como texto.
    private function valorLegivel(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        if (is_bool($valor)) {
            return $valor ? 'sim' : 'não';
        }

        return (string) $valor;
    }
}
