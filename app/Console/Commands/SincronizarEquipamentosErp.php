<?php

namespace App\Console\Commands;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Services\Erp\EquipamentoErp;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

// Sincroniza os equipamentos RIELLO a partir do ERP (PHC, tabela ma): faz upsert na BD da
// aplicação, correlacionando por id_erp (= ma.mastamp — a chave com que os 16.761 já foram
// carregados). Operação read-only do lado do ERP e sempre em background (agendada — ver
// routes/console.php). Nunca apaga equipamentos por estarem ausentes do ERP.
//
// COALESCE PURO: no update só preenche campos VAZIOS; NUNCA sobrepõe o que o técnico editou
// (local, atributos, próxima troca de baterias, estado, notas, modelo, série). Diverge de
// propósito do carregamento inicial (Python), que sobrepunha.
class SincronizarEquipamentosErp extends Command
{
    protected $signature = 'erp:sincronizar-equipamentos {--limit= : Nº máximo de equipamentos a processar} {--completo : Ignora os hashes e reprocessa tudo}';

    protected $description = 'Sincroniza os equipamentos Riello a partir do ERP (read-only, upsert por id_erp = mastamp, coalesce).';

    // Local de aterragem por cliente — o ERP não tem "local". Reutiliza o mesmo local com que
    // os 16.761 já foram carregados (não criar um paralelo, senão quebra a idempotência).
    private const DESIGNACAO_LOCAL = 'Instalação principal';

    public function handle(ErpSyncDriver $erp): int
    {
        $driver = config('erp.driver') ?: '(inativo)';
        $limite = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info("A sincronizar equipamentos Riello a partir do ERP (driver: {$driver})...");

        $criados = 0;
        $atualizados = 0;
        $iguais = 0;
        $semCliente = 0;
        $erros = 0;

        try {
            $this->sincronizar($erp, $limite, $criados, $atualizados, $iguais, $semCliente, $erros);
        } catch (Throwable $e) {
            // Falha de LIGAÇÃO/timeout (PHC em baixo) → loga e devolve FAILURE, sem rebentar
            // com exceção não tratada (não parte o scheduler nem os outros syncs).
            $this->error('Sync de equipamentos FALHOU: '.$e->getMessage());
            Log::error('Sync de equipamentos do ERP falhou.', ['driver' => $driver, 'erro' => $e->getMessage()]);

            return self::FAILURE;
        }

        $resumo = "{$criados} criados, {$atualizados} atualizados, {$iguais} iguais (saltados), {$semCliente} sem cliente (por associar), {$erros} erros.";
        $this->info("Sincronização concluída: {$resumo}");

        // Auditoria do sync (CLAUDE.md §11).
        Log::info('Sync de equipamentos do ERP concluído.', [
            'driver' => $driver,
            'limite' => $limite,
            'criados' => $criados,
            'atualizados' => $atualizados,
            'iguais' => $iguais,
            'sem_cliente' => $semCliente,
            'erros' => $erros,
        ]);

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }

