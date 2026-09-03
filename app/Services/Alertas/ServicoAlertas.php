<?php

namespace App\Services\Alertas;

use App\Enums\EstadoDespesa;
use App\Enums\EstadoEvento;
use App\Enums\EstadoIntervencao;
use App\Enums\TipoEquipamento;
use App\Enums\TipoEvento;
use App\Enums\TipoIntervencao;
use App\Models\AlertaConcluido;
use App\Models\Contrato;
use App\Models\ContratoAlertaVisita;
use App\Models\Equipamento;
use App\Models\EquipamentoAlertaManutencao;
use App\Models\EventoAgenda;
use App\Models\EventoAlerta;
use App\Models\Intervencao;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Services\Auditor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Recolhe os alertas proativos da operação (CLAUDE.md §6/§9): baterias a vencer,
// renovações de contrato, visitas planeadas em atraso e SLA em risco.
// Cada alerta é um array: tipo, severidade (alta|media), titulo, descricao, url, data,
// atribuido_a (ids de utilizador; [] = equipa completa), atribuido_nome (rótulo ou null) e
// chave (identificador ESTÁVEL: tipo + entidade + data — é o que permite dar por concluído).
class ServicoAlertas
{
    // Dá um alerta como CONCLUÍDO (pedido da equipa): fica guardado pela chave e deixa de
    // aparecer no dashboard, no painel e no email — até alguém o reabrir. Se a causa mudar
    // (nova data de baterias, contrato renovado, despesa reenviada) a chave é outra e volta.
    public function concluir(string $chave, User $quem): bool
    {
        $alerta = $this->todos()->firstWhere('chave', $chave);
        if (! $alerta) {
            return false;
        }

        AlertaConcluido::updateOrCreate(['chave' => $chave], [
            'tipo' => $alerta['tipo'],
            'titulo' => mb_substr($alerta['titulo'], 0, 255),
            'descricao' => mb_substr((string) $alerta['descricao'], 0, 255),
            'url' => mb_substr((string) $alerta['url'], 0, 255),
            'concluido_por' => $quem->id,
            'concluido_em' => now(),
        ]);
        Auditor::registar('alerta_concluido', null, ['chave' => $chave, 'titulo' => $alerta['titulo']]);

        return true;
    }

    public function reabrir(string $chave): bool
    {
        $apagados = AlertaConcluido::where('chave', $chave)->delete();
        if ($apagados > 0) {
            Auditor::registar('alerta_reaberto', null, ['chave' => $chave]);
        }

        return $apagados > 0;
    }

    // Histórico dos concluídos (mais recentes primeiro), para o painel.
    public function concluidos(int $limite = 50): Collection
    {
        return AlertaConcluido::with('utilizador')->orderByDesc('concluido_em')->limit($limite)->get();
    }

    // "Os meus" alertas de um utilizador: os da equipa (sem atribuição) + os atribuídos a ele;
    // administradores contam sempre. (Todos VEEM tudo — isto é o critério do filtro "os meus"
    // do painel e dos testes, não de visibilidade.)
    /** @param array<string, mixed> $alerta */
    public static function visivelPara(array $alerta, User $utilizador): bool
    {
        return $utilizador->ehAdmin()
            || ($alerta['atribuido_a'] ?? []) === []
            || in_array($utilizador->id, $alerta['atribuido_a'] ?? [], true);
    }

    // Alertas em aberto (sem os concluídos).
    public function recolher(): Collection
    {
        $concluidas = AlertaConcluido::pluck('chave')->flip();

        return $this->todos()->reject(fn ($a) => $concluidas->has($a['chave']))->values();
    }

