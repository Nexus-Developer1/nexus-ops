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
            ...$this->backupEmAtraso(),
            ...$this->baterias(),
            ...$this->renovacoes(),
            ...$this->visitasProgramadas(),
            ...$this->manutencoesProgramadas(),
            ...$this->visitasEmAtraso(),
            ...$this->slaEmRisco(),
        ])->sortByDesc(fn ($a) => $a['severidade'] === 'alta' ? 1 : 0)->values();
    }

    // Vigia de backups (opt-in por config): o scripts/backup.sh toca um marcador no fim de
    // cada backup BEM SUCEDIDO; marcador em falta ou velho = o backup deixou de correr — e
    // um backup morto só se descobre no dia em que faz falta. Alerta sempre ALTA.
    private function backupEmAtraso(): array
    {
        if (! config('alertas.backup_vigia')) {
            return [];
        }

        $marcador = storage_path('app/backups/.ultimo-backup');
        $maxHoras = (int) config('alertas.backup_max_horas');
        // (createFromTimestamp → now(): positivo; o diffInHours do Carbon 3 é COM SINAL —
        // na ordem inversa a idade vinha negativa e a vigia nunca alertava.)
        $idade = is_file($marcador)
            ? (int) \Illuminate\Support\Carbon::createFromTimestamp(filemtime($marcador))->diffInHours(now())
            : null;

        if ($idade !== null && $idade <= $maxHoras) {
            return []; // backup em dia
        }

        return [[
            'tipo' => 'backup',
            'severidade' => 'alta',
            'titulo' => 'Backup em atraso',
            'descricao' => $idade === null
                ? 'Nunca foi registado nenhum backup (marcador em falta) — verificar o cron do backup no servidor.'
                : "O último backup bem sucedido foi há {$idade}h (máx. {$maxHoras}h) — verificar o cron do backup no servidor.",
            'url' => route('alertas'),
            'data' => now(),
        ]];
    }

    // Alertas de manutenção PROGRAMADOS no equipamento (data + texto editável): mesma
    // mecânica dos alertas de visita do contrato — 7 dias antes (média), alta ao vencer.
    private function manutencoesProgramadas(): array
    {
        return \App\Models\EquipamentoAlertaManutencao::query()
            ->whereDate('data', '<=', now()->addDays(7))
            ->with('equipamento.local.cliente')
            ->orderBy('data')
            ->get()
            ->map(fn (\App\Models\EquipamentoAlertaManutencao $a) => [
                'tipo' => 'manutencao_programada',
                'severidade' => $a->data->isPast() || $a->data->isToday() ? 'alta' : 'media',
                'titulo' => $a->texto . ' · ' . (trim(($a->equipamento->fabricante ?? '') . ' ' . ($a->equipamento->modelo ?? '')) ?: ($a->equipamento->numero_serie ?? '—')),
                'descricao' => ($a->equipamento->local?->cliente?->nome ?? '—') . ' · programado para ' . $a->data->translatedFormat('d M Y'),
                'url' => route('equipamentos.ficha', $a->equipamento_id),
                'data' => $a->data,
            ])
            ->all();
    }

    // Alertas de visita PROGRAMADOS no contrato (data + texto editável): aparecem a partir
    // de 7 dias antes da data (média) e passam a ALTA quando a data chega/passa. Saem quando
    // a linha é removida na edição do contrato (depois de a visita ficar agendada).
    private function visitasProgramadas(): array
    {
        return \App\Models\ContratoAlertaVisita::query()
            ->whereDate('data', '<=', now()->addDays(7))
            ->with('contrato.cliente')
            ->orderBy('data')
            ->get()
            ->map(fn (\App\Models\ContratoAlertaVisita $a) => [
                'tipo' => 'visita_programada',
                'severidade' => $a->data->isPast() || $a->data->isToday() ? 'alta' : 'media',
                'titulo' => $a->texto . ' · ' . ($a->contrato->numero ?? '—'),
                'descricao' => ($a->contrato->cliente->nome ?? '—') . ' · programado para ' . $a->data->translatedFormat('d M Y'),
                'url' => route('contratos.editar', $a->contrato_id),
                'data' => $a->data,
            ])
            ->all();
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

    // Intervenções corretivas abertas medidas contra o SLA do contrato — RESPOSTA (relógio:
    // pedido_em → início do trabalho) e RESOLUÇÃO (início → agora). Vaga 2: o alerta passa a
    // ANTECIPAR — média a partir de 75% do prazo, alta quando estoura (antes só disparava
    // depois de falhado, quando já não havia nada a fazer).
    private function slaEmRisco(): array
    {
        return Intervencao::query()
            ->where('tipo', 'corretiva')
            ->where('estado', '!=', 'concluida')
            ->whereNotNull('contrato_id')
            ->with(['contrato.slas', 'equipamento.local.cliente'])
            ->get()
            ->filter(fn (Intervencao $i) => $i->contrato && $i->contrato->slas->isNotEmpty())
            ->flatMap(function (Intervencao $i) {
                $alertas = [];
                $cliente = $i->equipamento->local?->cliente?->nome ?? '—';

                // RESPOSTA: só enquanto o trabalho não começou (data_inicio marca a resposta).
                // NBD medido como o fim do dia útil seguinte ao pedido.
                $slaResposta = $i->contrato->slas
                    ->first(fn ($s) => $s->tempo_resposta_horas !== null || $s->resposta_nbd);
                if ($i->pedido_em && ! $i->data_inicio && $slaResposta) {
                    $prazo = $slaResposta->resposta_nbd
                        ? $i->pedido_em->copy()->addWeekday()->endOfDay()
                        : $i->pedido_em->copy()->addHours((int) $slaResposta->tempo_resposta_horas);
                    if ($a = $this->alertaDePrazo($i, 'resposta', $i->pedido_em, $prazo, $cliente)) {
                        $alertas[] = $a;
                    }
                }

                // RESOLUÇÃO: do início do trabalho até agora, contra o menor tempo do contrato.
                $horas = $i->contrato->slas->whereNotNull('tempo_resolucao_horas')->min('tempo_resolucao_horas');
                if ($i->data_inicio && $horas) {
                    $prazo = $i->data_inicio->copy()->addHours((int) $horas);
                    if ($a = $this->alertaDePrazo($i, 'resolução', $i->data_inicio, $prazo, $cliente)) {
                        $alertas[] = $a;
                    }
                }

                return $alertas;
            })
            ->values()
            ->all();
    }

    // Alerta de um prazo de SLA com antecipação: <75% decorrido → nada; 75-100% → média
    // ("a esgotar-se"); >100% → alta ("excedido").
    private function alertaDePrazo(Intervencao $i, string $fase, \Illuminate\Support\Carbon $inicio, \Illuminate\Support\Carbon $prazo, string $cliente): ?array
    {
        $total = max(1, $inicio->diffInSeconds($prazo));
        $decorrido = $inicio->diffInSeconds(now());
        $fracao = $decorrido / $total;

        if ($fracao < 0.75) {
            return null;
        }

        $estourou = $fracao >= 1;

        return [
            'tipo' => 'sla',
            'severidade' => $estourou ? 'alta' : 'media',
            'titulo' => 'SLA de ' . $fase . ($estourou ? ' excedido' : ' a esgotar-se') . ' · intervenção #' . $i->id,
            'descricao' => $cliente . ' · prazo ' . $prazo->translatedFormat('d M H:i') . ($estourou ? ' (excedido)' : ' (' . (int) round($fracao * 100) . '% decorrido)'),
            'url' => route('intervencoes.formulario', $i),
            'data' => $prazo,
        ];
    }
}