    // Percorre o ERP e faz upsert INCREMENTAL: o hash dos dados do ERP da última corrida vive
    // em equipamentos.hash_sync; hash igual → equipamento saltado sem nenhuma query. Exceção:
    // equipamentos ainda SEM LOCAL (sem cliente) são sempre reprocessados — quando o cliente
    // aparecer na app (sync de clientes, correção no PHC), ficam associados. Erros por linha
    // são contados (não param o sync); uma falha de ligação propaga para o handle() → FAILURE.
    private function sincronizar(ErpSyncDriver $erp, ?int $limite, int &$criados, int &$atualizados, int &$iguais, int &$semCliente, int &$erros): void
    {
        $forcarTudo = (bool) $this->option('completo');

        // Hashes da última corrida (withTrashed: apagados também têm id_erp e contam como
        // "vistos" — sem isto, um apagado nunca casava e era reprocessado para sempre).
        $hashes = Equipamento::withTrashed()->whereNotNull('id_erp')->pluck('hash_sync', 'id_erp')
            ->mapWithKeys(fn ($h, $k) => [(string) $k => $h])
            ->all();

        // id_erp dos equipamentos ainda sem local ("por associar"): furam o salto por hash,
        // para apanharem o cliente assim que ele existir na app.
        $semLocal = Equipamento::withTrashed()->whereNotNull('id_erp')->whereNull('local_id')
            ->pluck('id_erp')->mapWithKeys(fn ($v) => [(string) $v => true])->all();

        // Mapa id_erp → cliente_id (uma leitura, em vez de uma query por equipamento).
        $clientePorErp = Cliente::whereNotNull('id_erp')->pluck('id', 'id_erp')
            ->mapWithKeys(fn ($id, $idErp) => [(string) $idErp => $id])
            ->all();

        $localPorCliente = []; // cache cliente_id → local_id (firstOrCreate à medida)

        foreach ($erp->obterEquipamentos($limite) as $equipErp) {
            try {
                $hash = md5((string) json_encode([
                    $equipErp->numeroSerie, $equipErp->modelo, $equipErp->dataInstalacao,
                    $equipErp->clienteNo, $equipErp->marca, $equipErp->familia, $equipErp->faminome,
                    $equipErp->criadoEm,
                ], JSON_INVALID_UTF8_SUBSTITUTE));

                // Nada mudou no ERP desde a última corrida → salta (zero queries) — exceto os
                // "por associar", que voltam a tentar encontrar o cliente.
                if (! $forcarTudo && ($hashes[$equipErp->idErp] ?? null) === $hash && ! isset($semLocal[$equipErp->idErp])) {
                    $iguais++;

                    continue;
                }

                $clienteId = $clientePorErp[$equipErp->clienteNo] ?? null;

                // Sem cliente (fatura do PHC sem o nº associado, ou cliente ainda não sincronizado):
                // o equipamento ENTRA na mesma, com local a null ("por associar") — a pesquisa por
                // série encontra-o e a equipa associa o cliente na ficha quando souber de quem é.
                $localId = null;
                if ($clienteId !== null) {
                    $localId = $localPorCliente[$clienteId]
                        ??= Local::firstOrCreate(
                            ['cliente_id' => $clienteId, 'designacao' => self::DESIGNACAO_LOCAL],
                        )->id;
                } else {
                    $semCliente++;
                }

                $novo = $this->upsert($equipErp, $localId, $hash);
                $novo ? $criados++ : $atualizados++;
            } catch (Throwable $e) {
                $erros++;
                Log::warning('Falha a sincronizar equipamento do ERP.', [
                    'id_erp' => $equipErp->idErp ?? null,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }

    // Upsert por id_erp (= mastamp). Devolve true se criou, false se atualizou.
    // withTrashed: correlaciona também com equipamentos apagados (o id_erp é único na BD, incluindo
    // apagados) — evita violação de chave e NÃO ressuscita o que o técnico apagou.
    // $localId null = sem cliente conhecido ("por associar").
    private function upsert(EquipamentoErp $equipErp, ?int $localId, string $hash): bool
    {
        $equip = Equipamento::withTrashed()->firstOrNew(['id_erp' => $equipErp->idErp]);
        // Regista a impressão digital desta corrida — é o que permite saltar na próxima.
        $equip->hash_sync = $hash;

        if (! $equip->exists) {
            // CRIAÇÃO: preenche tudo o que o ERP fornece + os fixos (Riello/UPS/operacional).
            $equip->fill([
                'local_id' => $localId,
                'tipo' => TipoEquipamento::Ups,
                'fabricante' => 'Riello',
                'modelo' => $equipErp->modelo,
                'numero_serie' => $equipErp->numeroSerie,
                'data_instalacao' => $equipErp->dataInstalacao,
                'estado' => EstadoEquipamento::Operacional,
                'familia' => $equipErp->familia,
                'faminome' => $equipErp->faminome,
                'qr_code' => $equipErp->idErp,
                'criado_erp_em' => $equipErp->criadoEm,
            ]);
            $equip->save();

            return true;
        }

        // ATUALIZAÇÃO — COALESCE PURO: só preenche o que está VAZIO; nunca sobrepõe.
        // local_id, atributos, proxima_troca_baterias, estado e notas são intocáveis (edições do
        // técnico) — só entram se, por algum motivo, estiverem vazios.
        // Um "por associar" (local null) fica associado assim que o cliente existir na app —
        // nunca sobrepõe um local escolhido pelo técnico.
        if (blank($equip->local_id) && $localId !== null) {
            $equip->local_id = $localId;
        }
        if (blank($equip->modelo)) {
            $equip->modelo = $equipErp->modelo;
        }
        if (blank($equip->numero_serie)) {
            $equip->numero_serie = $equipErp->numeroSerie;
        }
        if (blank($equip->data_instalacao)) {
            $equip->data_instalacao = $equipErp->dataInstalacao;
        }
        if (blank($equip->fabricante)) {
            $equip->fabricante = 'Riello';
        }
        if (blank($equip->tipo)) {
            $equip->tipo = TipoEquipamento::Ups;
        }

        // Família e data de criação no PHC são do ERP (read-only na app) → mantêm-se SEMPRE
        // alinhadas com o PHC (não é coalesce). É isto que as preenche nos equipamentos que
        // já existiam, na primeira corrida após cada migração.
        $equip->familia = $equipErp->familia;
        $equip->faminome = $equipErp->faminome;
        $equip->criado_erp_em = $equipErp->criadoEm;

        // Só grava se algum campo vazio foi preenchido (evita updated_at à toa).
        if ($equip->isDirty()) {
            $equip->save();
        }

        return false;
    }
}
