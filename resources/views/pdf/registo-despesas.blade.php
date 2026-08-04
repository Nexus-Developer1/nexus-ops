<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <style>
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
        .nota { font-size: 7.5px; color: #374151; padding-top: 3px; }
        .resumo td { border: 1px solid #111827; padding: 3px 6px; font-size: 9px; }
        .resumo .rot { font-weight: bold; text-transform: uppercase; }
        .suite { color: #9ca3af; font-size: 7px; letter-spacing: 2px; margin-top: 2px; }
    </style>
</head>
<body>
    @php($colunas = \App\Models\Despesa::CATEGORIAS)
    @php($linhas = $registo->linhas())
    @php($totais = array_fill(0, count($colunas), 0.0))
    @php(collect($linhas)->each(function ($l) use (&$totais) { foreach ($l['valores'] as $i => $v) { if (is_numeric($v)) { $totais[$i] += (float) $v; } } }))
    @php($total = array_sum($totais))
    @php($eur = fn ($v) => is_numeric($v) && (float) $v > 0 ? number_format((float) $v, 2, ',', ' ') . ' €' : '')

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
            <th rowspan="2" style="width: 9%;">Refeições <span style="text-transform: none;">a)</span></th>
            <th rowspan="2" style="width: 10%;">Táxi · Comboio<br>Avião, etc</th>
            <th rowspan="2" style="width: 10%;">Outras despesas <span style="text-transform: none;">b)</span></th>
        </tr>
        <tr>
            <th style="width: 9%;">Combustíveis</th>
            <th style="width: 9%;">Outros</th>
        </tr>
        <tr class="sub">
            <td></td>
            <td class="sub">(cliente - localidade)</td>
            <td colspan="6"></td>
        </tr>
        @foreach ($linhas as $linha)
            <tr>
                <td class="dia">{{ \Illuminate\Support\Carbon::parse($linha['data'])->format('d/m/Y') }}</td>
                <td>{{ $linha['descricao'] }}</td>
                @foreach ($colunas as $i => $c)
                    <td class="num">{{ $eur($linha['valores'][$i] ?? '') }}{{ $c === 'Refeições' && ($linha['valores'][$i] ?? '') !== '' && $linha['refeicao_tipo'] !== '' ? ' (' . $linha['refeicao_tipo'] . ')' : '' }}</td>
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

    <div class="nota">
        a) INDICAR: A - ALMOÇO · J - JANTAR (sempre que incluir refeições com outros colaboradores, indicar em descrição o respetivo nome.)<br>
        b) Especificar em descrição.
    </div>

    {{-- Resumo (rodapé da folha). --}}
    <table class="resumo" style="width: 42%; margin-left: 58%; margin-top: 8px;">
        <tr><td class="rot">Total despesas</td><td class="num">{{ number_format($total, 2, ',', ' ') }} €</td></tr>
    </table>
</body>
</html>
