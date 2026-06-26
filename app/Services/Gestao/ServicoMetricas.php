<?php

namespace App\Services\Gestao;

use App\Enums\EstadoContrato;
use App\Enums\EstadoEvento;
use App\Enums\TipoEvento;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use Illuminate\Support\Collection;

// Métricas de gestão para o dashboard (CLAUDE.md §6): rentabilidade (visitas
// realizadas vs. orçamentadas), cumprimento de SLA, renovações próximas e
// equipamentos sem visitas recentes.
class ServicoMetricas
{
    private const MESES_SEM_VISITA = 6;

    /** @return array<string, mixed> */
    public function resumo(): array
    {
        return [
            'contratos_ativos' => Contrato::where('estado', EstadoContrato::Ativo->value)->count(),
            'equipamentos' => Equipamento::count(),
            'visitas' => $this->rentabilidadeVisitas(),
            'cumprimento_sla' => $this->cumprimentoSla(),
            'renovacoes' => Contrato::aExpirar()->count(),
        ];
    }

    // Visitas preventivas do ano: realizadas (concluídas) vs. orçamentadas (planeadas).
    /** @return array{realizadas:int, orcamentadas:int, taxa:int} */
    public function rentabilidadeVisitas(): array
    {
        $base = EventoAgenda::query()
            ->where('tipo', TipoEvento::VisitaPreventiva->value)
            ->whereYear('inicio', now()->year);

        $orcamentadas = (clone $base)->count();
        $realizadas = (clone $base)->where('estado', EstadoEvento::Concluido->value)->count();

        return [
            'realizadas' => $realizadas,
            'orcamentadas' => $orcamentadas,
            'taxa' => $orcamentadas > 0 ? (int) round($realizadas / $orcamentadas * 100) : 0,
        ];
    }

    // % de intervenções corretivas concluídas dentro do tempo de resolução do SLA.
    /** @return array{dentro:int, total:int, taxa:int|null} */
    public function cumprimentoSla(): array
    {
        $corretivas = Intervencao::query()
            ->where('tipo', 'corretiva')
            ->where('estado', 'concluida')
            ->whereNotNull('data_inicio')
            ->whereNotNull('data_fim')
            ->whereNotNull('contrato_id')
            ->with('contrato.slas')
            ->get()
            ->filter(fn (Intervencao $i) => $i->contrato && $i->contrato->slas->whereNotNull('tempo_resolucao_horas')->isNotEmpty());

        $total = $corretivas->count();

        if ($total === 0) {
            return ['dentro' => 0, 'total' => 0, 'taxa' => null];
        }

        $dentro = $corretivas->filter(function (Intervencao $i) {
            $horas = $i->contrato->slas->whereNotNull('tempo_resolucao_horas')->min('tempo_resolucao_horas');

            return $i->data_inicio->diffInHours($i->data_fim) <= $horas;
        })->count();

        return ['dentro' => $dentro, 'total' => $total, 'taxa' => (int) round($dentro / $total * 100)];
    }

    // Contratos ativos a expirar (lista, para o painel de renovações).
    public function renovacoesProximas(int $limite = 5): Collection
    {
        return Contrato::aExpirar()->with('cliente')->orderBy('data_fim')->limit($limite)->get();
    }

    // Distribuição de equipamentos por tipo (UPS/Gerador/PDU). [tipo => total]
    /** @return array<string, int> */
    public function equipamentosPorTipo(): array
    {
        return Equipamento::query()
            ->selectRaw('tipo, count(*) as total')
            ->groupBy('tipo')
            ->orderByDesc('total')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->tipo->value => (int) $r->total])
            ->all();
    }

    // Distribuição de equipamentos por estado (operacional/degradado/...). [estado => total]
    /** @return array<string, int> */
    public function equipamentosPorEstado(): array
    {
        return Equipamento::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->orderByDesc('total')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->estado->value => (int) $r->total])
            ->all();
    }

    // Visitas preventivas do ano por mês: planeadas (todas) vs realizadas (concluídas).
    /** @return array{planeadas: list<int>, realizadas: list<int>} */
    public function visitasPorMes(): array
    {
        $base = EventoAgenda::query()
            ->where('tipo', TipoEvento::VisitaPreventiva->value)
            ->whereYear('inicio', now()->year);

        $contar = fn ($query) => $query
            ->selectRaw('extract(month from inicio)::int as mes, count(*) as total')
            ->groupBy('mes')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->mes => (int) $r->total]);

        $planeadasMes = $contar(clone $base);
        $realizadasMes = $contar((clone $base)->where('estado', EstadoEvento::Concluido->value));

        $planeadas = [];
        $realizadas = [];
        for ($m = 1; $m <= 12; $m++) {
            $planeadas[] = $planeadasMes[$m] ?? 0;
            $realizadas[] = $realizadasMes[$m] ?? 0;
        }

        return ['planeadas' => $planeadas, 'realizadas' => $realizadas];
    }

    // Equipamentos sem intervenção nos últimos N meses (manutenção em falta).
    public function equipamentosSemVisitas(int $limite = 5): Collection
    {
        return Equipamento::query()
            ->whereDoesntHave('intervencoes', fn ($q) => $q->where('data_inicio', '>=', now()->subMonths(self::MESES_SEM_VISITA)))
            ->with('local.cliente')
            ->orderBy('id')
            ->limit($limite)
            ->get();
    }
}
