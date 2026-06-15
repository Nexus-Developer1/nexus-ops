<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\EstadoIntervencao;
use App\Enums\TipoEvento;
use App\Enums\TipoIntervencao;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use Illuminate\Support\Facades\DB;

// Converte um evento da agenda numa intervenção quando o técnico inicia a visita.
// Regra de ouro (CLAUDE.md §6): evento e intervenção partilham os mesmos factos e
// ficam ligados por evento_agenda_id ↔ intervencao_id — fonte única, nunca duplicar.
class ConversorVisita
{
    // Inicia a visita: cria (ou devolve) a intervenção ligada ao evento e marca
    // o evento como "em curso". Idempotente — chamar duas vezes não duplica.
    public function iniciar(EventoAgenda $evento, ?int $tecnicoId = null): Intervencao
    {
        return DB::transaction(function () use ($evento, $tecnicoId) {
            // Já convertido → devolve a intervenção existente.
            if ($evento->intervencao_id) {
                return $evento->intervencao()->firstOrFail();
            }

            $intervencao = Intervencao::create([
                'equipamento_id' => $evento->equipamento_id,
                'contrato_id' => $evento->contrato_id,
                'evento_agenda_id' => $evento->id,
                'tipo' => $this->tipoIntervencao($evento->tipo),
                'estado' => EstadoIntervencao::EmCurso,
                'tecnico_id' => $tecnicoId ?? $evento->tecnico_id,
                'data_inicio' => now(),
            ]);

            $evento->update([
                'intervencao_id' => $intervencao->id,
                'estado' => EstadoEvento::EmCurso,
            ]);

            return $intervencao;
        });
    }

    private function tipoIntervencao(TipoEvento $tipo): TipoIntervencao
    {
        return match ($tipo) {
            TipoEvento::VisitaPreventiva => TipoIntervencao::Preventiva,
            default => TipoIntervencao::Corretiva,
        };
    }
}
