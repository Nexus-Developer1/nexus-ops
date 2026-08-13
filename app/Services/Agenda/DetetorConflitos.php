<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Models\EventoAgenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Deteta conflitos ao agendar/reagendar um evento (CLAUDE.md §6): fora de horário
// de cobertura ou sobreposição com outro evento do técnico.
class DetetorConflitos
{
    // Serializa o agendamento por técnico (advisory lock do Postgres, âmbito da transação):
    // sem isto, dois utilizadores a gravar em simultâneo passavam ambos na verificação de
    // conflito ("verifica → grava") e o técnico ficava com double-booking. Chamar DENTRO de
    // uma DB::transaction, ANTES de verificar conflitos. Chaves ordenadas (evita deadlock
    // quando dois eventos partilham técnicos por ordens diferentes).
    /** @param list<int|string> $chaves ids de conta e/ou nomes legados */
    public function travarAgendaDe(array $chaves): void
    {
        $chaves = array_values(array_unique(array_map(strval(...), $chaves)));
        sort($chaves);

        foreach ($chaves as $chave) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['agenda:tecnico:'.$chave]);
        }
    }

    // Evento fora do horário comercial / fim-de-semana? (independente de técnico).
    // Só se aplica a eventos DENTRO do mesmo dia — o horário de cobertura serve para o
    // planeamento normal. Um evento que atravessa dias é registo de trabalho REAL
    // (deslocações longas, intervenções de noite/fim-de-semana): regulariza-se como foi,
    // sem ser bloqueado. A deteção de conflitos/double-booking aplica-se sempre.
    public function foraDeHorario(Carbon $inicio, Carbon $fim): ?string
    {
        if (! $inicio->isSameDay($fim)) {
            return null;
        }

        $abertura = (int) config('agenda.hora_abertura');
        $fecho = (int) config('agenda.hora_fecho');
        $diasUteis = (array) config('agenda.dias_uteis');

        if (! in_array($inicio->dayOfWeekIso, $diasUteis, true)) {
            return 'Fora do horário de cobertura (fim-de-semana).';
        }

        // O fim pode coincidir com a hora de fecho (ex.: termina às 19:00).
        if ($inicio->hour < $abertura || $fim->copy()->subSecond()->hour >= $fecho) {
            return "Fora do horário de cobertura ({$abertura}h–{$fecho}h).";
        }

        return null;
    }

    // Devolve a razão do conflito (texto legível) ou null se não houver conflito.
    // $segmentos: intervalos de trabalho REAIS do evento a gravar (eventos multi-dia com
    // horas por dia) — sem eles, usa [inicio, fim] contínuo. A comparação é sempre
    // segmento-a-segmento dos dois lados: as noites entre dias de um serviço longo NÃO
    // bloqueiam (nem são bloqueadas por) outros eventos.
    /** @param list<array{0: Carbon, 1: Carbon}>|null $segmentos */
    public function conflito(int $tecnicoId, Carbon $inicio, Carbon $fim, ?int $excetoEventoId = null, ?array $segmentos = null): ?string
    {
        $candidatos = EventoAgenda::query()
            ->where('tecnico_id', $tecnicoId)
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->when($excetoEventoId, fn ($q) => $q->where('id', '!=', $excetoEventoId))
            ->where('inicio', '<', $fim)
            ->where('fim', '>', $inicio)
            ->get();

        return $this->sobreposicaoPorSegmentos($candidatos, $segmentos ?: [[$inicio, $fim]]);
    }

    // Sobreposição para um técnico em TEXTO LIVRE (sem conta): deteta o double-booking com
    // outro evento do mesmo nome.
    /** @param list<array{0: Carbon, 1: Carbon}>|null $segmentos */
    public function conflitoPorNome(string $nome, Carbon $inicio, Carbon $fim, ?int $excetoEventoId = null, ?array $segmentos = null): ?string
    {
        $candidatos = EventoAgenda::query()
            ->where('tecnico_nome', $nome)
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->when($excetoEventoId, fn ($q) => $q->where('id', '!=', $excetoEventoId))
            ->where('inicio', '<', $fim)
            ->where('fim', '>', $inicio)
            ->get();

        return $this->sobreposicaoPorSegmentos($candidatos, $segmentos ?: [[$inicio, $fim]]);
    }

    // Compara os segmentos reais dos candidatos (span sobreposto, pré-filtrado em SQL) com os
    // segmentos do evento a gravar. Candidatos são poucos — a comparação fina é em PHP.
    /** @param list<array{0: Carbon, 1: Carbon}> $meus */
    private function sobreposicaoPorSegmentos(Collection $candidatos, array $meus): ?string
    {
        foreach ($candidatos as $outro) {
            foreach ($outro->segmentos() as [$outroIni, $outroFim]) {
                foreach ($meus as [$meuIni, $meuFim]) {
                    if ($meuIni->lt($outroFim) && $meuFim->gt($outroIni)) {
                        return 'O técnico já tem "'.$outro->titulo.'" neste horário.';
                    }
                }
            }
        }

        return null;
    }
}