    // Todos os alertas calculados, incluindo os já concluídos.
    private function todos(): Collection
    {
        return collect([
            ...$this->backupEmAtraso(),
            ...$this->baterias(),
            ...$this->renovacoes(),
            ...$this->visitasProgramadas(),
            ...$this->manutencoesProgramadas(),
            ...$this->eventosProgramados(),
            ...$this->visitasEmAtraso(),
            ...$this->slaEmRisco(),
            ...$this->despesasPorAprovar(),
            ...$this->propostasDeIntervencao(),
        ])
            // Sem atribuição explícita = equipa completa.
            ->map(fn ($a) => $a + ['atribuido_a' => [], 'atribuido_nome' => null])
            ->sortByDesc(fn ($a) => $a['severidade'] === 'alta' ? 1 : 0)->values();
    }

    // Processo de validação das despesas: cada registo PENDENTE é um alerta com link para a
    // ficha (onde o aprovador aprova/rejeita). Média; alta quando espera há mais de 7 dias.
    private function despesasPorAprovar(): array
    {
        // Atribuídas aos aprovadores com conta (config despesas.aprovadores); sem nenhum → equipa.
        $aprovadores = User::where('ativo', true)
            ->whereIn(DB::raw('lower(email)'), config('despesas.aprovadores', []))
            ->orderBy('nome')->get(['id', 'nome']);

        return RegistoDespesa::query()
            ->where('estado', EstadoDespesa::Pendente->value)
            ->with(['colaborador', 'despesas'])
            ->orderBy('submetido_em')
            ->get()
            ->map(function (RegistoDespesa $r) use ($aprovadores) {
                $desde = $r->submetido_em ?? $r->created_at ?? now();
                $n = $r->despesas->count();

                return [
                    'tipo' => 'despesa_aprovacao',
                    'chave' => 'despesa_aprovacao:'.$r->id.':'.$desde->timestamp,
                    'severidade' => $desde->lt(now()->subDays(7)) ? 'alta' : 'media',
                    'titulo' => 'Despesa por aprovar · '.($r->colaborador?->nome ?? '—').' · '.number_format((float) $r->despesas->sum('valor'), 2, ',', ' ').' €',
                    'descricao' => $n.' '.($n === 1 ? 'lançamento' : 'lançamentos').' · submetida a '.$desde->translatedFormat('d M Y'),
                    'url' => route('despesas.registo.ficha', $r),
                    'data' => $desde,
                    'atribuido_a' => $aprovadores->pluck('id')->all(),
                    'atribuido_nome' => $aprovadores->pluck('nome')->implode(', ') ?: null,
                ];
            })
            ->all();
    }

