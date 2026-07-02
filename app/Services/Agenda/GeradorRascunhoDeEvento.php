<?php

namespace App\Services\Agenda;

use App\Enums\EstadoIntervencao;
use App\Enums\TipoIntervencao;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Relatorio;
use Illuminate\Support\Facades\DB;

// Camada 2 da sincronização Agenda → Relatórios: a partir de um evento manual COM
// equipamento e data futura, gera um RASCUNHO de relatório ligado ao evento, com o
// contexto herdado. Caminho do RASCUNHO (não é o ConversorVisita: aqui a intervenção
// nasce "planeada" e o relatório "rascunho" sem número — o número só na finalização).
//
// É uma visita agendada → a intervenção nasce PREVENTIVA. Se o evento traz contrato, o
// rascunho nasce em MODO CONTRATO (contrato_id + equipamentos cobertos = os do contrato),
// para abrir com a ficha de medições UPS; sem contrato, fica individual (só o equipamento).
//
// Anti-loop: liga os dois lados (evento.intervencao_id ⇄ intervencao.evento_agenda_id)
// e só cria se o evento ainda não estiver convertido — idempotente.
class GeradorRascunhoDeEvento
{
    public function gerar(EventoAgenda $evento): ?Relatorio
    {
        return DB::transaction(function () use ($evento) {
            // Já ligado a uma intervenção → não recria (idempotente / anti-loop).
            if ($evento->intervencao_id) {
                return null;
            }

            // Equipamentos do contrato (se houver) — âmbito da visita. Documento de sistema →
            // sem global scopes, para trazer a cobertura completa do contrato.
            $idsContrato = $evento->contrato_id
                ? Equipamento::withoutGlobalScopes()
                    ->whereHas('contratos', fn ($q) => $q->whereKey($evento->contrato_id))
                    ->orderBy('id')
                    ->pluck('id')
                    ->all()
                : [];

            // Principal: o equipamento do evento; se não foi escolhido, o 1.º do contrato.
            $principalId = $evento->equipamento_id ?: ($idsContrato[0] ?? null);

            // Sem qualquer equipamento (nem no evento nem no contrato) não há intervenção
            // possível (equipamento_id é NOT NULL) → não gera.
            if (! $principalId) {
                return null;
            }

            $intervencao = Intervencao::create([
                'equipamento_id' => $principalId,
                'contrato_id' => $evento->contrato_id, // modo contrato quando o evento tem contrato
                'tecnico_id' => $evento->tecnico_id,
                'evento_agenda_id' => $evento->id,
                'tipo' => TipoIntervencao::Preventiva, // visita agendada, não correção
                'estado' => EstadoIntervencao::Planeada,
                'data_inicio' => $evento->inicio->toDateString(),
                'hora_inicio' => $evento->inicio->format('H:i'),
                'hora_fim' => $evento->fim->format('H:i'),
            ]);

            // Modo contrato: os equipamentos cobertos são os DO CONTRATO menos o principal,
            // para o relatório abrir com a ficha de medições por equipamento.
            if ($evento->contrato_id) {
                $cobertos = array_values(array_diff($idsContrato, [$principalId]));
                $intervencao->equipamentosCobertos()->sync($cobertos);
            }

            // Liga o outro lado da relação.
            $evento->update(['intervencao_id' => $intervencao->id]);

            // Documento em rascunho — sem número (atribuído só na finalização). Ponto único.
            return $intervencao->garantirRascunho();
        });
    }
}
