<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1f2937; margin: 0; }
        .cabecalho { border-bottom: 3px solid #16A34A; padding-bottom: 10px; margin-bottom: 18px; }
        .marca { color: #16A34A; font-size: 20px; font-weight: bold; }
        .suite { color: #9ca3af; font-size: 8px; letter-spacing: 2px; }
        .num { font-size: 15px; font-weight: bold; color: #111827; }
        .data { color: #6b7280; font-size: 10px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #16A34A; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin: 18px 0 8px; }
        .campo-rotulo { color: #6b7280; font-size: 9px; text-transform: uppercase; }
        .campo-valor { color: #111827; font-weight: bold; font-size: 11px; }
        .cliente-linha { color: #374151; font-size: 10px; line-height: 1.4; }
        table { width: 100%; border-collapse: collapse; }
        .grelha td { padding: 4px 0; vertical-align: top; width: 50%; }
        .texto { color: #374151; line-height: 1.5; }
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
        .cel-nok { text-align: center; color: #dc2626; font-weight: bold; width: 8%; }
    </style>
</head>
<body>
    @php($i = $relatorio->intervencao)
    @php($e = $i->equipamento)
    @php($c = $e->local->cliente)

    <div class="cabecalho">
        <table>
            <tr>
                <td>
                    <div class="marca">Nexus Infra</div>
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

    <h2>Cliente e Equipamento</h2>
    <table class="grelha">
        <tr>
            <td>
                <div class="campo-rotulo">Cliente</div>
                <div class="campo-valor">{{ $c->nome }}</div>
                @php($cNif = trim((string) $c->nif))
                @php($cMorada = trim((string) $c->morada))
                @php($cCodpost = trim((string) $c->codpost))
                @php($cTel = trim((string) $c->telefone))
                @php($cTlm = trim((string) $c->tlmvl))
                @php($cEmail = trim((string) $c->email))
                @if ($cNif !== '')<div class="cliente-linha">NIF {{ $cNif }}</div>@endif
                @if ($cMorada !== '')<div class="cliente-linha">{{ $cMorada }}</div>@endif
                @if ($cCodpost !== '')<div class="cliente-linha">{{ $cCodpost }}</div>@endif
                @if ($cTel !== '')<div class="cliente-linha">Tel. {{ $cTel }}</div>@endif
                @if ($cTlm !== '')<div class="cliente-linha">Tlm. {{ $cTlm }}</div>@endif
                @if ($cEmail !== '')<div class="cliente-linha">{{ $cEmail }}</div>@endif
            </td>
            <td><div class="campo-rotulo">Local</div><div class="campo-valor">{{ $e->local->designacao }}</div></td>
        </tr>
        <tr>
            <td><div class="campo-rotulo">Equipamento</div><div class="campo-valor">{{ $e->numero_serie }} · {{ $e->fabricante }} {{ $e->modelo }}</div></td>
            <td><div class="campo-rotulo">Tipo</div><div class="campo-valor">{{ $e->tipo->rotulo() }}</div></td>
        </tr>
        {{-- Cliente final / localização do equipamento (campos explícitos) — só quando preenchidos. --}}
        @php($eCliFinal = trim((string) ($e->cliente_final ?? '')))
        @php($eLocaliz = trim((string) ($e->localizacao_instalacao ?? '')))
        @if ($eCliFinal !== '' || $eLocaliz !== '')
            <tr>
                <td><div class="campo-rotulo">Cliente final</div><div class="campo-valor">{{ $eCliFinal !== '' ? $eCliFinal : '—' }}</div></td>
                <td><div class="campo-rotulo">Localização da instalação</div><div class="campo-valor">{{ $eLocaliz !== '' ? $eLocaliz : '—' }}</div></td>
            </tr>
        @endif
        {{-- Componentes do sistema (equipamentos compostos, ex.: deteção de incêndio). --}}
        @php($eComponentes = collect($e->atributos['componentes'] ?? [])->filter(fn ($c) => trim((string) ($c['designacao'] ?? '')) !== ''))
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
        @if ($i->contrato)
            {{-- Relatório no âmbito de um contrato. Individual (sem contrato) → linha omitida. --}}
            <tr>
                <td colspan="2"><div class="campo-rotulo">Contrato</div><div class="campo-valor">{{ $i->contrato->numero }} · {{ $i->contrato->tipo->rotulo() }}</div></td>
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

    <h2>Intervenção</h2>
    <table class="grelha">
        <tr>
            <td><div class="campo-rotulo">Tipo</div><div class="campo-valor">{{ $i->tipo->rotulo() }}</div></td>
            {{-- Principal + colaboradores, sem repetir, para o campo "Técnicos". --}}
            @php($nomesTecnicos = collect([$i->tecnico?->nome])->merge($i->tecnicos->pluck('nome'))->filter()->unique()->implode(', '))
            <td><div class="campo-rotulo">{{ $i->tecnicos->isEmpty() ? 'Técnico' : 'Técnicos' }}</div><div class="campo-valor">{{ $nomesTecnicos ?: '—' }}</div></td>
        </tr>
        <tr>
            <td><div class="campo-rotulo">Data de início</div><div class="campo-valor">{{ $i->data_inicio?->format('d/m/Y H:i') ?? '—' }}</div></td>
            <td><div class="campo-rotulo">Data de fim</div><div class="campo-valor">{{ $i->data_fim?->format('d/m/Y H:i') ?? '—' }}</div></td>
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


    {{-- Checklist antiga: só quando NÃO há fichas de medição (relatórios legados). Os relatórios
         novos (contrato ou individual) usam as fichas por equipamento (abaixo). --}}
    @if ($i->fichasMedicao->isEmpty())
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
            @php($locDerivado = ($floc?->designacao ?? '—') . (trim((string) $floc?->morada) !== '' ? ' · ' . $floc->morada : ''))
            @php($mostrarClienteFinal = $cfTexto !== '' || ($fcli && $i->contrato && $fcli->id !== $i->contrato->cliente_id))
            @php($clienteFinalValor = $cfTexto !== '' ? $cfTexto : ($fcli?->nome ?? '—'))
            <div class="ficha-pagina">
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
                        <td><span class="ficha-rot">Data</span><br>{{ $i->data_inicio?->format('d/m/Y') ?? $relatorio->data->format('d/m/Y') }}</td>
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

                <div class="ficha-seccao">Valores elétricos</div>
                @php($tabelasE = [
                    ['Tensão de entrada L/N (V)', ['L1' => 've_ln_l1', 'L2' => 've_ln_l2', 'L3' => 've_ln_l3']],
                    ['Tensão de entrada L/L (V)', ['L1-L2' => 've_ll_l1l2', 'L1-L3' => 've_ll_l1l3', 'L2-L3' => 've_ll_l2l3']],
                    ['Carga (%)', ['L1' => 'carga_l1', 'L2' => 'carga_l2', 'L3' => 'carga_l3']],
                    ['Frequência (Hz)', ['Hz' => 'frequencia']],
                    ['Tensão de saída L/N (V)', ['L1' => 'vs_ln_l1', 'L2' => 'vs_ln_l2', 'L3' => 'vs_ln_l3']],
                    ['Tensão de saída L/L (V)', ['L1-L2' => 'vs_ll_l1l2', 'L1-L3' => 'vs_ll_l1l3', 'L2-L3' => 'vs_ll_l2l3']],
                    ['Corrente de saída (A)', ['L1' => 'is_l1', 'L2' => 'is_l2', 'L3' => 'is_l3']],
                    ['Corrente de saída pico (A)', ['L1' => 'ispico_l1', 'L2' => 'ispico_l2', 'L3' => 'ispico_l3']],
                    ['Tensão de baterias (V)', ['V+' => 'vbat_pos', 'V-' => 'vbat_neg']],
                    ['Temperatura (°C)', ['°C' => 'temperatura']],
                ])
                <table class="ficha-tab">
                    @foreach ($tabelasE as [$titulo, $campos])
                        <tr>
                            <td style="width:34%;">{{ $titulo }}</td>
                            @foreach ($campos as $lab => $campo)
                                <td class="cel-num" style="width:22%;"><span class="ficha-rot">{{ $lab }}</span> {{ $ficha->{$campo} }}</td>
                            @endforeach
                            @for ($k = count($campos); $k < 3; $k++)<td></td>@endfor
                        </tr>
                    @endforeach
                </table>

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

                {{-- Fotografias DESTE equipamento (junto das medições). --}}
                @php($fotosEq = ($fotosPorEquipamento[$ficha->equipamento_id]['fotos'] ?? []))
                @if (count($fotosEq))
                    <div class="ficha-seccao">Fotografias</div>
                    @include('pdf._fotos', ['fotos' => $fotosEq])
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
