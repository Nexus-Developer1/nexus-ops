<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Models\EventoAgenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Deteta conflitos ao agendar/reagendar um evento (CLAUDE.md §6): sobreposição com outro
// evento do mesmo técnico. NÃO há horário de cobertura nem dias úteis — os técnicos não têm
// horário fixo (trabalho noturno, fim-de-semana e serviços que atravessam dias são normais);
// a regra "fora de horário" foi retirada a pedido da equipa (2026-08-29).
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