    // Proposta de nova intervenção (pedido da equipa): um equipamento instalado por nós ou já
    // sujeito a manutenção preventiva avisa `alertas.proposta_meses` (10) meses depois da
    // ÚLTIMA instalação/preventiva concluída — para propor ao cliente nova intervenção. Conta
    // tanto o equipamento principal da intervenção como os "também cobertos". Fica em aberto
    // até ser concluído ou até haver nova intervenção (a chave leva a data da última).
    private function propostasDeIntervencao(): array
    {
        $meses = max(1, (int) config('alertas.proposta_meses', 10));
        $limite = now()->subMonths($meses);

        // Só o scope do portal sai (é um cálculo de sistema, sem utilizador); o soft-delete
        // fica — uma intervenção apagada não pode contar como "última preventiva".
        $intervencoes = Intervencao::query()
            ->withoutGlobalScope('cliente')
            ->whereIn('tipo', [TipoIntervencao::Preventiva->value, TipoIntervencao::Instalacao->value])
            ->where('estado', EstadoIntervencao::Concluida->value)
            ->with('equipamentosCobertos:id')
            ->get(['id', 'equipamento_id', 'tipo', 'data_inicio', 'data_fim', 'created_at']);

        // Última instalação/preventiva por equipamento (principal OU coberto).
        $ultimas = [];
        foreach ($intervencoes as $i) {
            $quando = $i->data_fim ?? $i->data_inicio ?? $i->created_at;
            $ids = array_unique(array_filter(array_merge([$i->equipamento_id], $i->equipamentosCobertos->pluck('id')->all())));
            foreach ($ids as $eqId) {
                if (! isset($ultimas[$eqId]) || $quando->gt($ultimas[$eqId]['quando'])) {
                    $ultimas[$eqId] = ['quando' => $quando, 'tipo' => $i->tipo];
                }
            }
        }
        $vencidos = array_filter($ultimas, fn ($u) => $u['quando']->lte($limite));
        if ($vencidos === []) {
            return [];
        }

        return Equipamento::query()
            ->whereIn('id', array_keys($vencidos))
            ->with('local.cliente')
            ->get()
            ->map(function (Equipamento $e) use ($vencidos, $meses) {
                $u = $vencidos[$e->id];
                $mesesDesde = (int) $u['quando']->diffInMonths(now());
                $tipoRot = $u['tipo'] === TipoIntervencao::Instalacao ? 'instalação' : 'manutenção preventiva';

                return [
                    'tipo' => 'proposta_intervencao',
                    'chave' => 'proposta_intervencao:'.$e->id.':'.$u['quando']->toDateString(),
                    // Média ao chegar aos meses configurados; alta quando passa um ano.
                    'severidade' => $mesesDesde >= 12 ? 'alta' : 'media',
                    'titulo' => 'Propor nova intervenção · '.(trim(($e->fabricante ?? '').' '.($e->modelo ?? '')) ?: ($e->numero_serie ?? '—')),
                    'descricao' => ($e->local?->cliente?->nome ?? '—').' · última '.$tipoRot.' a '.$u['quando']->translatedFormat('d M Y').' ('.$mesesDesde.' meses; aviso aos '.$meses.')',
                    'url' => route('equipamentos.ficha', $e),
                    'data' => $u['quando']->copy()->addMonths($meses),
                ];
            })
            ->sortBy('data')
            ->values()
            ->all();
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
            ? (int) Carbon::createFromTimestamp(filemtime($marcador))->diffInHours(now())
            : null;

        if ($idade !== null && $idade <= $maxHoras) {
            return []; // backup em dia
        }

        return [[
            'tipo' => 'backup',
            'chave' => 'backup:'.now()->toDateString(),
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
        return EquipamentoAlertaManutencao::query()
            ->whereDate('data', '<=', now()->addDays(7))
            ->with(['equipamento.local.cliente', 'utilizador'])
            ->orderBy('data')
            ->get()
            ->map(fn (EquipamentoAlertaManutencao $a) => [
                'tipo' => 'manutencao_programada',
                'chave' => 'manutencao_programada:'.$a->id,
                'severidade' => $a->data->isPast() || $a->data->isToday() ? 'alta' : 'media',
                'titulo' => $a->texto.' · '.(trim(($a->equipamento->fabricante ?? '').' '.($a->equipamento->modelo ?? '')) ?: ($a->equipamento->numero_serie ?? '—')),
                'descricao' => ($a->equipamento->local?->cliente?->nome ?? '—').' · programado para '.$a->data->translatedFormat('d M Y'),
                // O MODELO, não o id: é o que faz o link sair com o mastamp (ver Equipamento::getRouteKey).
                'url' => route('equipamentos.ficha', $a->equipamento),
                'data' => $a->data,
                'atribuido_a' => $a->utilizador ? [$a->utilizador->id] : [],
                'atribuido_nome' => $a->utilizador?->nome,
            ])
            ->all();
    }

    // Alertas de visita PROGRAMADOS no contrato (data + texto editável): aparecem a partir
    // de 7 dias antes da data (média) e passam a ALTA quando a data chega/passa. Saem quando
    // a linha é removida na edição do contrato (depois de a visita ficar agendada).
    private function visitasProgramadas(): array
    {
        return ContratoAlertaVisita::query()
            ->whereDate('data', '<=', now()->addDays(7))
            ->with(['contrato.cliente', 'utilizador'])
            ->orderBy('data')
            ->get()
            ->map(fn (ContratoAlertaVisita $a) => [
                'tipo' => 'visita_programada',
                'chave' => 'visita_programada:'.$a->id,
                'severidade' => $a->data->isPast() || $a->data->isToday() ? 'alta' : 'media',
                'titulo' => $a->texto.' · '.($a->contrato->numero ?? '—'),
                'descricao' => ($a->contrato->cliente->nome ?? '—').' · programado para '.$a->data->translatedFormat('d M Y'),
                'url' => route('contratos.editar', $a->contrato_id),
                'data' => $a->data,
                'atribuido_a' => $a->utilizador ? [$a->utilizador->id] : [],
                'atribuido_nome' => $a->utilizador?->nome,
            ])
            ->all();
    }

    // Alertas PROGRAMADOS num evento da agenda (data + texto editável no modal do evento):
    // mesma mecânica — 7 dias antes (média), alta ao vencer. Eventos cancelados não alertam.
    private function eventosProgramados(): array
    {
        return EventoAlerta::query()
            ->whereDate('data', '<=', now()->addDays(7))
            ->whereHas('evento', fn ($q) => $q->where('estado', '!=', EstadoEvento::Cancelado->value))
            ->with(['evento.cliente', 'evento.tecnico', 'evento.tecnicosAdicionais'])
            ->orderBy('data')
            ->get()
            ->map(fn (EventoAlerta $a) => [
                'tipo' => 'evento_programado',
                'chave' => 'evento_programado:'.$a->id,
                'severidade' => $a->data->isPast() || $a->data->isToday() ? 'alta' : 'media',
                'titulo' => $a->texto.' · '.$a->evento->titulo,
                'descricao' => ($a->evento->cliente?->nome ? $a->evento->cliente->nome.' · ' : '')
                    .'evento a '.$a->evento->inicio->translatedFormat('d M Y')
                    .' · aviso programado para '.$a->data->translatedFormat('d M Y'),
                'url' => route('agenda'),
                'data' => $a->data,
                // Quem faz parte do evento: principal + adicionais (sem técnicos = equipa).
                'atribuido_a' => $a->evento->tecnicoIdsTodos(),
                'atribuido_nome' => collect([$a->evento->tecnico])->merge($a->evento->tecnicosAdicionais)->filter()->unique('id')->pluck('nome')->implode(', ') ?: null,
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
                    'chave' => 'bateria:'.$e->id.':'.$e->proxima_troca_baterias->toDateString(),
                    'severidade' => $vencida ? 'alta' : 'media',
                    'titulo' => 'Baterias '.($vencida ? 'vencidas' : 'a vencer').' · '.trim($e->fabricante.' '.$e->modelo),
                    'descricao' => ($e->local?->cliente?->nome ?? '—').' · troca prevista '.$e->proxima_troca_baterias->translatedFormat('d M Y'),
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
                    'chave' => 'renovacao:'.$c->id.':'.$c->data_fim->toDateString(),
                    'severidade' => $dias <= $criticoDias ? 'alta' : 'media',
                    'titulo' => 'Contrato a expirar · '.$c->numero,
                    'descricao' => $c->cliente->nome.' · termina '.$c->data_fim->translatedFormat('d M Y')." (em {$dias} dias)",
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
                'chave' => 'visita_atraso:'.$e->id,
                'severidade' => 'alta',
                'titulo' => 'Visita em atraso · '.$e->titulo,
                'descricao' => ($e->cliente->nome ?? '—').' · planeada para '.$e->inicio->translatedFormat('d M Y'),
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
    private function alertaDePrazo(Intervencao $i, string $fase, Carbon $inicio, Carbon $prazo, string $cliente): ?array
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
            'chave' => 'sla:'.$i->id.':'.$fase,
            'severidade' => $estourou ? 'alta' : 'media',
            'titulo' => 'SLA de '.$fase.($estourou ? ' excedido' : ' a esgotar-se').' · intervenção #'.$i->id,
            'descricao' => $cliente.' · prazo '.$prazo->translatedFormat('d M H:i').($estourou ? ' (excedido)' : ' ('.(int) round($fracao * 100).'% decorrido)'),
            'url' => route('intervencoes.formulario', $i),
            'data' => $prazo,
        ];
    }
}
