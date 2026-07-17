<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\TipoEvento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use Illuminate\Support\Facades\DB;

// Camada 3 da sincronização Relatórios → Agenda (sentido inverso da camada 2): quando
// uma intervenção/relatório tem data de intervenção FUTURA, garante o evento de agenda
// correspondente, ligado e com o contexto herdado.
//
// Anti-loop (cerne): cria o evento DIRETAMENTE no model (EventoAgenda::create), nunca via
// Calendario::criarEvento — por isso o gancho da camada 2 (que vive nesse método de
// componente, não num observer) nunca dispara. Liga os dois lados
// (intervencao.evento_agenda_id ⇄ evento.intervencao_id) e é idempotente.
class GeradorEventoDeRelatorio
{
    // Hora de início por defeito quando a intervenção não a tem (igual à geração de preventivas).
    private const HORA_INICIO_PADRAO = '09:00';

    private const DURACAO_PADRAO_MIN = 60;

    public function gerar(Intervencao $intervencao): ?EventoAgenda
    {
        $intervencao->loadMissing('equipamento.local');
        $equipamento = $intervencao->equipamento;

        // Sem equipamento ou sem data não há evento a sincronizar.
        if (! $equipamento || ! $intervencao->data_inicio) {
            return null;
        }

        return DB::transaction(function () use ($intervencao, $equipamento) {
            $inicio = $intervencao->data_inicio->copy()
                ->setTimeFromTimeString($intervencao->hora_inicio ?: self::HORA_INICIO_PADRAO);
            $fim = $intervencao->hora_fim
                ? $intervencao->data_inicio->copy()->setTimeFromTimeString($intervencao->hora_fim)
                : $inicio->copy()->addMinutes(self::DURACAO_PADRAO_MIN);

            $existente = $intervencao->evento_agenda_id
                ? EventoAgenda::find($intervencao->evento_agenda_id)
                : null;

            // Data não-futura: nunca cria; se já existir um evento, deixa-o como está (mantém histórico).
            if (! $inicio->isFuture()) {
                return $existente;
            }

            $dados = [
                'tipo' => TipoEvento::Intervencao,
                'titulo' => 'Intervenção · ' . (trim($equipamento->fabricante . ' ' . $equipamento->modelo)
                    ?: ($equipamento->numero_serie ?? '—')),
                'estado' => EstadoEvento::Planeado,
                'inicio' => $inicio,
                'fim' => $fim,
                'tecnico_id' => $intervencao->tecnico_id,
                'equipamento_id' => $equipamento->id,
                'local_id' => $equipamento->local_id,
                'cliente_id' => $equipamento->local?->cliente_id,
            ];

            // Já tem evento ligado → atualiza (move na agenda) em vez de duplicar.
            if ($existente) {
                $existente->update($dados);

                return $existente;
            }

            // Cria e liga os dois lados (o evento nasce já a apontar para a intervenção).
            $evento = EventoAgenda::create($dados + ['intervencao_id' => $intervencao->id]);
            $intervencao->update(['evento_agenda_id' => $evento->id]);

            return $evento;
        });
    }
}
