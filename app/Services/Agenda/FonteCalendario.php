<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\PapelUtilizador;
use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Fonte de dados do FullCalendar: eventos no formato do calendário, e as cores
// por técnico (partilhadas entre eventos e legenda). Extraído do componente Calendario —
// é leitura pura, sem estado de UI.
class FonteCalendario
{
    // Paleta de cores por técnico (legenda + eventos).
    //
    // São as CORES DAS CATEGORIAS DO OUTLOOK (set. 2026): cada tom corresponde a uma
    // categoria do M365 (ver PRESETS_OUTLOOK), para a mesma pessoa ter a mesma cor na
    // agenda da app e no calendário partilhado. Antes eram tons próprios e a categoria do
    // Outlook saía de `id % 24` — verde aqui, rosa lá.
    //
    // A Microsoft não publica o hex de cada preset; estes são a aproximação usada pela app
    // (podem sair um fio mais claros/escuros no Outlook, mas é sempre o mesmo tom).
    // A ORDEM não pode mudar: mantém o tom que cada pessoa já tinha.
    public const PALETA = [
        '#22b14c', '#3a96dd', '#5c2d91', '#c25a21', '#1f6e7b', '#e2318c',
        '#986f0b', '#1c3f95', '#2f9e9e', '#a4262c', '#6b7d0c', '#b3a3e0',
    ];

    // As 25 categorias do Outlook e a cor com que o Outlook as desenha. Serve nos dois
    // sentidos: a cor de cada pessoa na app é uma destas, e a categoria que se cria no
    // M365 é a que corresponde à cor. Os valores foram tirados das próprias etiquetas do
    // Outlook (a Microsoft não os publica) — daí serem aproximados ao pixel, não oficiais.
    public const CORES_OUTLOOK = [
        'preset0' => '#d0454c',   // vermelho
        'preset1' => '#e8730c',   // laranja
        'preset2' => '#eab785',   // pêssego
        'preset3' => '#f5d435',   // amarelo
        'preset4' => '#22b14c',   // verde
        'preset5' => '#2f9e9e',   // turquesa
        'preset6' => '#a8a400',   // azeitona
        'preset7' => '#3a96dd',   // azul
        'preset8' => '#b3a3e0',   // roxo claro
        'preset9' => '#e2318c',   // framboesa
        'preset10' => '#808c94',  // aço
        'preset11' => '#4a5c6a',  // aço escuro
        'preset12' => '#c1c5c0',  // cinzento
        'preset13' => '#8a8886',  // cinzento escuro
        'preset14' => '#000000',  // preto
        'preset15' => '#a4262c',  // vermelho escuro
        'preset16' => '#c25a21',  // laranja escuro
        'preset17' => '#7a4526',  // castanho escuro
        'preset18' => '#986f0b',  // mostarda
        'preset19' => '#0b6a0b',  // verde escuro
        'preset20' => '#1f6e7b',  // turquesa escuro
        'preset21' => '#6b7d0c',  // azeitona escura
        'preset22' => '#1c3f95',  // azul escuro
        'preset23' => '#5c2d91',  // roxo escuro
        'preset24' => '#6b0036',  // framboesa escura
    ];

    // Cor de quem ainda não tem ninguém atribuído.
    public const COR_SEM_TECNICO = '#94a3b8';

    // Contas que aparecem na equipa mas não andam em serviços (o Davide, o Rui Moreira):
    // ficam a PRETO em vez de gastarem uma cor da paleta.
    public const COR_SEM_COR = '#000000';

    // Categoria do Outlook correspondente a uma cor da agenda: a de cor igual, ou — se a
    // cor tiver sido afinada à mão — a mais próxima (distância no espaço RGB).
    public static function presetOutlook(?string $cor): string
    {
        $alvo = self::rgb((string) $cor);
        if ($alvo === null) {
            return 'preset14'; // sem cor → preto
        }

        $melhor = 'preset14';
        $menor = PHP_INT_MAX;
        foreach (self::CORES_OUTLOOK as $preset => $hex) {
            $c = self::rgb($hex);
            $d = ($c[0] - $alvo[0]) ** 2 + ($c[1] - $alvo[1]) ** 2 + ($c[2] - $alvo[2]) ** 2;
            if ($d < $menor) {
                $menor = $d;
                $melhor = $preset;
            }
        }

        return $melhor;
    }

    // Cor do TEXTO por cima de um bloco desta cor: as categorias claras do Outlook (o roxo
    // claro, os cinzentos) ficavam ilegíveis com o branco de sempre.
    public static function textoSobre(?string $cor): string
    {
        $c = self::rgb((string) $cor);
        if ($c === null) {
            return '#ffffff';
        }

        // Luminância relativa (WCAG, sem a correção gama — chega para escolher preto/branco).
        $l = (0.299 * $c[0] + 0.587 * $c[1] + 0.114 * $c[2]) / 255;

        return $l > 0.6 ? '#1e293b' : '#ffffff';
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    private static function rgb(string $hex): ?array
    {
        $hex = ltrim(mb_strtolower(trim($hex)), '#');
        if (! preg_match('/^[0-9a-f]{6}$/', $hex)) {
            return null;
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    // Cores já resolvidas neste pedido (nome→cor) e as contas da equipa, para não repetir
    // consultas nem atribuir cores a quem não aparece.
    private array $coresTecnicos = [];

    private ?Collection $contas = null;

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
                    'textos' => $cores->map(fn (string $c) => self::textoSobre($c))->all(),
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
                    $base = ['id' => $id, 'title' => $titulo, 'backgroundColor' => $cor, 'borderColor' => $cor,
                        'textColor' => self::textoSobre($cor), 'extendedProps' => $props];

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

        // Contas da equipa (técnicos e administradores — também vão a serviços), incluindo
        // inativas: os eventos antigos continuam a mostrar quem lá esteve.
        $this->contas ??= User::whereIn('papel', [PapelUtilizador::Tecnico, PapelUtilizador::Admin])
            ->orderBy('id')
            ->get()
            ->keyBy(fn (User $u) => trim($u->nome));

        // A cor só é atribuída a QUEM APARECE. Antes pedia-se a cor de toda a equipa de uma
        // vez, e contas que nunca vão a serviços (a de suporte, um administrativo) gastavam
        // uma cor da paleta — deixando pessoas reais a repetir cores.
        if ($conta = $this->contas->get($nome)) {
            return $this->coresTecnicos[$nome] ??= $conta->corAgenda();
        }

        // Nome só de texto, sem conta (eventos legados): determinístico pelo nome, mas
        // escolhido entre as cores que NINGUÉM tem — senão ia bater na cor de uma pessoa
        // real e voltavam as cores repetidas.
        $jaUsadas = User::whereNotNull('cor_agenda')->pluck('cor_agenda')->all();
        $livres = array_values(array_diff(self::PALETA, $jaUsadas));
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
        $contas = User::selecionavel()->pluck('nome');

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
