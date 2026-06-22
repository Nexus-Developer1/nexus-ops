<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\TipoEvento;
use App\Models\Contrato;
use App\Models\EventoAgenda;

// Deteta sobreposições POR EQUIPAMENTO entre as visitas preventivas de um contrato e
// quaisquer outros eventos não-cancelados no mesmo equipamento (incluindo de outros
// contratos / intervenções). Apenas avisa — não move nem bloqueia nada.
//
// Distinto do DetetorConflitos (que é por técnico): as preventivas nascem sem técnico,
// logo o critério relevante é o ativo. Sem N+1: uma query + sweep em memória.
class DetetorSobreposicaoEquipamento
{
    /**
     * @return list<array{quando: string, visita: string, colide_com: string, externo: bool}>
     *         Uma entrada por visita do contrato que se sobrepõe a outro evento.
     */
    public function paraContrato(Contrato $contrato): array
    {
        // Equipamentos cobertos pelas preventivas (não-canceladas) deste contrato.
        $equipamentoIds = EventoAgenda::query()
            ->where('contrato_id', $contrato->id)
            ->where('tipo', TipoEvento::VisitaPreventiva->value)
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->whereNotNull('equipamento_id')
            ->distinct()
            ->pluck('equipamento_id')
            ->all();

        if ($equipamentoIds === []) {
            return [];
        }

        // Uma só query: todos os eventos não-cancelados nesses equipamentos, ordenados.
        $eventos = EventoAgenda::query()
            ->whereIn('equipamento_id', $equipamentoIds)
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->orderBy('equipamento_id')
            ->orderBy('inicio')
            ->get(['id', 'equipamento_id', 'contrato_id', 'titulo', 'inicio', 'fim']);

        $conflitos = []; // id da visita do contrato => entrada (1 por visita)

        foreach ($eventos->groupBy('equipamento_id') as $grupo) {
            $lista = $grupo->values();
            $total = $lista->count();

            for ($i = 0; $i < $total; $i++) {
                $a = $lista[$i];
                for ($j = $i + 1; $j < $total; $j++) {
                    $b = $lista[$j];

                    // Ordenado por início: assim que b começa depois (ou quando) a acaba,
                    // nenhum b seguinte sobrepõe a → passa ao próximo a.
                    if ($b->inicio >= $a->fim) {
                        break;
                    }

                    // Sobreposição real (a->inicio < b->fim garantido pela ordenação).
                    $this->registar($conflitos, $contrato->id, $a, $b);
                    $this->registar($conflitos, $contrato->id, $b, $a);
                }
            }
        }

        return array_values($conflitos);
    }

    // Regista a visita $alvo (se for deste contrato) como sobreposta a $outro.
    private function registar(array &$conflitos, int $contratoId, EventoAgenda $alvo, EventoAgenda $outro): void
    {
        if ($alvo->contrato_id !== $contratoId || isset($conflitos[$alvo->id])) {
            return;
        }

        $conflitos[$alvo->id] = [
            'quando' => $alvo->inicio->format('Y-m-d H:i'),
            'visita' => $alvo->titulo,
            'colide_com' => $outro->titulo,
            'externo' => $outro->contrato_id !== $contratoId, // colisão com outro contrato/evento
        ];
    }
}
