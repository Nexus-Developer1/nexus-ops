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
    // Paleta das cores da agenda: 12 tons bem separados, todos escuros o suficiente para o
    // texto branco dos blocos. Eram 6 — com 7 pessoas na equipa começavam a repetir-se.
    // A ORDEM não pode mudar: as 6 primeiras são as cores que os técnicos já tinham.
    public const PALETA = [
        '#16a34a', '#2563eb', '#9333ea', '#ea580c', '#0891b2', '#db2777',
        '#ca8a04', '#4f46e5', '#0f766e', '#be123c', '#65a30d', '#86198f',
    ];

    // Cor de quem ainda não tem ninguém atribuído.
    public const COR_SEM_TECNICO = '#94a3b8';

    // Mapa nome→cor calculado uma vez por pedido (lazy).
    private ?array $coresTecnicos = null;

    // Eventos que se SOBREPÕEM à janela visível (não só os que começam dentro
    // dela — um evento iniciado antes e a acabar lá dentro também aparece).
    /** @return array<int, array<string, mixed>> */
    public function eventos(Carbon $de, Carbon $ate, string $tecnicoNome = ''): array
    {
        $eventos = EventoAgenda::query()
            ->with(['cliente', 'equipamento', 'tecnico', 'tecnicosAdicionais'])
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
                // O bloco diz o que é E de quem é: "título · cliente" (quem olha para a semana
                // quer saber onde cada um está sem abrir evento a evento).
                // "tipo · cliente · técnicos" (EventoAgenda::resumoCompleto — o mesmo que vai para o Outlook).
                $titulo = $e->resumoCompleto();

                $bloco = function (string $id, Carbon $de, Carbon $ate, bool $arrastavel) use ($cor, $props, $titulo): array {
                    $base = ['id' => $id, 'title' => $titulo, 'backgroundColor' => $cor, 'borderColor' => $cor, 'extendedProps' => $props];

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

    /**
     * Cor de um técnico, pelo NOME (é assim que os eventos o guardam).
     *
     * A cor vive na CONTA (utilizadores.cor_agenda) e é atribuída uma única vez: não muda
     * quando alguém entra, sai ou passa a administrador, e não se repete entre pessoas.
     * Antes vinha da posição numa lista recalculada a cada pedido — mudava sozinha e
     * repetia-se (equipa, set. 2026).
     */
    public function corTecnico(?string $nome): string
    {
        $nome = trim((string) $nome);
        if ($nome === '') {
            return self::COR_SEM_TECNICO; // por atribuir
        }

        // Mapa nome→cor de TODA a equipa (técnicos e administradores: também vão a
        // serviços), incluindo contas inativas — os eventos antigos continuam lá.
        $this->coresTecnicos ??= User::whereIn('papel', [PapelUtilizador::Tecnico, PapelUtilizador::Admin])
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (User $u) => [trim($u->nome) => $u->corAgenda()])
            ->all();

        if (isset($this->coresTecnicos[$nome])) {
            return $this->coresTecnicos[$nome];
        }

        // Nome só de texto, sem conta (eventos legados): determinístico pelo nome, mas
        // escolhido entre as cores que NINGUÉM tem — senão ia bater na cor de uma pessoa
        // real e voltavam as cores repetidas.
        $livres = array_values(array_diff(self::PALETA, $this->coresTecnicos));
        $livres = $livres ?: self::PALETA;

        return $livres[abs(crc32($nome)) % count($livres)];
    }

    // Técnicos + cor respetiva — filtro e legenda do calendário. Entram TODAS as contas de
    // técnico ativas (mesmo sem eventos ainda — a legenda mostra a equipa toda), os nomes
    // legados usados nos eventos (só texto) e quem só aparece como técnico ADICIONAL (antes só
    // contava o principal, e um técnico que estivesse sempre "a acompanhar" nunca aparecia).
    /** @return array<int, array{nome: string, cor: string}> */
    public function legenda(): array
    {
        // Técnicos ativos + qualquer conta que apareça em eventos (um administrador que
        // vá a serviços tem de ter cor própria na legenda, como toda a gente).
        $contas = User::fazServicos()->where('ativo', true)->pluck('nome');

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
