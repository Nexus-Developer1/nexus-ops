<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\TipoEvento;
use App\Models\Contrato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Gera as visitas preventivas planeadas de um contrato para todo o período de
// vigência, a partir dos planos de visita (CLAUDE.md §6 — coração da Fase 2).
class GeradorVisitasPreventivas
{
    // Hora por defeito de uma visita planeada (sem técnico atribuído ainda).
    private const HORA_INICIO = 9;

    private const DURACAO_PADRAO_MIN = 60;

    // Gera (idempotente) as visitas do contrato. Devolve o nº de visitas criadas.
    public function gerar(Contrato $contrato): int
    {
        $contrato->load(['planosVisita', 'equipamentos.local']);

        return DB::transaction(function () use ($contrato) {
            // Idempotência: remove visitas preventivas ainda planeadas/confirmadas
            // (não toca em visitas já em curso/concluídas) antes de regerar.
            $contrato->eventos()
                ->where('tipo', TipoEvento::VisitaPreventiva)
                ->whereIn('estado', [EstadoEvento::Planeado->value, EstadoEvento::Confirmado->value])
                ->delete();

            $criadas = 0;
            [$horaInicio, $minutoInicio] = $this->horaInicio($contrato);

            foreach ($contrato->planosVisita as $plano) {
                $intervalo = $plano->periodicidade->meses();
                $duracao = $plano->duracao_estimada_min ?: self::DURACAO_PADRAO_MIN;

                // ATENÇÃO: a `recorrencia` (RRULE) é guardada apenas como METADADO/rastreio
                // (ex.: futura interop iCal). NÃO existe motor de RRULE — nada lê esta coluna
                // hoje. A recorrência REAL é materializada pelo loop abaixo, que cria uma linha
                // (evento) por ocorrência. Ver também a nota em EventoAgenda::casts().
                $rrule = 'FREQ=MONTHLY;INTERVAL=' . $intervalo;

                $equipamentos = $contrato->equipamentos
                    ->where('tipo', $plano->equipamento_tipo);

                foreach ($equipamentos as $equipamento) {
                    $momento = Carbon::parse($contrato->data_inicio)->setTime($horaInicio, $minutoInicio);
                    $fimVigencia = Carbon::parse($contrato->data_fim)->endOfDay();

                    while ($momento->lte($fimVigencia)) {
                        $contrato->eventos()->create([
                            'tipo' => TipoEvento::VisitaPreventiva,
                            'titulo' => 'Preventiva · ' . trim($equipamento->fabricante . ' ' . $equipamento->modelo),
                            'inicio' => $momento->copy(),
                            'fim' => $momento->copy()->addMinutes($duracao),
                            'estado' => EstadoEvento::Planeado,
                            'cliente_id' => $contrato->cliente_id,
                            'local_id' => $equipamento->local_id,
                            'equipamento_id' => $equipamento->id,
                            'recorrencia' => $rrule,
                        ]);

                        $criadas++;
                        $momento->addMonths($intervalo);
                    }
                }
            }

            return $criadas;
        });
    }

    // Hora de início das visitas geradas: a hora única do contrato (`hora_visita`), ou
    // 09:00 por defeito quando não está definida (mantém o comportamento anterior).
    /** @return array{0:int, 1:int} [hora, minuto] */
    private function horaInicio(Contrato $contrato): array
    {
        if ($contrato->hora_visita) {
            $hora = Carbon::parse($contrato->hora_visita); // aceita "14:30" ou "14:30:00"

            return [$hora->hour, $hora->minute];
        }

        return [self::HORA_INICIO, 0];
    }

    // Estima quantas visitas gerar() criaria, SEM criar nada (mesma matemática de período
    // e filtro por tipo). Serve para decidir, na ativação, entre gerar na hora ou em fila.
    public function estimar(Contrato $contrato): int
    {
        $contrato->loadMissing(['planosVisita', 'equipamentos']);

        $total = 0;

        foreach ($contrato->planosVisita as $plano) {
            $nEquipamentos = $contrato->equipamentos->where('tipo', $plano->equipamento_tipo)->count();
            if ($nEquipamentos === 0) {
                continue;
            }

            $intervalo = $plano->periodicidade->meses();
            $momento = Carbon::parse($contrato->data_inicio)->setTime(self::HORA_INICIO, 0);
            $fimVigencia = Carbon::parse($contrato->data_fim)->endOfDay();

            $ocorrencias = 0;
            while ($momento->lte($fimVigencia)) {
                $ocorrencias++;
                $momento->addMonths($intervalo);
            }

            $total += $nEquipamentos * $ocorrencias;
        }

        return $total;
    }

    // Quantas visitas preventivas o contrato IMPLICA dentro de um ano civil — cálculo
    // determinístico a partir da periodicidade (não conta eventos materializados). Uma
    // ocorrência (ancorada em data_inicio, com passo periodicidade) conta se cair dentro
    // de `vigência ∩ ano`, tratando assim contratos com vigência parcial no ano.
    public function estimarNoAno(Contrato $contrato, int $ano): int
    {
        $contrato->loadMissing(['planosVisita', 'equipamentos']);

        $inicioAno = Carbon::create($ano, 1, 1)->startOfDay();
        $fimAno = Carbon::create($ano, 12, 31)->endOfDay();

        $total = 0;

        foreach ($contrato->planosVisita as $plano) {
            $nEquipamentos = $contrato->equipamentos->where('tipo', $plano->equipamento_tipo)->count();
            if ($nEquipamentos === 0) {
                continue;
            }

            $intervalo = $plano->periodicidade->meses();
            $momento = Carbon::parse($contrato->data_inicio)->setTime(self::HORA_INICIO, 0);
            // Não passa do fim da vigência NEM do fim do ano.
            $limite = Carbon::parse($contrato->data_fim)->endOfDay()->min($fimAno);

            $ocorrencias = 0;
            while ($momento->lte($limite)) {
                if ($momento->gte($inicioAno)) { // só as que caem dentro do ano corrente
                    $ocorrencias++;
                }
                $momento->addMonths($intervalo);
            }

            $total += $nEquipamentos * $ocorrencias;
        }

        return $total;
    }
}
