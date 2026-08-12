<?php

namespace App\Console\Commands;

use App\Models\LinhaFatura;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

// Sincroniza as linhas de faturação a partir do ERP (PHC, tabela fi): faz upsert na BD
// da aplicação, correlacionando por id_erp (fi.fistamp). Só linhas com nº de série
// (equipamentos físicos — o driver aplica o WHERE series). Operação read-only do lado
// do ERP e sempre em background (agendada — ver routes/console.php). Nunca apaga linhas
// por estarem ausentes do ERP.
class SincronizarFaturacaoErp extends Command
{
    protected $signature = 'erp:sincronizar-faturacao {--limit= : Nº máximo de linhas a processar} {--completo : Ignora os hashes e reprocessa tudo}';

    protected $description = 'Sincroniza as linhas de faturação a partir do ERP (read-only, upsert por id_erp).';

    public function handle(ErpSyncDriver $erp): int
    {
        $driver = config('erp.driver') ?: '(inativo)';
        $limite = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info("A sincronizar faturação a partir do ERP (driver: {$driver})...");

        $criados = 0;
        $atualizados = 0;
        $iguais = 0;
        $erros = 0;

        try {
            $this->sincronizar($erp, $limite, $criados, $atualizados, $iguais, $erros);
        } catch (Throwable $e) {
            // Falha de LIGAÇÃO/timeout (PHC em baixo) → loga e devolve FAILURE, sem rebentar
            // com exceção não tratada (não parte o scheduler nem os outros syncs).
            $this->error('Sync de faturação FALHOU: '.$e->getMessage());
            Log::error('Sync de faturação do ERP falhou.', ['driver' => $driver, 'erro' => $e->getMessage()]);

            return self::FAILURE;
        }

        $resumo = "{$criados} criadas, {$atualizados} atualizadas, {$iguais} iguais (saltadas), {$erros} erros.";
        $this->info("Sincronização concluída: {$resumo}");

        // Auditoria do sync (CLAUDE.md §11).
        Log::info('Sync de faturação do ERP concluído.', [
            'driver' => $driver,
            'limite' => $limite,
            'criados' => $criados,
            'atualizados' => $atualizados,
            'iguais' => $iguais,
            'erros' => $erros,
        ]);

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }

    // Percorre o ERP e faz upsert INCREMENTAL: o hash dos dados da última corrida vive em
    // linhas_fatura.hash_sync; hash igual → linha saltada sem nenhuma query. (Antes, o
    // `synced_at => now()` tornava as ~191 mil linhas "sujas" TODAS as corridas → 191 mil
    // UPDATEs ≈ 20 min; agora só as novas/alteradas tocam na BD.) Erros por linha são
    // contados (não param o sync); uma falha de ligação propaga para o handle() → FAILURE.
    private function sincronizar(ErpSyncDriver $erp, ?int $limite, int &$criados, int &$atualizados, int &$iguais, int &$erros): void
    {
        $forcarTudo = (bool) $this->option('completo');

        // Mapa id_erp → hash da última corrida (UMA query para a tabela toda).
        $hashes = LinhaFatura::whereNotNull('id_erp')->pluck('hash_sync', 'id_erp')
            ->mapWithKeys(fn ($h, $k) => [(string) $k => $h])
            ->all();

        foreach ($erp->obterLinhasFatura($limite) as $linhaErp) {
            try {
                $dados = [
                    'cliente_no' => $linhaErp->clienteNo,
                    'nmdoc' => $linhaErp->nmdoc,
                    'fno' => $linhaErp->fno,
                    'data' => $linhaErp->data,
                    'ref' => $linhaErp->ref,
                    'design' => $linhaErp->design,
                    'series' => $linhaErp->series,
                    'qtt' => $linhaErp->qtt,
                    // Valores (12/08) — ERP-owned, sempre alinhados com o PHC. Entram no
                    // hash: a 1.ª corrida após a migração reprocessa as ~191k linhas (uma vez).
                    'preco_unitario' => $linhaErp->precoUnitario,
                    'desconto' => $linhaErp->desconto,
                    'total_linha' => $linhaErp->totalLinha,
                    'total_documento' => $linhaErp->totalDocumento,
                    'total_documento_iva' => $linhaErp->totalDocumentoIva,
                    'anulada' => $linhaErp->anulada,
                ];
                $hash = md5((string) json_encode($dados, JSON_INVALID_UTF8_SUBSTITUTE));

                // Nada mudou no ERP desde a última corrida → salta (zero queries).
                if (! $forcarTudo && ($hashes[$linhaErp->idErp] ?? null) === $hash) {
                    $iguais++;

                    continue;
                }

                // Upsert por id_erp: existe → atualiza; não existe → cria.
                $linha = LinhaFatura::updateOrCreate(
                    ['id_erp' => $linhaErp->idErp],
                    $dados + ['hash_sync' => $hash, 'synced_at' => now()],
                );

                $linha->wasRecentlyCreated ? $criados++ : $atualizados++;
            } catch (Throwable $e) {
                $erros++;
                Log::warning('Falha a sincronizar linha de faturação do ERP.', [
                    'id_erp' => $linhaErp->idErp ?? null,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }
}
