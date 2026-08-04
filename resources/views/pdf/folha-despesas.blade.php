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
        .dia { text-align: center; width: 5%; }
        .tot td { font-weight: bold; }
        .nota { font-size: 7.5px; color: #374151; padding-top: 3px; }
        .resumo td { border: 1px solid #111827; padding: 3px 6px; font-size: 9px; }
        .resumo .rot { font-weight: bold; text-transform: uppercase; }
        .suite { color: #9ca3af; font-size: 7px; letter-spacing: 2px; margin-top: 2px; }
    </style>
</head>
<body>
    @php($colunas = \App\Models\FolhaDespesa::COLUNAS)
    @php($despesas = $folha->despesas()->get())
    @php($porDia = $despesas->groupBy(fn ($d) => (int) $d->data->format('j')))
    @php($totais = $folha->totaisPorColuna())
    @php($total = array_sum($totais))
    @php($eur = fn ($v) => $v > 0 ? number_format($v, 2, ',', ' ') . ' €' : '')

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
                        <td>{{ $folha->colaborador?->nome ?? '—' }}</td>
                        <td class="rot">Matrícula</td>
                        <td>{{ $folha->matricula ?? '' }}</td>
                    </tr>
                    <tr>
                        <td class="rot">Departamento</td>
                        <td>{{ $folha->departamento ?? '' }}</td>
                        <td class="rot">Data</td>
                        <td>{{ ucfirst($folha->rotuloMes()) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Grelha de despesas: uma linha por dia do mês, colunas da folha. --}}
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
        @for ($dia = 1; $dia <= $folha->diasDoMes(); $dia++)
            @php($doDia = $porDia->get($dia, collect()))
            @php($descricao = $doDia->first(fn ($d) => ! in_array($d->descricao, $colunas, true))->descricao ?? '')
            <tr>
                <td class="dia">{{ $dia }}</td>
                <td>{{ $descricao }}</td>
                @foreach ($colunas as $coluna)
                    <td class="num">{{ $eur((float) $doDia->where('categoria', $coluna)->sum('valor')) }}</td>
                @endforeach
            </tr>
        @endfor
        <tr class="tot">
            <td colspan="2" style="text-align: right; text-transform: uppercase;">Euros</td>
            @foreach ($colunas as $coluna)
                <td class="num">{{ number_format($totais[$coluna], 2, ',', ' ') }} €</td>
            @endforeach
        </tr>
    </table>

    <div class="nota">
        a) INDICAR: A - ALMOÇO · J - JANTAR (sempre que incluir refeições com outros colaboradores, indicar em descrição o respetivo nome.)<br>
        b) Especificar em descrição.
    </div>

    {{-- Resumo (rodapé da folha): total, adiantado, a devolver / a receber. --}}
    <table class="resumo" style="width: 42%; margin-left: 58%; margin-top: 8px;">
        <tr><td class="rot">Total despesas</td><td class="num">{{ number_format($total, 2, ',', ' ') }} €</td></tr>
        <tr><td class="rot">Adiantado</td><td class="num">{{ number_format((float) $folha->adiantado, 2, ',', ' ') }} €</td></tr>
        <tr><td class="rot">A devolver</td><td class="num">{{ number_format($folha->aDevolver(), 2, ',', ' ') }} €</td></tr>
        <tr><td class="rot">Total: a receber</td><td class="num">{{ number_format($folha->aReceber(), 2, ',', ' ') }} €</td></tr>
    </table>
</body>
</html>
