<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\PapelUtilizador;
use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Support\Carbon;

// Fonte de dados do FullCalendar: eventos no formato do calendário, e as cores
// por técnico (partilhadas entre eventos e legenda). Extraído do componente Calendario —
// é leitura pura, sem estado de UI.
class FonteCalendario
{
    // Paleta de cores por técnico (legenda + eventos).
    private const PALETA = ['#16a34a', '#2563eb', '#9333ea', '#ea580c', '#0891b2', '#db2777'];

    // Mapa nome→cor calculado uma vez por pedido (lazy).
    private ?array $coresTecnicos = null;

    // Eventos que se SOBREPÕEM à janela visível (não só os que começam dentro
    // dela — um evento iniciado antes e a acabar lá dentro também aparece).
    /** @return array<int, array<string, mixed>> */
    public function eventos(Carbon $de, Carbon $ate, string $tecnicoNome = ''): array
    {
        $eventos = EventoAgenda::query()
            ->with(['cliente', 'equipamento', 'tecnicosAdicionais'])
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->where('inicio', '<', $ate)
            ->where('fim', '>', $de)
            // Filtro por técnico: eventos em que é o principal (tecnico_nome) OU um dos adicionais.
            ->when($tecnicoNome !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('tecnico_nome', $tecnicoNome)
                ->orWhereHas('tecnicosAdicionais', fn ($u) => $u->where('utilizadores.nome', $tecnicoNome))))
            ->get()
            ->flatMap(function (EventoAgenda $e) {
                // Cores de TODOS os técnicos do evento (principal + adicionais, sem repetidos):
                // com mais do que um, o frontend divide o bloco em faixas verticais, uma cor
                // por técnico (eventDidMount no app.js). Com um só, comporta-se como sempre.
                $cores = collect([$e->tecnico_nome])
                    ->concat($e->tecnicosAdicionais->sortBy('nome')->pluck('nome'))
                    ->map(fn ($n) => trim((string) $n))
                    ->filter()
                    ->unique()
                    ->map(fn (string $n) => $this->corTecnico($n))
                    ->unique()
                    ->values();
                $cor = $cores->first() ?? $this->corTecnico(null);

                $props = [
                    'kind' => 'evento',
                    'evento_id' => $e->id, // id do EVENTO mesmo em segmentos (clique/detalhe)
                    'tecnico_id' => $e->tecnico_id,
                    'tipo' => $e->tipo->value,
                    'estado' => $e->estado->value,
                    'cores' => $cores->all(),
                ];

                $segmentos = $e->segmentos();

                // Um segmento 00:00–23:59 (férias, ausências — "dia inteiro") vai para a faixa
                // de dia inteiro no topo da vista (allDay), não para a grelha das horas: senão
                // ocupava a coluna toda e empurrava/tapava os eventos com hora desse dia.
                // Não arrastável — muda-se no formulário.
                $bloco = function (string $id, Carbon $de, Carbon $ate, bool $arrastavel) use ($e, $cor, $props): array {
                    $base = ['id' => $id, 'title' => $e->titulo, 'backgroundColor' => $cor, 'borderColor' => $cor, 'extendedProps' => $props];

                    if (self::diaInteiro($de, $ate)) {
                        return $base + [
                            'allDay' => true,
                            'start' => $de->format('Y-m-d'),
                            'end' => $ate->copy()->addDay()->format('Y-m-d'), // fim exclusivo
                            'editable' => false,
                        ];
                    }

                    return $base + [
                        'start' => $de->format('Y-m-d\TH:i:s'),
                        'end' => $ate->format('Y-m-d\TH:i:s'),
                    ] + ($arrastavel ? [] : ['editable' => false]);
                };

                // Evento de um dia (ou multi-dia legado sem horas por dia): um bloco, como sempre.
                if (count($segmentos) === 1) {
                    return [$bloco((string) $e->id, $e->inicio, $e->fim, true)];
                }

                // Multi-dia com horas por dia: um bloco POR DIA com as horas reais trabalhadas.
                // Não arrastáveis (editable: false) — as horas de cada dia editam-se no formulário.
                return collect($segmentos)->map(fn (array $s, int $i) => $bloco($e->id.':'.$i, $s[0], $s[1], false))->all();
            })
            ->values()
            ->all();

        return $eventos;
    }

    // Segmento que cobre o dia todo: começa às 00:00 e acaba às 23:59 do mesmo dia (o que a
    // opção "Dia inteiro" do formulário grava).
    public static function diaInteiro(Carbon $de, Carbon $ate): bool
    {
        return $de->isSameDay($ate) && $de->format('H:i') === '00:00' && $ate->format('H:i') === '23:59';
    }

    public function corTecnico(?string $nome): string
    {
        $nome = trim((string) $nome);
        if ($nome === '') {
            return '#94a3b8'; // por atribuir
        }

        // Cor pela posição numa lista ESTÁVEL: contas de técnico por id (ids nunca mudam;
        // contas novas entram no FIM, sem mexer nas cores de quem já existe), seguidas dos
        // nomes legados (só texto) por ordem alfabética. Distintas até 6 técnicos.
        $this->coresTecnicos ??= (function (): array {
            $contas = User::where('papel', PapelUtilizador::Tecnico)
                ->orderBy('id')
                ->pluck('nome')
                ->map(fn (string $n) => trim($n));

            $legados = EventoAgenda::query()
                ->whereNotNull('tecnico_nome')
                ->where('tecnico_nome', '!=', '')
                ->distinct()
                ->orderBy('tecnico_nome')
                ->pluck('tecnico_nome')
                ->map(fn (string $n) => trim($n))
                ->reject(fn (string $n) => $contas->contains($n));

            return $contas->concat($legados)
                ->unique()
                ->values()
                ->mapWithKeys(fn (string $n, int $i) => [$n => self::PALETA[$i % count(self::PALETA)]])
                ->all();
        })();

        // Nome fora da lista (não devia acontecer): fallback determinístico por hash.
        return $this->coresTecnicos[$nome] ?? self::PALETA[abs(crc32($nome)) % count(self::PALETA)];
    }

    // Técnicos + cor respetiva — filtro e legenda do calendário. Entram TODAS as contas de
    // técnico ativas (mesmo sem eventos ainda — a legenda mostra a equipa toda), os nomes
    // legados usados nos eventos (só texto) e quem só aparece como técnico ADICIONAL (antes só
    // contava o principal, e um técnico que estivesse sempre "a acompanhar" nunca aparecia).
    /** @return array<int, array{nome: string, cor: string}> */
    public function legenda(): array
    {
        $contas = User::where('papel', PapelUtilizador::Tecnico)->where('ativo', true)->pluck('nome');

        $principais = EventoAgenda::query()
            ->whereNotNull('tecnico_nome')
            ->where('tecnico_nome', '!=', '')
            ->distinct()
            ->pluck('tecnico_nome');

        $adicionais = User::whereHas('eventosAdicionais')->pluck('nome');

        return $contas->concat($principais)->concat($adicionais)
            ->map(fn (string $n) => trim($n))
            ->filter()
            ->unique()
            ->sortBy(fn (string $n) => mb_strtolower($n), SORT_NATURAL)
            ->values()
            ->map(fn (string $nome) => ['nome' => $nome, 'cor' => $this->corTecnico($nome)])
            ->all();
    }
}
