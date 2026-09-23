<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <style>
        /* A margem é dita aqui e a conta do tamanho da digitalização parte dela (ver
           PdfRegistoDespesas): sem isto ficava à mercê do valor por omissão do dompdf. */
        @page { margin: {{ $margemMm ?? 12.7 }}mm; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 9px; color: #1f2937; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .cab td { border: 1px solid #111827; padding: 3px 6px; font-size: 9px; }
        .cab .rot { font-weight: bold; text-transform: uppercase; width: 16%; }
        .tab td, .tab th { border: 1px solid #111827; padding: 2.5px 5px; font-size: 8.5px; vertical-align: middle; }
        .tab th { background-color: #d1d5db; font-weight: bold; text-transform: uppercase; text-align: center; }
        .sub { background-color: #e5e7eb; font-size: 7.5px; text-align: center; }
        .num { text-align: right; white-space: nowrap; }
        .dia { text-align: center; width: 7%; white-space: nowrap; }
        .tot td { font-weight: bold; }
        .resumo td { border: 1px solid #111827; padding: 3px 6px; font-size: 9px; }
        .resumo .rot { font-weight: bold; text-transform: uppercase; }
        .suite { color: #9ca3af; font-size: 7px; letter-spacing: 2px; margin-top: 2px; }
        /* Recibos digitalizados: UM POR PÁGINA, a ocupar a página toda. Antes iam quatro
           por linha, do tamanho de um selo, e não se lia nada. */
        .recibo-pagina { text-align: center; }
        .recibo-rot { font-size: 9px; font-weight: bold; margin: 0 0 4px; text-align: left; }
        .recibo-img { border: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    @php($colunas = \App\Models\Despesa::CATEGORIAS)
    @php($linhas = $registo->linhasOrdenadas())
    @php($totais = array_fill(0, count($colunas), 0.0))
    @php($linhas->each(function ($d) use (&$totais, $colunas) { $i = array_search($d->categoria, $colunas, true); $totais[$i === false ? count($colunas) - 1 : $i] += (float) $d->valor; }))
    @php($total = array_sum($totais))
    @php($eur = fn ($v) => is_numeric($v) && (float) $v > 0 ? number_format((float) $v, 2, ',', ' ') . ' €' : '')

    @unless ($apenasRecibos ?? false)
    {{-- Cabeçalho: logótipo oficial + identificação (como na folha impressa). --}}
    <table style="margin-bottom: 8px;">
        <tr>
            <td style="width: 30%;">
                @if (is_file(public_path('img/nexus-1.png')))
                    <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('img/nexus-1.png'))) }}" alt="NEXUS" style="width: 120px;">
                @else
                    <div style="font-size: 20px; font-weight: 800; color: #16a34a;">NEXUS</div>
                @endif
                <div class="suite">TECHNICAL SUITE</div>
            </td>
            <td style="width: 70%;">
                <table class="cab">
                    <tr>
                        <td class="rot">Nome colaborador</td>
                        <td>{{ $registo->colaborador?->nome ?? '—' }}</td>
                        <td class="rot">Matrícula</td>
                        <td>{{ $registo->matricula ?? '' }}</td>
                    </tr>
                    <tr>
                        <td class="rot">Departamento</td>
                        <td>{{ $registo->departamento ?? '' }}</td>
                        <td class="rot">Data</td>
                        <td>{{ $registo->created_at?->format('d/m/Y') ?? '' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Grelha de despesas: as linhas do registo, no formato da folha. --}}
    <table class="tab">
        <tr>
            <th rowspan="2" class="dia">Dia</th>
            <th rowspan="2">Descrição</th>
            <th colspan="2">Veículos da empresa</th>
            <th rowspan="2" style="width: 9%;">Hotel</th>
            <th rowspan="2" style="width: 9%;">Refeições</th>
            <th rowspan="2" style="width: 10%;">Táxi · Comboio<br>Avião, etc</th>
            <th rowspan="2" style="width: 10%;">Outras despesas</th>
        </tr>
        <tr>
            <th style="width: 9%;">Combustíveis</th>
            <th style="width: 9%;">Outros</th>
        </tr>
        <tr class="sub">
            <td></td>
            <td class="sub">(local · serviço)</td>
            <td colspan="6"></td>
        </tr>
        @foreach ($linhas as $d)
            @php($indiceCol = array_search($d->categoria, $colunas, true))
            @php($indiceCol = $indiceCol === false ? count($colunas) - 1 : $indiceCol)
            <tr>
                <td class="dia">{{ $d->data->format('d/m/Y') }}</td>
                {{-- Descrição (local · serviço) + "o que é" quando preenchido; por baixo, a
                     negrito, quem pagou (set. 2026). Fica dentro da célula para não mexer nas
                     colunas da folha da empresa. --}}
                <td>{{ $d->descricao }}{{ $d->detalhe ? ' — ' . $d->detalhe : '' }}@if ($d->pagoPorRotulo())<br><strong>{{ $d->pagoPorRotulo() }}</strong>@endif</td>
                @foreach ($colunas as $i => $c)
                    <td class="num">{{ $i === $indiceCol ? $eur($d->valor) . ($d->refeicao_tipo ? ' (' . $d->refeicao_tipo . ')' : '') : '' }}</td>
                @endforeach
            </tr>
        @endforeach
        <tr class="tot">
            <td colspan="2" style="text-align: right; text-transform: uppercase;">Euros</td>
            @foreach ($colunas as $i => $c)
                <td class="num">{{ number_format($totais[$i], 2, ',', ' ') }} €</td>
            @endforeach
        </tr>
    </table>

    {{-- Resumo (rodapé da folha). --}}
    <table class="resumo" style="width: 42%; margin-left: 58%; margin-top: 8px;">
        <tr><td class="rot">Total despesas</td><td class="num">{{ number_format($total, 2, ',', ' ') }} €</td></tr>
    </table>
    @endunless

    {{-- Recibos digitalizados: UM POR PÁGINA, a ocupar a página toda.

         As imagens vão embebidas em base64 (o dompdf corre com enable_remote=false). Um
         ficheiro que falte no storage é saltado sem rebentar a geração, e as linhas sem
         recibos não aparecem aqui.

         O dompdf não sabe `object-fit`, por isso a conta é feita aqui: sabendo a forma da
         imagem e o espaço da página, manda-se encostar à LARGURA (imagem deitada) ou à
         ALTURA (talão, que é o caso normal). Assim enche sempre sem ficar esticada. --}}
    @php($comRecibos = $linhas->filter(fn ($d) => $d->anexos->isNotEmpty()))
    @php($formaCaixa = $caixa['largura'] / max(1, $caixa['imagem']))
    @php($primeira = true)
    @foreach ($comRecibos as $d)
        @foreach ($d->anexos as $anexo)
            @php($conteudo = \Illuminate\Support\Facades\Storage::disk()->get($anexo->storage_key))
            @continue($conteudo === null)
            @php($medidas = @getimagesizefromstring($conteudo))
            @php($forma = $medidas && ! empty($medidas[1]) ? $medidas[0] / $medidas[1] : 0.7)
            @php($estilo = $forma > $formaCaixa
                ? 'width: ' . $caixa['largura'] . 'px; height: auto;'
                : 'height: ' . $caixa['imagem'] . 'px; width: auto;')

            {{-- No PDF completo a folha vem antes, por isso o primeiro recibo também salta
                 de página; no PDF só de recibos o primeiro abre o documento. --}}
            @if (! $primeira || ! $apenasRecibos)
                <div style="page-break-before: always;"></div>
            @endif
            @php($primeira = false)

            <div class="recibo-pagina">
                <div class="recibo-rot">{{ $d->data->format('d/m/Y') }} · {{ $d->descricao }}{{ $d->detalhe ? ' — ' . $d->detalhe : '' }} · {{ $d->categoria }} · {{ number_format((float) $d->valor, 2, ',', ' ') }} €@if ($d->pagoPorRotulo()) · <strong>{{ $d->pagoPorRotulo() }}</strong>@endif</div>
                <img class="recibo-img" style="{{ $estilo }}" src="data:{{ $anexo->mime ?: 'image/jpeg' }};base64,{{ base64_encode($conteudo) }}">
            </div>
        @endforeach
    @endforeach
</body>
</html>
