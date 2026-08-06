<?php

namespace App\Services\Alertas;

use App\Enums\EstadoEvento;
use App\Enums\TipoEquipamento;
use App\Enums\TipoEvento;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use Illuminate\Support\Collection;

// Recolhe os alertas proativos da operação (CLAUDE.md §6/§9): baterias a vencer,
// renovações de contrato, visitas planeadas em atraso e SLA em risco.
// Cada alerta é um array: tipo, severidade (alta|media), titulo, descricao, url, data.
class ServicoAlertas
{
    public function recolher(): Collection
    {
        return collect([
            ...$this->baterias(),
            ...$this->renovacoes(),
            ...$this->visitasEmAtraso(),
            ...$this->slaEmRisco(),
        ])->sortByDesc(fn ($a) => $a['severidade'] === 'alta' ? 1 : 0)->values();
    }

    // UPS cuja próxima troca de baterias está a aproximar-se ou já passou.
    private function baterias(): array
    {
        $limite = now()->addDays((int) config('alertas.bateria_aviso_dias'));

        return Equipamento::query()
            ->where('tipo', TipoEquipamento::Ups->value)
            ->whereNotNull('proxima_troca_baterias')
            ->whereDate('proxima_troca_baterias', '<=', $limite)
            ->with('local.cliente')
            ->get()
            ->map(function (Equipamento $e) {
                $vencida = $e->proxima_troca_baterias->isPast();

                return [
                    'tipo' => 'bateria',
                    'severidade' => $vencida ? 'alta' : 'media',
                    'titulo' => 'Baterias ' . ($vencida ? 'vencidas' : 'a vencer') . ' · ' . trim($e->fabricante . ' ' . $e->modelo),
                    'descricao' => ($e->local?->cliente?->nome ?? '—') . ' · troca prevista ' . $e->proxima_troca_baterias->translatedFormat('d M Y'),
                    'url' => route('equipamentos.ficha', $e),
                    'data' => $e->proxima_troca_baterias,
                ];
            })
            ->all();
    }

    // Contratos ativos dentro da própria janela de aviso de renovação.
    private function renovacoes(): array
    {
        $criticoDias = (int) config('alertas.renovacao_critica_dias');

        return Contrato::aExpirar()
            ->with('cliente')
            ->get()
            ->map(function (Contrato $c) use ($criticoDias) {
                $dias = $c->diasParaFim();

                return [
                    'tipo' => 'renovacao',
                    'severidade' => $dias <= $criticoDias ? 'alta' : 'media',
                    'titulo' => 'Contrato a expirar · ' . $c->numero,
                    'descricao' => $c->cliente->nome . ' · termina ' . $c->data_fim->translatedFormat('d M Y') . " (em {$dias} dias)",
                    'url' => route('contratos.ficha', $c),
                    'data' => $c->data_fim,
                ];
            })
            ->all();
    }

    // Visitas preventivas planeadas/confirmadas cuja data já passou (não realizadas).
    private function visitasEmAtraso(): array
    {
        return EventoAgenda::query()
            ->where('tipo', TipoEvento::VisitaPreventiva->value)
            ->whereIn('estado', [EstadoEvento::Planeado->value, EstadoEvento::Confirmado->value])
            ->where('inicio', '<', now())
            ->with('cliente')
            ->orderBy('inicio')
            ->get()
            ->map(fn (EventoAgenda $e) => [
                'tipo' => 'visita_atraso',
                'severidade' => 'alta',
                'titulo' => 'Visita em atraso · ' . $e->titulo,
                'descricao' => ($e->cliente->nome ?? '—') . ' · planeada para ' . $e->inicio->translatedFormat('d M Y'),
                'url' => route('agenda'),
                'data' => $e->inicio,
            ])
            ->all();
    }

    // Intervenções corretivas abertas que excedem o tempo de resolução do SLA do contrato.
    private function slaEmRisco(): array
    {
        return Intervencao::query()
            ->where('tipo', 'corretiva')
            ->where('estado', '!=', 'concluida')
            ->whereNotNull('contrato_id')
            ->whereNotNull('data_inicio')
            ->with(['contrato.slas', 'equipamento.local.cliente'])
            ->get()
            ->filter(fn (Intervencao $i) => $i->contrato && $i->contrato->slas->isNotEmpty())
            ->map(function (Intervencao $i) {
                // Usa o SLA mais exigente do contrato (menor tempo de resolução).
                $horas = $i->contrato->slas->whereNotNull('tempo_resolucao_horas')->min('tempo_resolucao_horas');
                if (! $horas) {
                    return null;
                }

                $prazo = $i->data_inicio->copy()->addHours($horas);
                if ($prazo->isFuture()) {
                    return null;
                }

                return [
                    'tipo' => 'sla',
                    'severidade' => 'alta',
                    'titulo' => 'SLA em risco · intervenção #' . $i->id,
                    'descricao' => ($i->equipamento->local?->cliente?->nome ?? '—') . ' · prazo de resolução excedido (' . $horas . 'h)',
                    'url' => route('intervencoes.formulario', $i),
                    'data' => $prazo,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
