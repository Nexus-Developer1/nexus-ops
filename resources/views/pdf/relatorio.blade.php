<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <style>
        /* ---- Base ------------------------------------------------------------------ */
        @page { margin: 15mm 14mm 19mm 14mm; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #111827; margin: 0; line-height: 1.4; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }

        /* Rodapé fixo em TODAS as páginas (o dompdf repete os position:fixed) com nº de página. */
        .rodape-fixo { position: fixed; bottom: -11mm; left: 0; right: 0; height: 8mm; border-top: 2px solid #15803D; padding-top: 4px; font-size: 8px; color: #4B5563; }
        .rodape-fixo .pagina:after { content: counter(page); }

        /* ---- Cabeçalho ------------------------------------------------------------ */
        .cabecalho { border-bottom: 3px solid #15803D; padding-bottom: 10px; margin-bottom: 14px; }
        .cabecalho td { vertical-align: bottom; }
        .suite { color: #6B7280; font-size: 7.5px; letter-spacing: 2.5px; margin-top: 3px; }
        .doc-titulo { font-size: 15px; font-weight: bold; color: #111827; line-height: 1.15; }
        .doc-num { font-size: 11px; color: #15803D; font-weight: bold; margin-top: 3px; }
        .doc-data { font-size: 10px; color: #4B5563; margin-top: 1px; }

        /* ---- Grelha de dados (rótulo | valor) --------------------------------------- */
        .dados { border: 1px solid #D1D5DB; margin-bottom: 10px; }
        .dados td { border: 1px solid #D1D5DB; padding: 5px 8px; font-size: 10.5px; }
        .dados td.r { background-color: #F3F4F6; color: #374151; font-weight: bold; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.6px; width: 14%; vertical-align: middle; }
        .dados td.v { font-weight: bold; width: 36%; }

        /* ---- Secções e textos ----------------------------------------------------- */
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #ffffff; background-color: #15803D; padding: 5px 9px; margin: 14px 0 8px; }
        .bloco { margin: 0 0 10px; }
        .bloco .rot { color: #15803D; font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 3px; border-bottom: 1px solid #D1D5DB; padding-bottom: 2px; }
        .texto { color: #1F2937; line-height: 1.5; white-space: pre-line; } /* respeita as quebras de linha escritas pelo técnico */
        .cliente-linha { color: #1F2937; font-size: 10.5px; line-height: 1.4; }
        .grelha td { padding: 4px 0; width: 50%; }
        .campo-rotulo { color: #374151; font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; }
        .campo-valor { color: #111827; font-weight: bold; font-size: 11px; }

        /* Resumo dos resultados (1.ª página). */
        .tab { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .tab th { background-color: #374151; color: #ffffff; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.6px; text-align: left; padding: 5px 8px; }
        .tab td { padding: 6px 8px; border: 1px solid #D1D5DB; font-size: 10.5px; }
        .tab .mini { color: #4B5563; font-size: 8.5px; }
        .aviso { border: 1px solid #B91C1C; border-left: 6px solid #B91C1C; padding: 7px 10px; margin: 8px 0; }
        .aviso .rot { color: #B91C1C; }
        .recom { border: 1px solid #15803D; border-left: 6px solid #15803D; padding: 7px 10px; margin: 8px 0; }
        .recom .rot { color: #15803D; }
        .aviso .rot, .recom .rot { font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 3px; }
        .lista-item { padding: 2px 0; color: #111827; }
        .lista-item .quem { color: #4B5563; }

        /* A informação técnica (checklist legada) começa sempre numa página nova. */
        .pagina-tecnica { page-break-before: always; }
        .item { padding: 3px 0 3px 10px; }
        .marca-check { color: #15803D; font-weight: bold; }
        .marca-vazio { color: #9CA3AF; }
        .etapa-titulo { margin-top: 8px; font-weight: bold; color: #111827; font-size: 11px; }
        .etapa-contador { color: #6B7280; font-weight: normal; font-size: 9px; }
        .item-obs { color: #4B5563; }

        /* ---- Fotos (grelha em tabela, 3/linha — ver pdf/_fotos.blade.php) ---------- */
        .fotos-tab { width: 100%; border-collapse: separate; border-spacing: 0 0; margin-bottom: 6px; }
        .foto-cel { width: 33.33%; padding: 0 6px 6px 0; }
        .foto { width: 100%; height: 150px; object-fit: cover; border: 1px solid #9CA3AF; }
        /* Título de secção + primeiro bloco nunca se separam por uma quebra de página. */
        .junto { page-break-inside: avoid; }

        /* ---- Fichas de medição --------------------------------------------------------
           Cada ficha começa SEMPRE em página nova — mesmo que a 1.ª página fique meia em branco
           (decisão da equipa: a ficha de verificações não se mistura com o resumo). */
        .ficha-pagina { page-break-before: always; }
        .ficha-cab { border-bottom: 3px solid #15803D; padding-bottom: 6px; margin-bottom: 4px; }
        .ficha-cab td { vertical-align: bottom; }
        .ficha-titulo { font-size: 15px; font-weight: bold; color: #111827; margin: 0 0 2px; }
        .ficha-sub { color: #374151; font-size: 10px; }
        .ficha-seccao { background-color: #15803D; color: #ffffff; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; padding: 4px 9px; margin: 12px 0 5px; }
        .ficha-tab { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .ficha-tab td, .ficha-tab th { border: 1px solid #D1D5DB; padding: 3.5px 6px; font-size: 10px; vertical-align: top; }
        .ficha-tab th { background-color: #E5E7EB; color: #111827; font-weight: bold; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .ficha-rot { color: #374151; font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; }
        .cel-num { text-align: center; }
        .cel-ok { text-align: center; color: #15803D; font-weight: bold; width: 8%; font-size: 12px; }
        .cel-nok { text-align: center; color: #B91C1C; font-weight: bold; width: 8%; font-size: 12px; }
        .cel-na { text-align: center; color: #6B7280; font-weight: bold; width: 8%; font-size: 12px; } /* N/A (ficha SADEI) */
        /* Grelha de caixas das medições — espelho do formulário (3 grupos por linha). */
        .med-grid { width: 100%; border-collapse: separate; border-spacing: 4px 3px; margin: 0 -4px; }
        .med-cel { width: 33.33%; vertical-align: top; }
        .med-caixa { border: 1px solid #9CA3AF; padding: 5px 8px 6px; }
        .med-titulo { font-size: 9px; font-weight: bold; color: #111827; margin-bottom: 4px; border-bottom: 1px solid #E5E7EB; padding-bottom: 2px; }
        .med-vals { width: 100%; border-collapse: collapse; }
        .med-vals td { padding: 0 8px 0 0; vertical-align: bottom; }
        .med-rot { color: #374151; font-size: 7.5px; font-weight: bold; text-transform: uppercase; }
        .med-val { font-size: 11.5px; font-weight: bold; color: #111827; padding: 1px 2px 2px; }
        .temp-alerta { color: #B91C1C; } /* temperatura acima do limite */
        .legenda-grafico { text-align: center; color: #374151; font-size: 8.5px; margin-top: 2px; }
        .assin-tab td { border: 1px solid #D1D5DB; padding: 6px 8px; }
        .assin-area { height: 64px; vertical-align: bottom; text-align: center; border-bottom: 1px solid #111827; }
        .assin-nome { font-weight: bold; color: #111827; font-size: 10.5px; }
    </style>
</head>
<body>
    @php($i = $relatorio->intervencao)
    @php($e = $i->equipamento)
    {{-- local pode ser null (equipamento "por associar" do PHC) — o PDF não pode rebentar. --}}
    @php($c = $e->local?->cliente)
    @php($fichas = $i->fichasMedicao)
    {{-- Sem selo "Conforme / Com anomalias" (a equipa não o quer no PDF): o que o técnico marcou
         KO fica visível na caixa "Anomalias detetadas" e nas próprias fichas. --}}
    @php($marca = fn ($v, $alvo) => ($v ?? null) === $alvo ? (in_array($alvo, ['ko', 'nok'], true) ? '✗' : ($alvo === 'na' ? '–' : '✓')) : '')
    @php($rotuloEq = fn ($f) => $f->tipo_equipamento === 'incendio' ? 'Deteção de incêndio' : ($f->equipamento?->tipo?->rotulo() ?? 'UPS'))
    @php($anomalias = $fichas->flatMap(fn ($f) => collect($f->anomalias())->map(fn ($a) => $a + ['quem' => trim($rotuloEq($f).' · '.($f->serie ?: ($f->equipamento?->numero_serie ?? '')), ' ·')])))
    @php($recomendacoes = $fichas->filter(fn ($f) => trim((string) $f->recomendacao) !== ''))
    {{-- Extras do equipamento (componentes sempre; cliente final / localização / também cobertos
         só sem fichas, porque com fichas já estão na tabela de resultados e em cada ficha). --}}
    @php($eCliFinal = trim((string) ($e->cliente_final ?? '')))
    @php($eLocaliz = trim((string) ($e->localizacao_instalacao ?? '')))
    @php($eComponentes = collect($e->atributos['componentes'] ?? [])->filter(fn ($comp) => trim((string) ($comp['designacao'] ?? '')) !== ''))
    @php($semFichas = $fichas->isEmpty())
    @php($temExtrasEquipamento = $eComponentes->isNotEmpty() || ($semFichas && ($eCliFinal !== '' || $eLocaliz !== '' || $i->equipamentosCobertos->isNotEmpty())))
    @php($temTextos = $i->descricao_problema || $i->trabalho_realizado || $i->observacoes)

    <div class="rodape-fixo">
        <table>
            <tr>
                <td>NEXUS SOLUTIONS OPERATIONS · Relatório {{ $relatorio->numero }}@if ($c) · {{ $c->nome }}@endif</td>
                <td align="right">Documento gerado em {{ now()->format('d/m/Y H:i') }} · Página <span class="pagina"></span></td>
            </tr>
        </table>
    </div>

    <table class="cabecalho">
        <tr>
            <td>
                {{-- Logótipo oficial (wordmark verde) embebido como data URI — o dompdf tem
                     enable_remote=false, por isso nunca por URL. Se o ficheiro faltar num
                     deploy, cai na marca em texto em vez de rebentar a geração do PDF. --}}
                @if (is_file(public_path('img/nexus-1.png')))
                    <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('img/nexus-1.png'))) }}" alt="NEXUS" style="width: 132px;">
                @else
                    <div style="font-size: 22px; font-weight: 800; color: #15803D;">NEXUS</div>
                @endif
                <div class="suite">TECHNICAL SUITE</div>
            </td>
            <td align="right">
                <div class="doc-titulo">Relatório de Intervenção Técnica</div>
                <div class="doc-num">Nº {{ $relatorio->numero }}</div>
                <div class="doc-data">{{ $relatorio->data->format('d/m/Y') }} · Manutenção {{ strtolower($i->tipo->rotulo()) }}@if ($i->contrato) · Contrato {{ $i->contrato->numero }}@endif</div>
            </td>
        </tr>
    </table>

    {{-- ---- Dados: cliente / local / contrato / intervenção ---------------------------- --}}
    {{-- Local: o cliente final (equipamento instalado num cliente do cliente) ou a sede da
         empresa (morada do ERP) — nunca o local da intervenção. --}}
    @php($sede = collect([trim((string) $c?->morada), trim((string) $c?->codpost)])->filter(fn ($s) => $s !== '')->implode(' · '))
    {{-- Principal + colaboradores, sem repetir, para o campo "Técnicos". --}}
    @php($nomesTecnicos = collect([$i->tecnico?->nome])->merge($i->tecnicos->pluck('nome'))->filter()->unique()->implode(', '))
    {{-- Datas + horas ESCRITAS pelo técnico (data_fim é o término real vindo do formulário). --}}
    @php($hIni = $i->hora_inicio ? substr($i->hora_inicio, 0, 5) : null)
    @php($hFim = $i->hora_fim ? substr($i->hora_fim, 0, 5) : null)
    <table class="dados">
        <tr>
            <td class="r">Cliente</td>
            <td class="v">{{ $c?->nome ?? '—' }}</td>
            <td class="r">Local</td>
            <td class="v">{{ $eCliFinal !== '' ? $eCliFinal : ($sede !== '' ? $sede : '—') }}</td>
        </tr>
        <tr>
            @if ($i->contrato)
                {{-- Relatório no âmbito de um contrato. Individual (sem contrato) → âmbito. --}}
                <td class="r">Contrato</td>
                <td class="v">{{ $i->contrato->numero }} <span style="font-weight: normal; color: #4B5563;">· {{ $i->contrato->tipo->rotulo() }}</span></td>
            @else
                <td class="r">Âmbito</td>
                <td class="v">Intervenção individual <span style="font-weight: normal; color: #4B5563;">· fora de contrato</span></td>
            @endif
            <td class="r">Tipo</td>
            <td class="v">{{ $i->tipo->rotulo() }}</td>
        </tr>
        <tr>
            <td class="r">{{ $i->tecnicos->isEmpty() ? 'Técnico' : 'Técnicos' }}</td>
            <td class="v">{{ $nomesTecnicos ?: '—' }}</td>
            <td class="r">Início</td>
            <td class="v">{{ $i->data_inicio?->format('d/m/Y') ?? '—' }}{{ $hIni ? " · $hIni" : '' }}</td>
        </tr>
        <tr>
            <td class="r">Término</td>
            <td class="v">{{ $i->data_fim?->format('d/m/Y') ?? ($i->data_inicio?->format('d/m/Y') ?? '—') }}{{ $hFim ? " · $hFim" : '' }}</td>
            <td class="r">Equipamentos</td>
            <td class="v">{{ $fichas->isNotEmpty() ? $fichas->count() : 1 }}</td>
        </tr>
    </table>

    @if ($temTextos)
        <h2>Descrição da intervenção</h2>
        @if ($i->descricao_problema)
            <div class="bloco"><div class="rot">Problema reportado</div><div class="texto">{{ $i->descricao_problema }}</div></div>
        @endif
        @if ($i->trabalho_realizado)
            <div class="bloco"><div class="rot">Trabalho realizado</div><div class="texto">{{ $i->trabalho_realizado }}</div></div>
        @endif
        @if ($i->observacoes)
            <div class="bloco"><div class="rot">Observações</div><div class="texto">{{ $i->observacoes }}</div></div>
        @endif
    @endif

    {{-- ---- Equipamentos verificados (só com fichas) ---------------------------------- --}}
    {{-- O cliente vê logo na 1.ª página os equipamentos, as anomalias e as recomendações —
         sem ter de ler as fichas técnicas que se seguem. --}}
    @if ($fichas->isNotEmpty())
        <h2>Equipamentos verificados</h2>
        <table class="tab">
            <tr><th style="width: 55%;">Equipamento</th><th>Local de instalação</th></tr>
            @foreach ($fichas as $f)
                @php($feq = $f->equipamento)
                @php($nomeEq = trim(($f->marca ?: $feq?->fabricante ?? '').' '.($f->modelo ?: $feq?->modelo ?? '')))
                @php($locEq = trim((string) ($feq?->localizacao_instalacao ?? '')) ?: (trim((string) ($feq?->local?->morada ?? '')) ?: '—'))
                <tr>
                    <td><b>{{ $rotuloEq($f) }}</b>@if ($nomeEq !== '') · {{ $nomeEq }}@endif<div class="mini">S/N {{ $f->serie ?: ($feq?->numero_serie ?? '—') }}</div></td>
                    <td>{{ $locEq }}</td>
                </tr>
            @endforeach
        </table>

        @if ($anomalias->isNotEmpty())
            <div class="aviso">
                <div class="rot">Anomalias detetadas ({{ $anomalias->count() }})</div>
                @foreach ($anomalias as $a)
                    <div class="lista-item">✗ {{ $a['item'] }}@if ($a['nota'] !== '') — {{ $a['nota'] }}@endif <span class="quem">({{ $a['quem'] }})</span></div>
                @endforeach
            </div>
        @endif

        @if ($recomendacoes->isNotEmpty())
            <div class="recom">
                <div class="rot">Recomendações e próximos passos</div>
                @foreach ($recomendacoes as $f)
                    <div class="lista-item">
                        {{ $f->recomendacao }}
                        @if ($f->tipo_equipamento !== 'incendio' && $f->prioridade)<span class="quem">· Prioridade {{ strtolower($f->prioridade) }}</span>@endif
                        @if ($fichas->count() > 1)<span class="quem">({{ $rotuloEq($f) }} · {{ $f->serie ?: ($f->equipamento?->numero_serie ?? '—') }})</span>@endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ---- Equipamento: extras (só quando preenchidos) -------------------------------- --}}
    {{-- Identificação do equipamento (S/N, fabricante, tipo) saiu do relatório a pedido da
         equipa — a ficha de medições já identifica cada equipamento. Ficam só os extras. --}}
    @if ($temExtrasEquipamento)
        <h2>Equipamento</h2>
        <table class="grelha">
            @if ($semFichas && ($eCliFinal !== '' || $eLocaliz !== ''))
                <tr>
                    @if ($eCliFinal !== '')<td><div class="campo-rotulo">Cliente final</div><div class="campo-valor">{{ $eCliFinal }}</div></td>@endif
                    @if ($eLocaliz !== '')<td><div class="campo-rotulo">Localização da instalação</div><div class="campo-valor">{{ $eLocaliz }}</div></td>@endif
                </tr>
            @endif
            {{-- Componentes do sistema (equipamentos compostos, ex.: deteção de incêndio). --}}
            @if ($eComponentes->isNotEmpty())
                <tr>
                    <td colspan="2">
                        <div class="campo-rotulo">Componentes do sistema</div>
                        @foreach ($eComponentes as $comp)
                            <div class="cliente-linha">{{ $comp['designacao'] }}@if ((int) ($comp['quantidade'] ?? 0) > 0) · {{ (int) $comp['quantidade'] }} un.@endif</div>
                        @endforeach
                    </td>
                </tr>
            @endif
            @if ($semFichas && $i->equipamentosCobertos->isNotEmpty())
                <tr>
                    <td colspan="2">
                        <div class="campo-rotulo">Também cobertos</div>
                        @foreach ($i->equipamentosCobertos as $ec)
                            <div class="cliente-linha">{{ $ec->numero_serie ?? '—' }} · {{ trim($ec->fabricante . ' ' . $ec->modelo) ?: '—' }}</div>
                        @endforeach
                    </td>
                </tr>
            @endif
        </table>
    @endif

    {{-- ===== PÁGINA TÉCNICA — checklist antiga, só quando NÃO há fichas de medição (relatórios
         legados). A página só existe quando tem conteúdo — vazia deixava uma página em branco. --}}
    @php($temChecklist = $fichas->isEmpty() && ($i->checklistEtapas->count() || $i->checklistItens->count()))
    @if ($temChecklist)
    <div class="pagina-tecnica">
        @if ($i->checklistEtapas->count())
            <h2>Checklist</h2>
            @foreach ($i->checklistEtapas as $etapa)
                @php($tot = $etapa->itens->count())
                @php($fei = $etapa->itens->where('concluido', true)->count())
                <div class="etapa-titulo">{{ $etapa->titulo }} <span class="etapa-contador">({{ $fei }}/{{ $tot }} concluídos)</span></div>
                @foreach ($etapa->itens as $item)
                    <div class="item">
                        <span class="{{ $item->concluido ? 'marca-check' : 'marca-vazio' }}">{{ $item->concluido ? '[X]' : '[ ]' }}</span>
                        {{ $item->descricao }}@if ($item->observacao)<span class="item-obs"> — {{ $item->observacao }}</span>@endif
                    </div>
                @endforeach
            @endforeach
        @elseif ($i->checklistItens->count())
            <h2>Checklist</h2>
            @foreach ($i->checklistItens as $item)
                <div class="item">
                    <span class="{{ $item->concluido ? 'marca-check' : 'marca-vazio' }}">{{ $item->concluido ? '[X]' : '[ ]' }}</span>
                    {{ $item->descricao }}
                </div>
            @endforeach
        @endif
    </div>{{-- /pagina-tecnica --}}
    @endif

    {{-- ===== FICHAS DE MEDIÇÃO — uma por página (contrato e individual), SEMPRE a começar em
         página nova, mesmo que a 1.ª página fique meia em branco (pedido da equipa).
         Nota: usar sempre a forma INLINE do PHP (como o resto desta view); um bloco raw
         de PHP partiria a compilação do Blade. --}}
    @if ($fichas->isNotEmpty())
        @foreach ($fichas as $ficha)
            @php($fe = $ficha->equipamento)
            @php($floc = $fe?->local)
            @php($fcli = $floc?->cliente)
            {{-- Campo explícito do equipamento tem prioridade; senão, cai na lógica derivada. --}}
            @php($cfTexto = trim((string) ($fe?->cliente_final ?? '')))
            @php($locTexto = trim((string) ($fe?->localizacao_instalacao ?? '')))
            {{-- Local de instalação = MORADA onde o equipamento está (nunca o nome do local,
                 ex.: "Instalação principal"): campo explícito do equipamento → morada do
                 local → morada da sede do cliente. --}}
            @php($locMorada = trim((string) ($floc?->morada ?? '')))
            @php($locSede = collect([trim((string) ($fcli?->morada ?? '')), trim((string) ($fcli?->codpost ?? ''))])->filter(fn ($s) => $s !== '')->implode(' · '))
            @php($locDerivado = $locMorada !== '' ? $locMorada : ($locSede !== '' ? $locSede : '—'))
            @php($mostrarClienteFinal = $cfTexto !== '' || ($fcli && $i->contrato && $fcli->id !== $i->contrato->cliente_id))
            @php($clienteFinalValor = $cfTexto !== '' ? $cfTexto : ($fcli?->nome ?? '—'))
            <div class="ficha-pagina">
                {{-- Decide pela FICHA (tipo_equipamento gravado) com fallback ao equipamento:
                     imune a equipamentos entretanto apagados (soft delete) e cobre fichas
                     antigas gravadas antes do tipo real ser registado. --}}
                @php($eIncendio = $ficha->tipo_equipamento === 'incendio' || ($fe?->tipo ?? null) === \App\Enums\TipoEquipamento::Incendio)
                <table class="ficha-cab">
                    <tr>
                        <td>
                            <div class="ficha-titulo">{{ $eIncendio ? 'Ficha de Verificações SADEI' : 'Ficha de Medições — UPS' }}</div>
                            <div class="ficha-sub">Relatório {{ $relatorio->numero }} · {{ $ficha->serie ?: ($fe?->numero_serie ?? '—') }}@if (trim(($ficha->marca ?: '').' '.($ficha->modelo ?: '')) !== '') · {{ trim(($ficha->marca ?: '').' '.($ficha->modelo ?: '')) }}@endif</div>
                        </td>
                    </tr>
                </table>

                @if ($eIncendio)
                    {{-- Equipamentos de incêndio: Ficha de Verificações SADEI (folha própria). --}}
                    @include('pdf._ficha_sadei')
                @else
                <div class="ficha-seccao">Identificação</div>
                <table class="ficha-tab">
                    <tr>
                        <td style="width:50%;"><span class="ficha-rot">Cliente</span><br>{{ $i->contrato?->cliente?->nome ?? $fcli?->nome ?? '—' }}</td>
                        <td><span class="ficha-rot">Intervenção nº</span><br>{{ $relatorio->numero }}</td>
                    </tr>
                    @if ($mostrarClienteFinal)
                        <tr><td colspan="2"><span class="ficha-rot">Cliente final</span><br>{{ $clienteFinalValor }}</td></tr>
                    @endif
                    <tr>
                        <td><span class="ficha-rot">Data</span><br>{{ $i->data_inicio?->format('d/m/Y') ?? $relatorio->data->format('d/m/Y') }}{{ $hIni && $hFim ? " · {$hIni}–{$hFim}" : '' }}</td>
                        <td><span class="ficha-rot">Local de instalação</span><br>{{ $locTexto !== '' ? $locTexto : $locDerivado }}</td>
                    </tr>
                </table>

                <div class="ficha-seccao">Dados do equipamento</div>
                <table class="ficha-tab">
                    <tr>
                        <td style="width:25%;"><span class="ficha-rot">Marca</span><br>{{ $ficha->marca ?: '—' }}</td>
                        <td style="width:25%;"><span class="ficha-rot">Modelo</span><br>{{ $ficha->modelo ?: '—' }}</td>
                        <td style="width:25%;"><span class="ficha-rot">S/N</span><br>{{ $ficha->serie ?: '—' }}</td>
                        <td style="width:25%;"><span class="ficha-rot">Baterias</span><br>{{ $ficha->baterias ?: '—' }}</td>
                    </tr>
                </table>

                <div class="ficha-seccao">Configuração da UPS</div>
                <table class="ficha-tab">
                    <tr>
                        <td style="width:50%;"><span class="ficha-rot">Tipo</span><br>{{ $ficha->config_tipo ? ucfirst($ficha->config_tipo) : '—' }}</td>
                        <td><span class="ficha-rot">Bypass externo</span><br>{{ $ficha->bypass_externo ? 'Sim' : 'Não' }}</td>
                    </tr>
                </table>
                @php($modulos = collect($ficha->modulos ?? [])->filter(fn ($m) => trim((string) ($m['modelo'] ?? '')) !== '' || trim((string) ($m['sn'] ?? '')) !== ''))
                @php($bancos = collect($ficha->bancos_bateria ?? [])->filter(fn ($m) => trim((string) ($m['modelo'] ?? '')) !== '' || trim((string) ($m['sn'] ?? '')) !== ''))
                @if ($modulos->isNotEmpty())
                    <table class="ficha-tab">
                        <tr><th colspan="2">Módulos de potência</th></tr>
                        <tr><th style="width:60%;">Modelo</th><th>S/N</th></tr>
                        @foreach ($modulos as $m)
                            <tr><td>{{ $m['modelo'] ?? '' }}</td><td>{{ $m['sn'] ?? '' }}</td></tr>
                        @endforeach
                    </table>
                @endif
                @if ($bancos->isNotEmpty())
                    <table class="ficha-tab">
                        <tr><th colspan="2">Banco de baterias externo</th></tr>
                        <tr><th style="width:60%;">Modelo</th><th>S/N</th></tr>
                        @foreach ($bancos as $m)
                            <tr><td>{{ $m['modelo'] ?? '' }}</td><td>{{ $m['sn'] ?? '' }}</td></tr>
                        @endforeach
                    </table>
                @endif

                {{-- Medições elétricas: espelho EXATO do formulário — grelha de caixas (3 por
                     linha), cada uma com o título do grupo e os valores por baixo dos rótulos. --}}
                <div class="ficha-seccao">Medições elétricas</div>
                @php($gruposE = [
                    ['Entrada — Tensão L-N (V)', ['L1' => 've_ln_l1', 'L2' => 've_ln_l2', 'L3' => 've_ln_l3']],
                    ['Entrada — Tensão L-L (V)', ['L1-L2' => 've_ll_l1l2', 'L1-L3' => 've_ll_l1l3', 'L2-L3' => 've_ll_l2l3']],
                    ['Carga (%)', ['L1' => 'carga_l1', 'L2' => 'carga_l2', 'L3' => 'carga_l3']],
                    ['Frequência (Hz)', ['Hz' => 'frequencia']],
                    ['Saída — Tensão L-N (V)', ['L1' => 'vs_ln_l1', 'L2' => 'vs_ln_l2', 'L3' => 'vs_ln_l3']],
                    ['Saída — Tensão L-L (V)', ['L1-L2' => 'vs_ll_l1l2', 'L1-L3' => 'vs_ll_l1l3', 'L2-L3' => 'vs_ll_l2l3']],
                    ['Saída — Corrente (A)', ['L1' => 'is_l1', 'L2' => 'is_l2', 'L3' => 'is_l3']],
                    ['Saída — Corrente de pico (A)', ['L1' => 'ispico_l1', 'L2' => 'ispico_l2', 'L3' => 'ispico_l3']],
                    // Temperatura separada das baterias: é a temperatura NA UPS, não a das baterias.
                    ['Baterias', ['Vbat +' => 'vbat_pos', 'Vbat −' => 'vbat_neg']],
                    ['Temperatura UPS', ['Temp (°C)' => 'temperatura']],
                ])
                @foreach (array_chunk($gruposE, 3) as $linhaGrupos)
                    <table class="med-grid">
                        <tr>
                            @foreach ($linhaGrupos as [$titulo, $campos])
                                <td class="med-cel">
                                    <div class="med-caixa">
                                        <div class="med-titulo">{{ $titulo }}</div>
                                        <table class="med-vals">
                                            <tr>
                                                @foreach ($campos as $lab => $campo)
                                                    {{-- Temperatura acima do limite sai a vermelho (alerta visual). --}}
                                                    @php($tempAlta = $campo === 'temperatura' && is_numeric($ficha->temperatura) && (float) $ficha->temperatura > \App\Models\FichaMedicao::TEMPERATURA_ALERTA)
                                                    <td>
                                                        <div class="med-rot">{{ $lab }}</div>
                                                        <div class="med-val{{ $tempAlta ? ' temp-alerta' : '' }}">{{ ($ficha->{$campo} ?? '') !== '' ? $ficha->{$campo} : '—' }}</div>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        </table>
                                    </div>
                                </td>
                            @endforeach
                            @for ($k = count($linhaGrupos); $k < 3; $k++)<td class="med-cel"></td>@endfor
                        </tr>
                    </table>
                @endforeach

                <div class="ficha-seccao">Verificações</div>
                <table class="ficha-tab">
                    <tr><th>Item</th><th class="cel-ok">OK</th><th class="cel-nok">NOK</th><th style="width:40%;">Nota</th></tr>
                    @foreach (\App\Models\FichaMedicao::VERIFICACOES as $chave => $rotulo)
                        @php($v = $ficha->verificacoes[$chave] ?? [])
                        @php($estado = $v['estado'] ?? null)
                        <tr>
                            <td>{{ $rotulo }}</td>
                            <td class="cel-ok">{{ $marca($estado, 'ok') }}</td>
                            <td class="cel-nok">{{ $marca($estado, 'nok') }}</td>
                            <td>{{ $v['nota'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </table>

                <div class="ficha-seccao">Teste de descarga de baterias</div>
                <table class="ficha-tab">
                    <tr>
                        <th>Tempo</th>
                        @foreach (\App\Models\FichaMedicao::COLS_DESCARGA as $ck => $crot)<th class="cel-num">{{ $crot }}</th>@endforeach
                    </tr>
                    @foreach (\App\Models\FichaMedicao::LINHAS_DESCARGA as $lk => $lrot)
                        <tr>
                            <td>{{ $lrot }}</td>
                            @foreach (array_keys(\App\Models\FichaMedicao::COLS_DESCARGA) as $ck)
                                <td class="cel-num">{{ $ficha->teste_descarga[$lk][$ck] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
                {{-- Gráfico das tensões Vbat+/Vbat− ao longo do teste. O componente gera SVG puro
                     (o editor mostra-o inline), mas o dompdf NÃO desenha SVG inline — despejava
                     só os rótulos numa linha. Como IMAGEM (data URI svg+xml) desenha certinho.
                     Só aparece com ≥2 valores preenchidos (o componente devolve vazio). A tag vai
                     partida ('<x'.'-…') para o compilador de componentes do Blade não a apanhar
                     dentro da string PHP. --}}
                @php($svgDescarga = trim(\Illuminate\Support\Facades\Blade::render('<x'.'-relatorios.grafico-descarga :curva="$c" :dados="$d" :largura="680" :altura="$a" />', ['c' => $ficha->descarga_curva ?? [], 'd' => $ficha->teste_descarga ?? [], 'a' => ($ficha->descarga_curva ?? []) !== [] ? 260 : 220])))
                @if ($svgDescarga !== '')
                    <div class="junto" style="margin: 4px 0 2px; text-align: center;">
                        <img src="data:image/svg+xml;base64,{{ base64_encode($svgDescarga) }}" alt="Gráfico do teste de descarga" style="width: 100%;">
                        <div class="legenda-grafico">Gráfico do teste de descarga — tensão das baterias (Vbat+ / Vbat−) ao longo do teste</div>
                    </div>
                @endif
                <table class="ficha-tab">
                    <tr>
                        <td style="width:60%;">Baterias em funcionamento</td>
                        <td class="cel-ok">{{ $marca($ficha->baterias_funcionamento, 'ok') }}</td>
                        <td class="cel-nok">{{ $marca($ficha->baterias_funcionamento, 'nok') }}</td>
                    </tr>
                </table>

                <div class="ficha-seccao">Relatório final</div>
                <table class="ficha-tab">
                    <tr><th>Item</th><th class="cel-ok">OK</th><th class="cel-nok">NOK</th></tr>
                    <tr>
                        <td>Equipamento a suportar a carga e sem anomalias</td>
                        <td class="cel-ok">{{ $marca($ficha->carga_a_funcionar, 'ok') }}</td>
                        <td class="cel-nok">{{ $marca($ficha->carga_a_funcionar, 'nok') }}</td>
                    </tr>
                    <tr>
                        <td>Equipamento com status carga no inversor</td>
                        <td class="cel-ok">{{ $marca($ficha->ups_modo_normal, 'ok') }}</td>
                        <td class="cel-nok">{{ $marca($ficha->ups_modo_normal, 'nok') }}</td>
                    </tr>
                </table>
                @if (trim((string) $ficha->notas_finais) !== '')
                    <table class="ficha-tab">
                        <tr><td><span class="ficha-rot">Notas finais</span><br>{{ $ficha->notas_finais }}</td></tr>
                    </table>
                @endif
                @if (trim((string) $ficha->recomendacao) !== '')
                    <div class="ficha-seccao">Recomendações e próximos passos</div>
                    <table class="ficha-tab">
                        <tr>
                            <td style="width:78%;">{{ $ficha->recomendacao }}</td>
                            <td><span class="ficha-rot">Prioridade</span><br>{{ $ficha->prioridade ?: 'Normal' }}</td>
                        </tr>
                    </table>
                @endif
                @endif {{-- /ramo UPS vs SADEI --}}

                {{-- Fotografias DESTE equipamento (junto das medições). --}}
                @php($fotosEq = ($fotosPorEquipamento[$ficha->equipamento_id]['fotos'] ?? []))
                @if (count($fotosEq))
                    @include('pdf._fotos', ['fotos' => $fotosEq, 'titulo' => 'Fotografias'])
                @endif

                {{-- ASSINATURAS — sempre o ÚLTIMO bloco da ficha (depois das fotos), como numa
                     folha de obra em papel. Só nas fichas SADEI (deteção de incêndio). --}}
                @php($assinaturas = ($assinaturasFichas ?? [])[$ficha->id] ?? [])
                @if (($assinaturas['cliente'] ?? null) || ($assinaturas['tecnico'] ?? null) || $ficha->assinado_em)
                    <div class="junto">
                    <div class="ficha-seccao">Assinaturas</div>
                    <table class="assin-tab">
                        <tr>
                            <td style="width:50%;">
                                <div class="assin-area">
                                    @if ($assinaturas['cliente'] ?? null)
                                        <img src="{{ $assinaturas['cliente'] }}" alt="Assinatura do cliente" style="max-height:56px; max-width:90%;">
                                    @endif
                                </div>
                                <div class="ficha-rot" style="margin-top:4px;">Cliente</div>
                                <div class="assin-nome">{{ $ficha->assinatura_cliente_nome ?: '—' }}</div>
                            </td>
                            <td>
                                <div class="assin-area">
                                    @if ($assinaturas['tecnico'] ?? null)
                                        <img src="{{ $assinaturas['tecnico'] }}" alt="Assinatura do técnico" style="max-height:56px; max-width:90%;">
                                    @endif
                                </div>
                                <div class="ficha-rot" style="margin-top:4px;">Técnico</div>
                                <div class="assin-nome">{{ $ficha->assinatura_tecnico_nome ?: '—' }}</div>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2"><span class="ficha-rot">Data</span><br>{{ $ficha->assinado_em?->format('d/m/Y H:i') ?? ($i->data_inicio?->format('d/m/Y') ?? '—') }}</td>
                        </tr>
                    </table>
                    </div>
                @endif
            </div>
        @endforeach
    @endif

    {{-- Fotos de equipamentos SEM ficha (não mostradas acima) + fotos gerais (relatórios antigos). --}}
    @php($idsComFicha = $fichas->pluck('equipamento_id')->map(fn ($id) => (int) $id)->all())
    @foreach (($fotosPorEquipamento ?? []) as $equipId => $grupo)
        @if (! in_array((int) $equipId, $idsComFicha, true) && count($grupo['fotos']))
            @include('pdf._fotos', ['fotos' => $grupo['fotos'], 'titulo' => 'Fotografias — '.$grupo['nome'], 'h2' => true])
        @endif
    @endforeach
    @if (count($fotosGerais ?? []))
        @include('pdf._fotos', ['fotos' => $fotosGerais, 'titulo' => 'Registo Fotográfico', 'h2' => true])
    @endif
</body>
</html>
