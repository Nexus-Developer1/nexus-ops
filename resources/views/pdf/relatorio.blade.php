<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1f2937; margin: 0; }
        .cabecalho { border-bottom: 3px solid #16A34A; padding-bottom: 10px; margin-bottom: 18px; }
        .suite { color: #9ca3af; font-size: 8px; letter-spacing: 2px; margin-top: 3px; }
        .num { font-size: 15px; font-weight: bold; color: #111827; }
        .data { color: #6b7280; font-size: 10px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #16A34A; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin: 18px 0 8px; }
        .campo-rotulo { color: #6b7280; font-size: 9px; text-transform: uppercase; }
        .campo-valor { color: #111827; font-weight: bold; font-size: 11px; }
        .cliente-linha { color: #374151; font-size: 10px; line-height: 1.4; }
        table { width: 100%; border-collapse: collapse; }
        .grelha td { padding: 4px 0; vertical-align: top; width: 50%; }
        .texto { color: #374151; line-height: 1.5; white-space: pre-line; } /* respeita as quebras de linha escritas pelo técnico */
        /* A informação técnica começa sempre numa página nova — a 1ª página é só o resumo. */
        .pagina-tecnica { page-break-before: always; }
        .item { padding: 3px 0 3px 10px; }
        .marca-check { color: #16A34A; font-weight: bold; }
        .marca-vazio { color: #9ca3af; }
        .etapa-titulo { margin-top: 8px; font-weight: bold; color: #374151; font-size: 11px; }
        .etapa-contador { color: #9ca3af; font-weight: normal; font-size: 9px; }
        .item-obs { color: #6b7280; }
        /* Fotos em grelha de tabela (4/linha) — ver pdf/_fotos.blade.php. */
        .fotos-tab { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
        .foto-cel { width: 25%; padding: 0 6px 6px 0; }
        .foto { width: 100%; height: 110px; object-fit: cover; border: 1px solid #e5e7eb; }
        .rodape { margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 8px; text-align: center; color: #9ca3af; font-size: 8px; letter-spacing: 1px; }
        .etiqueta { background: #ECFDF3; color: #166534; font-size: 9px; padding: 2px 6px; border-radius: 3px; }
        /* Ficha de medições (folha Nexus) — uma por página. */
        .ficha-pagina { page-break-before: always; }
        .ficha-titulo { font-size: 14px; font-weight: bold; color: #111827; margin: 0 0 2px; }
        .ficha-sub { color: #6b7280; font-size: 10px; margin-bottom: 6px; }
        .ficha-seccao { background-color: #16A34A; color: #ffffff; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; padding: 4px 8px; margin: 12px 0 6px; }
        .ficha-tab { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .ficha-tab td, .ficha-tab th { border: 1px solid #d1d5db; padding: 3px 6px; font-size: 10px; vertical-align: top; }
        .ficha-tab th { background-color: #f3f4f6; color: #374151; font-weight: bold; text-align: left; }
        .ficha-rot { color: #9ca3af; font-size: 8px; text-transform: uppercase; }
        .cel-num { text-align: center; }
        .cel-ok { text-align: center; color: #16A34A; font-weight: bold; width: 8%; }
        .cel-na { text-align: center; color: #6b7280; font-weight: bold; width: 8%; } /* N/A (ficha SADEI) */
        /* Grelha de caixas das medições — espelho do formulário (3 grupos por linha). */
        .med-grid { width: 100%; border-collapse: separate; border-spacing: 3px 2px; }
        .med-cel { width: 33.33%; vertical-align: top; }
        .med-caixa { border: 1px solid #d1d5db; border-radius: 4px; padding: 5px 7px; }
        .med-titulo { font-size: 9px; font-weight: bold; color: #374151; margin-bottom: 3px; }
        .med-vals { width: 100%; border-collapse: collapse; }
        .med-vals td { padding: 0 6px 0 0; vertical-align: bottom; }
        .med-rot { color: #9ca3af; font-size: 7px; text-transform: uppercase; }
        .med-val { font-size: 10px; color: #111827; border-bottom: 1px solid #e5e7eb; padding: 1px 2px 2px; }
        .temp-alerta { color: #dc2626; font-weight: bold; } /* temperatura acima de 25 °C */
        .cel-nok { text-align: center; color: #dc2626; font-weight: bold; width: 8%; }
    </style>
</head>
<body>
    @php($i = $relatorio->intervencao)
    @php($e = $i->equipamento)
    {{-- local pode ser null (equipamento "por associar" do PHC) — o PDF não pode rebentar. --}}
    @php($c = $e->local?->cliente)

    <div class="cabecalho">
        <table>
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
                    <div class="num">Relatório {{ $relatorio->numero }}</div>
                    <div class="data">{{ $relatorio->data->format('d/m/Y') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div style="font-size:16px; font-weight:bold; color:#111827;">Relatório de Intervenção Técnica</div>

    <h2>Cliente</h2>
    <table class="grelha">
        <tr>
            <td>
                <div class="campo-rotulo">Cliente</div>
                <div class="campo-valor">{{ $c?->nome ?? '—' }}</div>
            </td>
            <td>
                {{-- Local: o cliente final (equipamento instalado num cliente do cliente) ou a
                     sede da empresa (morada do ERP) — nunca o local da intervenção. --}}
                @php($eCliFinal = trim((string) ($e->cliente_final ?? '')))
                @php($sede = collect([trim((string) $c?->morada), trim((string) $c?->codpost)])->filter(fn ($s) => $s !== '')->implode(' · '))
                <div class="campo-rotulo">Local</div>
                <div class="campo-valor">{{ $eCliFinal !== '' ? $eCliFinal : ($sede !== '' ? $sede : '—') }}</div>
            </td>
        </tr>
        @if ($i->contrato)
            {{-- Relatório no âmbito de um contrato. Individual (sem contrato) → linha omitida. --}}
            <tr>
                <td colspan="2"><div class="campo-rotulo">Contrato</div><div class="campo-valor">{{ $i->contrato->numero }} · {{ $i->contrato->tipo->rotulo() }}</div></td>
            </tr>
        @endif
    </table>

    <h2>Intervenção</h2>
    <table class="grelha">
        <tr>
            <td><div class="campo-rotulo">Tipo</div><div class="campo-valor">{{ $i->tipo->rotulo() }}</div></td>
            {{-- Principal + colaboradores, sem repetir, para o campo "Técnicos". --}}
            @php($nomesTecnicos = collect([$i->tecnico?->nome])->merge($i->tecnicos->pluck('nome'))->filter()->unique()->implode(', '))
            <td><div class="campo-rotulo">{{ $i->tecnicos->isEmpty() ? 'Técnico' : 'Técnicos' }}</div><div class="campo-valor">{{ $nomesTecnicos ?: '—' }}</div></td>
        </tr>
        <tr>
            {{-- Datas + horas ESCRITAS pelo técnico. Antes mostrava data_inicio com H:i
                 (sempre 00:00 — a data não tem hora) e data_fim era o instante em que se
                 FINALIZOU o relatório; agora data_fim é o término real vindo do formulário. --}}
            @php($hIni = $i->hora_inicio ? substr($i->hora_inicio, 0, 5) : null)
            @php($hFim = $i->hora_fim ? substr($i->hora_fim, 0, 5) : null)
            <td><div class="campo-rotulo">Início</div><div class="campo-valor">{{ $i->data_inicio?->format('d/m/Y') ?? '—' }}{{ $hIni ? " · $hIni" : '' }}</div></td>
            <td><div class="campo-rotulo">Término</div><div class="campo-valor">{{ $i->data_fim?->format('d/m/Y') ?? ($i->data_inicio?->format('d/m/Y') ?? '—') }}{{ $hFim ? " · $hFim" : '' }}</div></td>
        </tr>
    </table>

    @if ($i->descricao_problema)
        <div style="margin-top:8px;"><div class="campo-rotulo">Problema reportado</div><div class="texto">{{ $i->descricao_problema }}</div></div>
    @endif
    @if ($i->trabalho_realizado)
        <div style="margin-top:8px;"><div class="campo-rotulo">Trabalho realizado</div><div class="texto">{{ $i->trabalho_realizado }}</div></div>
    @endif
    @if ($i->observacoes)
        <div style="margin-top:8px;"><div class="campo-rotulo">Observações</div><div class="texto">{{ $i->observacoes }}</div></div>
    @endif


    {{-- ===== PÁGINA TÉCNICA — tudo o que é técnico começa aqui (a 1ª página é só o resumo:
         cliente, local, intervenção e textos). As fichas de medição seguem-se, uma por página. --}}
    {{-- Identificação do equipamento (S/N, fabricante, tipo) saiu do relatório a pedido da
         equipa — a ficha de medições já identifica cada equipamento. Ficam só os extras.
         A página só existe quando tem conteúdo — vazia deixava uma página em branco entre
         o resumo e as fichas (a quebra dela somava-se à da 1ª ficha). --}}
    @php($eLocaliz = trim((string) ($e->localizacao_instalacao ?? '')))
    @php($eComponentes = collect($e->atributos['componentes'] ?? [])->filter(fn ($comp) => trim((string) ($comp['designacao'] ?? '')) !== ''))
    @php($temExtrasEquipamento = $eCliFinal !== '' || $eLocaliz !== '' || $eComponentes->isNotEmpty() || $i->equipamentosCobertos->isNotEmpty())
    @php($temChecklist = $i->fichasMedicao->isEmpty() && ($i->checklistEtapas->count() || $i->checklistItens->count()))
    @if ($temExtrasEquipamento || $temChecklist)
    <div class="pagina-tecnica">
        @if ($temExtrasEquipamento)
        <h2>Equipamento</h2>
        <table class="grelha">
            {{-- Cliente final / localização do equipamento (campos explícitos) — só quando preenchidos. --}}
            @if ($eCliFinal !== '' || $eLocaliz !== '')
                <tr>
                    <td><div class="campo-rotulo">Cliente final</div><div class="campo-valor">{{ $eCliFinal !== '' ? $eCliFinal : '—' }}</div></td>
                    <td><div class="campo-rotulo">Localização da instalação</div><div class="campo-valor">{{ $eLocaliz !== '' ? $eLocaliz : '—' }}</div></td>
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
            @if ($i->equipamentosCobertos->isNotEmpty())
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

    {{-- Checklist antiga: só quando NÃO há fichas de medição (relatórios legados). Os relatórios
         novos (contrato ou individual) usam as fichas por equipamento (abaixo). --}}
    @if ($temChecklist)
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
    @endif
    </div>{{-- /pagina-tecnica --}}
    @endif

    {{-- As fotos passaram para JUNTO das medições de cada equipamento (na ficha, abaixo); as de
         relatórios antigos (sem equipamento) e as de equipamentos sem ficha saem no fim. --}}

    {{-- ===== FICHAS DE MEDIÇÃO — uma por página (contrato e individual) =====
         Nota: usar sempre a forma INLINE do PHP (como o resto desta view); um bloco raw
         de PHP partiria a compilação do Blade. --}}
    @if ($i->fichasMedicao->isNotEmpty())
        @foreach ($i->fichasMedicao as $ficha)
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
                @if ($ficha->tipo_equipamento === 'incendio' || ($fe?->tipo ?? null) === \App\Enums\TipoEquipamento::Incendio)
                    {{-- Equipamentos de incêndio: Ficha de Verificações SADEI (folha própria). --}}
                    @include('pdf._ficha_sadei')
                @else
                <div class="ficha-titulo">Ficha de Medições — UPS</div>
                <div class="ficha-sub">Relatório {{ $relatorio->numero }} · {{ $ficha->serie ?: ($fe?->numero_serie ?? '—') }}</div>

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
                                                    {{-- Temperatura acima de 25 °C sai a vermelho (alerta visual). --}}
                                                    @php($tempAlta = $campo === 'temperatura' && is_numeric($ficha->temperatura) && (float) $ficha->temperatura > 25)
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
                            <td class="cel-ok">{{ $estado === 'ok' ? 'X' : '' }}</td>
                            <td class="cel-nok">{{ $estado === 'nok' ? 'X' : '' }}</td>
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
                <table class="ficha-tab">
                    <tr>
                        <td style="width:60%;">Baterias em funcionamento</td>
                        <td class="cel-ok">{{ $ficha->baterias_funcionamento === 'ok' ? 'X' : '' }}</td>
                        <td class="cel-nok">{{ $ficha->baterias_funcionamento === 'nok' ? 'X' : '' }}</td>
                    </tr>
                </table>

                <div class="ficha-seccao">Relatório final</div>
                <table class="ficha-tab">
                    <tr><th>Item</th><th class="cel-ok">OK</th><th class="cel-nok">NOK</th></tr>
                    <tr>
                        <td>Equipamento a suportar a carga e sem anomalias</td>
                        <td class="cel-ok">{{ $ficha->carga_a_funcionar === 'ok' ? 'X' : '' }}</td>
                        <td class="cel-nok">{{ $ficha->carga_a_funcionar === 'nok' ? 'X' : '' }}</td>
                    </tr>
                    <tr>
                        <td>Equipamento com status carga no inversor</td>
                        <td class="cel-ok">{{ $ficha->ups_modo_normal === 'ok' ? 'X' : '' }}</td>
                        <td class="cel-nok">{{ $ficha->ups_modo_normal === 'nok' ? 'X' : '' }}</td>
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
                    <div class="ficha-seccao">Fotografias</div>
                    @include('pdf._fotos', ['fotos' => $fotosEq])
                @endif

                {{-- ASSINATURAS — sempre o ÚLTIMO bloco da ficha (depois das fotos), como numa
                     folha de obra em papel. Só nas fichas SADEI (deteção de incêndio). --}}
                @php($assinaturas = ($assinaturasFichas ?? [])[$ficha->id] ?? [])
                @if (($assinaturas['cliente'] ?? null) || ($assinaturas['tecnico'] ?? null) || $ficha->assinado_em)
                    <div class="ficha-seccao">Assinaturas</div>
                    <table class="ficha-tab">
                        <tr>
                            <td style="width:50%; height:70px; vertical-align:bottom;">
                                @if ($assinaturas['cliente'] ?? null)
                                    <img src="{{ $assinaturas['cliente'] }}" alt="Assinatura do cliente" style="max-height:60px; max-width:100%;">
                                @endif
                            </td>
                            <td style="height:70px; vertical-align:bottom;">
                                @if ($assinaturas['tecnico'] ?? null)
                                    <img src="{{ $assinaturas['tecnico'] }}" alt="Assinatura do técnico" style="max-height:60px; max-width:100%;">
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><span class="ficha-rot">Cliente</span><br>{{ $ficha->assinatura_cliente_nome ?: '—' }}</td>
                            <td><span class="ficha-rot">Técnico</span><br>{{ $ficha->assinatura_tecnico_nome ?: '—' }}</td>
                        </tr>
                        <tr>
                            <td colspan="2"><span class="ficha-rot">Data</span><br>{{ $ficha->assinado_em?->format('d/m/Y H:i') ?? ($i->data_inicio?->format('d/m/Y') ?? '—') }}</td>
                        </tr>
                    </table>
                @endif
            </div>
        @endforeach
    @endif

    {{-- Fotos de equipamentos SEM ficha (não mostradas acima) + fotos gerais (relatórios antigos). --}}
    @php($idsComFicha = $i->fichasMedicao->pluck('equipamento_id')->map(fn ($id) => (int) $id)->all())
    @foreach (($fotosPorEquipamento ?? []) as $equipId => $grupo)
        @if (! in_array((int) $equipId, $idsComFicha, true) && count($grupo['fotos']))
            <h2>Fotografias — {{ $grupo['nome'] }}</h2>
            @include('pdf._fotos', ['fotos' => $grupo['fotos']])
        @endif
    @endforeach
    @if (count($fotosGerais ?? []))
        <h2>Registo Fotográfico</h2>
        @include('pdf._fotos', ['fotos' => $fotosGerais])
    @endif

    <div class="rodape">
        NEXUS SOLUTIONS OPERATIONS · Documento gerado em {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
