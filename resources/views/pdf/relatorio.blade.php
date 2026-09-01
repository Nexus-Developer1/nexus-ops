<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <style>
        /* ---- Base ------------------------------------------------------------------ */
        @page { margin: 16mm 14mm 20mm 14mm; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10.5px; color: #1f2937; margin: 0; line-height: 1.35; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }

        /* Rodapé fixo em TODAS as páginas (o dompdf repete os position:fixed) com nº de página. */
        .rodape-fixo { position: fixed; bottom: -12mm; left: 0; right: 0; height: 8mm; border-top: 1px solid #e5e7eb; padding-top: 4px; font-size: 7.5px; color: #9ca3af; letter-spacing: 0.5px; }
        .rodape-fixo .pagina:after { content: counter(page); }

        /* ---- Cabeçalho + faixa de título ----------------------------------------- */
        .cabecalho td { vertical-align: middle; padding-bottom: 8px; }
        .suite { color: #9ca3af; font-size: 7.5px; letter-spacing: 2.5px; margin-top: 3px; }
        .doc-tipo { color: #6b7280; font-size: 8px; text-transform: uppercase; letter-spacing: 2px; }
        .num { font-size: 20px; font-weight: bold; color: #111827; line-height: 1.1; }
        .data { color: #6b7280; font-size: 10px; margin-top: 2px; }
        .faixa { background-color: #16A34A; color: #ffffff; padding: 10px 14px; margin: 2px 0 10px; }
        .faixa td { vertical-align: middle; }
        .faixa-titulo { font-size: 17px; font-weight: bold; letter-spacing: 0.3px; }
        .faixa-sub { font-size: 9.5px; color: #D1FADF; margin-top: 3px; }

        /* Selos de resultado (faixa + resumo + fichas). */
        .selo { display: inline-block; font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; padding: 3px 9px; border-radius: 10px; white-space: nowrap; }
        .selo-conforme { background-color: #ffffff; color: #166534; }
        .selo-anomalias { background-color: #FEE2E2; color: #B91C1C; }
        .selo-sem { background-color: #F3F4F6; color: #6b7280; }
        .ficha-pagina .selo-conforme { background-color: #DCFCE7; }

        /* ---- Cartões de informação ------------------------------------------------ */
        .cartoes { border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 6px; }
        .cartao { background-color: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 5px; padding: 7px 10px; }
        .rot { color: #6b7280; font-size: 7.5px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; }
        .val { color: #111827; font-weight: bold; font-size: 11px; }
        .sub { color: #6b7280; font-size: 9px; margin-top: 2px; }

        /* ---- Secções e textos ----------------------------------------------------- */
        h2 { font-size: 10px; text-transform: uppercase; letter-spacing: 1.2px; color: #166534; border-bottom: 1px solid #D1FADF; padding-bottom: 3px; margin: 12px 0 6px; }
        .bloco { border-left: 3px solid #16A34A; padding: 3px 0 3px 10px; margin: 4px 0 8px; }
        .bloco .rot { margin-bottom: 3px; }
        .texto { color: #374151; line-height: 1.45; white-space: pre-line; } /* respeita as quebras de linha escritas pelo técnico */
        .cliente-linha { color: #374151; font-size: 10px; line-height: 1.4; }
        .grelha td { padding: 4px 0; width: 50%; }
        .campo-rotulo { color: #6b7280; font-size: 7.5px; text-transform: uppercase; letter-spacing: 1px; }
        .campo-valor { color: #111827; font-weight: bold; font-size: 11px; }

        /* Resumo dos resultados (1.ª página). */
        .tab { width: 100%; border-collapse: collapse; }
        .tab th { background-color: #F3F4F6; color: #374151; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.6px; text-align: left; padding: 5px 7px; border-bottom: 1px solid #D1D5DB; }
        .tab td { padding: 6px 7px; border-bottom: 1px solid #E5E7EB; font-size: 10px; }
        .tab tr:nth-child(even) td { background-color: #FAFAFA; }
        .tab .mini { color: #6b7280; font-size: 8.5px; }
        .aviso { background-color: #FFFBEB; border: 1px solid #FDE68A; border-left: 4px solid #F59E0B; padding: 6px 10px; margin: 6px 0; }
        .aviso .rot { color: #B45309; }
        .recom { background-color: #ECFDF3; border: 1px solid #A6F4C5; border-left: 4px solid #16A34A; padding: 6px 10px; margin: 6px 0; }
        .recom .rot { color: #166534; }
        .lista-item { padding: 2px 0 2px 0; color: #1f2937; }
        .lista-item .quem { color: #6b7280; }

        /* A informação técnica (checklist legada) começa sempre numa página nova. */
        .pagina-tecnica { page-break-before: always; }
        .item { padding: 3px 0 3px 10px; }
        .marca-check { color: #16A34A; font-weight: bold; }
        .marca-vazio { color: #9ca3af; }
        .etapa-titulo { margin-top: 8px; font-weight: bold; color: #374151; font-size: 11px; }
        .etapa-contador { color: #9ca3af; font-weight: normal; font-size: 9px; }
        .item-obs { color: #6b7280; }

        /* ---- Fotos (grelha em tabela, 3/linha — ver pdf/_fotos.blade.php) ---------- */
        .fotos-tab { width: 100%; border-collapse: separate; border-spacing: 0 0; margin-bottom: 6px; }
        .foto-cel { width: 33.33%; padding: 0 6px 6px 0; }
        .foto { width: 100%; height: 150px; object-fit: cover; border: 1px solid #E5E7EB; border-radius: 4px; }

        /* ---- Fichas de medição (uma por página) ----------------------------------- */
        .ficha-pagina { page-break-before: always; }
        .ficha-cab { border-bottom: 2px solid #16A34A; padding-bottom: 8px; margin-bottom: 6px; }
        .ficha-cab td { vertical-align: bottom; }
        .ficha-titulo { font-size: 15px; font-weight: bold; color: #111827; margin: 0 0 2px; }
        .ficha-sub { color: #6b7280; font-size: 9.5px; }
        .ficha-seccao { background-color: #ECFDF3; border-left: 4px solid #16A34A; color: #166534; font-size: 9.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; padding: 4px 8px; margin: 12px 0 5px; }
        .ficha-tab { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .ficha-tab td, .ficha-tab th { border: 1px solid #E5E7EB; padding: 3.5px 6px; font-size: 9.5px; vertical-align: top; }
        /* Título de secção + primeiro bloco nunca se separam por uma quebra de página. */
        .junto { page-break-inside: avoid; }
        .ficha-tab th { background-color: #F3F4F6; color: #374151; font-weight: bold; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .ficha-tab tr:nth-child(even) td { background-color: #FAFAFA; }
        .ficha-rot { color: #9ca3af; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.8px; }
        .cel-num { text-align: center; }
        .cel-ok { text-align: center; color: #16A34A; font-weight: bold; width: 8%; font-size: 11px; }
        .cel-nok { text-align: center; color: #dc2626; font-weight: bold; width: 8%; font-size: 11px; }
        .cel-na { text-align: center; color: #9ca3af; font-weight: bold; width: 8%; font-size: 11px; } /* N/A (ficha SADEI) */
        /* Grelha de caixas das medições — espelho do formulário (3 grupos por linha). */
        .med-grid { width: 100%; border-collapse: separate; border-spacing: 4px 3px; margin: 0 -4px; }
        .med-cel { width: 33.33%; vertical-align: top; }
        .med-caixa { background-color: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 4px; padding: 5px 8px 6px; }
        .med-titulo { font-size: 8.5px; font-weight: bold; color: #374151; margin-bottom: 4px; }
        .med-vals { width: 100%; border-collapse: collapse; }
        .med-vals td { padding: 0 8px 0 0; vertical-align: bottom; }
        .med-rot { color: #9ca3af; font-size: 7px; text-transform: uppercase; letter-spacing: 0.5px; }
        .med-val { font-size: 11px; font-weight: bold; color: #111827; border-bottom: 1px solid #D1D5DB; padding: 1px 2px 2px; }
        .temp-alerta { color: #dc2626; } /* temperatura acima do limite */
        .legenda-grafico { text-align: center; color: #6b7280; font-size: 8px; margin-top: 2px; }
        .assin-tab td { border: 1px solid #E5E7EB; padding: 6px 8px; }
        .assin-area { height: 64px; vertical-align: bottom; text-align: center; border-bottom: 1px solid #9ca3af; }
        .assin-nome { font-weight: bold; color: #111827; font-size: 10px; }
    </style>
</head>
<body>
    @php($i = $relatorio->intervencao)
    @php($e = $i->equipamento)
    {{-- local pode ser null (equipamento "por associar" do PHC) — o PDF não pode rebentar. --}}
    @php($c = $e->local?->cliente)
    @php($fichas = $i->fichasMedicao)
    {{-- Veredicto global: uma ficha com anomalias chega para o relatório ser "com anomalias";
         "conforme" só quando TODAS as fichas têm verificações e nenhuma tem anomalias. --}}
    @php($resultados = $fichas->map(fn ($f) => $f->resultado()))
    @php($veredicto = $resultados->contains('anomalias') ? 'anomalias' : ($resultados->isNotEmpty() && $resultados->every(fn ($r) => $r === 'conforme') ? 'conforme' : null))
    @php($rotuloSelo = ['conforme' => 'Conforme', 'anomalias' => 'Com anomalias', 'sem_dados' => 'Sem verificações'])
    @php($marca = fn ($v, $alvo) => ($v ?? null) === $alvo ? (in_array($alvo, ['ko', 'nok'], true) ? '✗' : ($alvo === 'na' ? '–' : '✓')) : '')

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
                    <div style="font-size: 22px; font-weight: 800; color: #16a34a;">NEXUS</div>
                @endif
                <div class="suite">TECHNICAL SUITE</div>
            </td>
            <td align="right">
                <div class="doc-tipo">Relatório nº</div>
                <div class="num">{{ $relatorio->numero }}</div>
                <div class="data">{{ $relatorio->data->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <div class="faixa">
        <table>
            <tr>
                <td>
                    <div class="faixa-titulo">Relatório de Intervenção Técnica</div>
                    <div class="faixa-sub">Manutenção {{ strtolower($i->tipo->rotulo()) }}@if ($i->contrato) · Contrato {{ $i->contrato->numero }}@endif @if ($fichas->isNotEmpty()) · {{ $fichas->count() }} {{ $fichas->count() === 1 ? 'equipamento verificado' : 'equipamentos verificados' }}@endif</div>
                </td>
                @if ($veredicto)
                    <td align="right" style="width: 30%;"><span class="selo selo-{{ $veredicto }}">{{ $rotuloSelo[$veredicto] }}</span></td>
                @endif
            </tr>
        </table>
    </div>

    {{-- ---- Cliente / Local / Contrato ---------------------------------------------- --}}
    {{-- Local: o cliente final (equipamento instalado num cliente do cliente) ou a sede da
         empresa (morada do ERP) — nunca o local da intervenção. --}}
    @php($eCliFinal = trim((string) ($e->cliente_final ?? '')))
    @php($sede = collect([trim((string) $c?->morada), trim((string) $c?->codpost)])->filter(fn ($s) => $s !== '')->implode(' · '))
    <table class="cartoes">
        <tr>
            <td class="cartao" style="width: 34%;">
                <div class="rot">Cliente</div>
                <div class="val">{{ $c?->nome ?? '—' }}</div>
            </td>
            <td class="cartao" style="width: 33%;">
                <div class="rot">Local</div>
                <div class="val">{{ $eCliFinal !== '' ? $eCliFinal : ($sede !== '' ? $sede : '—') }}</div>
            </td>
            @if ($i->contrato)
                {{-- Relatório no âmbito de um contrato. Individual (sem contrato) → cartão de âmbito. --}}
                <td class="cartao" style="width: 33%;">
                    <div class="rot">Contrato</div>
                    <div class="val">{{ $i->contrato->numero }}</div>
                    <div class="sub">{{ $i->contrato->tipo->rotulo() }}</div>
                </td>
            @else
                <td class="cartao" style="width: 33%;">
                    <div class="rot">Âmbito</div>
                    <div class="val">Intervenção individual</div>
                    <div class="sub">Fora de contrato de manutenção</div>
                </td>
            @endif
        </tr>
    </table>

    {{-- ---- Intervenção --------------------------------------------------------------- --}}
    {{-- Principal + colaboradores, sem repetir, para o campo "Técnicos". --}}
    @php($nomesTecnicos = collect([$i->tecnico?->nome])->merge($i->tecnicos->pluck('nome'))->filter()->unique()->implode(', '))
    {{-- Datas + horas ESCRITAS pelo técnico (data_fim é o término real vindo do formulário). --}}
    @php($hIni = $i->hora_inicio ? substr($i->hora_inicio, 0, 5) : null)
    @php($hFim = $i->hora_fim ? substr($i->hora_fim, 0, 5) : null)
    <table class="cartoes">
        <tr>
            <td class="cartao" style="width: 25%;"><div class="rot">Tipo</div><div class="val">{{ $i->tipo->rotulo() }}</div></td>
            <td class="cartao" style="width: 25%;"><div class="rot">{{ $i->tecnicos->isEmpty() ? 'Técnico' : 'Técnicos' }}</div><div class="val">{{ $nomesTecnicos ?: '—' }}</div></td>
            <td class="cartao" style="width: 25%;"><div class="rot">Início</div><div class="val">{{ $i->data_inicio?->format('d/m/Y') ?? '—' }}{{ $hIni ? " · $hIni" : '' }}</div></td>
            <td class="cartao" style="width: 25%;"><div class="rot">Término</div><div class="val">{{ $i->data_fim?->format('d/m/Y') ?? ($i->data_inicio?->format('d/m/Y') ?? '—') }}{{ $hFim ? " · $hFim" : '' }}</div></td>
        </tr>
    </table>

    @if ($i->descricao_problema || $i->trabalho_realizado || $i->observacoes)
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

    {{-- ---- Resultado por equipamento (só com fichas) --------------------------------- --}}
    {{-- O cliente vê logo na 1.ª página o veredicto de cada equipamento, as anomalias e as
         recomendações — sem ter de ler as fichas técnicas que se seguem. --}}
    @if ($fichas->isNotEmpty())
        <h2>Resultado da intervenção</h2>
        <table class="tab">
            <tr><th style="width: 46%;">Equipamento</th><th style="width: 34%;">Local de instalação</th><th>Resultado</th></tr>
            @foreach ($fichas as $f)
                @php($feq = $f->equipamento)
                @php($tipoRot = $f->tipo_equipamento === 'incendio' ? 'Deteção de incêndio' : ($feq?->tipo?->rotulo() ?? 'UPS'))
                @php($nomeEq = trim(($f->marca ?: $feq?->fabricante ?? '').' '.($f->modelo ?: $feq?->modelo ?? '')))
                @php($locEq = trim((string) ($feq?->localizacao_instalacao ?? '')) ?: (trim((string) ($feq?->local?->morada ?? '')) ?: '—'))
                @php($res = $f->resultado())
                <tr>
                    <td><b>{{ $tipoRot }}</b>@if ($nomeEq !== '') · {{ $nomeEq }}@endif<div class="mini">S/N {{ $f->serie ?: ($feq?->numero_serie ?? '—') }}</div></td>
                    <td>{{ $locEq }}</td>
                    <td><span class="selo selo-{{ $res === 'sem_dados' ? 'sem' : $res }}">{{ $rotuloSelo[$res] }}</span></td>
                </tr>
            @endforeach
        </table>

        @php($anomalias = $fichas->flatMap(fn ($f) => collect($f->anomalias())->map(fn ($a) => $a + ['quem' => trim(($f->tipo_equipamento === 'incendio' ? 'Deteção de incêndio' : ($f->equipamento?->tipo?->rotulo() ?? 'UPS')).' · '.($f->serie ?: ($f->equipamento?->numero_serie ?? '')), ' ·')])))
        @if ($anomalias->isNotEmpty())
            <div class="aviso">
                <div class="rot">Anomalias detetadas ({{ $anomalias->count() }})</div>
                @foreach ($anomalias as $a)
                    <div class="lista-item">✗ {{ $a['item'] }}@if ($a['nota'] !== '') — {{ $a['nota'] }}@endif <span class="quem">({{ $a['quem'] }})</span></div>
                @endforeach
            </div>
        @endif

        @php($recomendacoes = $fichas->filter(fn ($f) => trim((string) $f->recomendacao) !== ''))
        @if ($recomendacoes->isNotEmpty())
            <div class="recom">
                <div class="rot">Recomendações e próximos passos</div>
                @foreach ($recomendacoes as $f)
                    <div class="lista-item">
                        {{ $f->recomendacao }}
                        @if ($f->tipo_equipamento !== 'incendio' && $f->prioridade)<span class="quem">· Prioridade {{ strtolower($f->prioridade) }}</span>@endif
                        @if ($fichas->count() > 1)<span class="quem">({{ $f->tipo_equipamento === 'incendio' ? 'Deteção de incêndio' : ($f->equipamento?->tipo?->rotulo() ?? 'UPS') }} · {{ $f->serie ?: ($f->equipamento?->numero_serie ?? '—') }})</span>@endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ---- Equipamento: extras (só quando preenchidos) -------------------------------- --}}
    {{-- Identificação do equipamento (S/N, fabricante, tipo) saiu do relatório a pedido da
         equipa — a ficha de medições já identifica cada equipamento. Ficam só os extras,
         aqui no resumo (antes ocupavam uma página quase vazia). --}}
    @php($eLocaliz = trim((string) ($e->localizacao_instalacao ?? '')))
    @php($eComponentes = collect($e->atributos['componentes'] ?? [])->filter(fn ($comp) => trim((string) ($comp['designacao'] ?? '')) !== ''))
    {{-- Com fichas, o cliente final, a localização e os "também cobertos" já estão na tabela de
         resultados e na identificação de cada ficha — aqui ficam só os componentes. --}}
    @php($semFichas = $fichas->isEmpty())
    @php($temExtrasEquipamento = $eComponentes->isNotEmpty() || ($semFichas && ($eCliFinal !== '' || $eLocaliz !== '' || $i->equipamentosCobertos->isNotEmpty())))
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

    {{-- ===== FICHAS DE MEDIÇÃO — uma por página (contrato e individual) =====
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
            @php($resFicha = $ficha->resultado())
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
                        <td align="right" style="width: 30%;"><span class="selo selo-{{ $resFicha === 'sem_dados' ? 'sem' : $resFicha }}">{{ $rotuloSelo[$resFicha] }}</span></td>
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
