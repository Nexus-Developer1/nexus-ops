@props([
    // Curva importada do ficheiro do carregador: [{t: "20:02:51", p: Vbat+, n: Vbat−}, ...].
    // É a fonte PREFERIDA do gráfico (o desenho fica igual ao do Excel da equipa).
    'curva' => [],
    // Último recurso: valores da tabela manual [linha => [col => valor]] (FichaMedicao).
    'dados' => [],
    'largura' => 560,
    'altura' => 230,
])

@php
    use App\Models\FichaMedicao;

    // SVG puro (polyline, line, text) — desenha igual no browser e no DomPDF, sem JavaScript.
    $cores = ['p' => '#2563eb', 'n' => '#ea580c'];
    $rotulos = ['p' => 'Vbat +', 'n' => 'Vbat −'];
    $pontos = ['p' => [], 'n' => []];
    $rotulosX = [];     // posição no eixo => texto
    $comCurva = false;

    // 1) Curva do ficheiro (aceita vírgula decimal; ignora entradas inválidas).
    $amostras = collect(is_iterable($curva) ? $curva : [])
        ->map(function ($a) {
            $p = str_replace(',', '.', trim((string) ($a['p'] ?? '')));
            $n = str_replace(',', '.', trim((string) ($a['n'] ?? '')));

            return is_numeric($p) && is_numeric($n)
                ? ['t' => (string) ($a['t'] ?? ''), 'p' => (float) $p, 'n' => (float) $n]
                : null;
        })
        ->filter()
        ->values();

    if ($amostras->count() >= 2) {
        $comCurva = true;
        foreach ($amostras as $i => $a) {
            $pontos['p'][$i] = $a['p'];
            $pontos['n'][$i] = $a['n'];
        }
        // Rótulos de tempo na vertical (como no Excel): no máximo ~16, sempre com o último.
        $n = $amostras->count();
        $passoRotulo = max(1, (int) ceil($n / 16));
        for ($i = 0; $i < $n; $i += $passoRotulo) {
            $rotulosX[$i] = $amostras[$i]['t'];
        }
        $rotulosX[$n - 1] = $amostras[$n - 1]['t'];
    } else {
        // 2) Tabela manual: pontos pela ordem das linhas (Início, 1 min, …).
        $mapa = ['p' => 'vbat_pos', 'n' => 'vbat_neg'];
        $linhas = array_keys(FichaMedicao::LINHAS_DESCARGA);
        foreach ($mapa as $serie => $col) {
            foreach (array_values($linhas) as $i => $lk) {
                $bruto = str_replace(',', '.', trim((string) ($dados[$lk][$col] ?? '')));
                if ($bruto !== '' && is_numeric($bruto)) {
                    $pontos[$serie][$i] = (float) $bruto;
                }
            }
        }
        $rotulosX = array_values(FichaMedicao::LINHAS_DESCARGA);
    }

    $todos = array_merge(array_values($pontos['p']), array_values($pontos['n']));
@endphp

@if (count($todos) >= 2)
    @php
        // Escala Y com folga e passo "redondo" (1/2/5×10^n) — eixo legível tipo Excel.
        $min = min($todos); $max = max($todos);
        $amplitude = max($max - $min, 1e-9);
        $passoBruto = $amplitude / 5;
        $magnitude = pow(10, floor(log10($passoBruto)));
        $passo = collect([1, 2, 5, 10])->map(fn ($m) => $m * $magnitude)->first(fn ($p) => $p >= $passoBruto);
        $yMin = floor($min / $passo) * $passo;
        $yMax = ceil($max / $passo) * $passo;
        if ($yMin === $yMax) { $yMax = $yMin + $passo; }

        $mEsq = 46; $mDir = 10; $mTopo = 12;
        $mFundo = $comCurva ? 62 : 34; // horas na vertical precisam de mais pé
        $w = $largura - $mEsq - $mDir;
        $h = $altura - $mTopo - $mFundo;
        $nPontos = $comCurva ? count($pontos['p']) : count(FichaMedicao::LINHAS_DESCARGA);
        $x = fn (int $i) => $mEsq + ($nPontos > 1 ? $w * $i / ($nPontos - 1) : 0);
        $y = fn (float $v) => $mTopo + $h - $h * ($v - $yMin) / ($yMax - $yMin);
        $decimais = $passo < 1 ? 1 : 0;
        $baseX = $altura - $mFundo;
    @endphp
    <svg width="{{ $largura }}" height="{{ $altura }}" viewBox="0 0 {{ $largura }} {{ $altura }}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Gráfico do teste de descarga (Vbat)">
        {{-- Grelha e eixo Y --}}
        @for ($v = $yMin; $v <= $yMax + $passo / 2; $v += $passo)
            <line x1="{{ $mEsq }}" y1="{{ round($y($v), 1) }}" x2="{{ $largura - $mDir }}" y2="{{ round($y($v), 1) }}" stroke="#e5e7eb" stroke-width="1" />
            <text x="{{ $mEsq - 6 }}" y="{{ round($y($v) + 3, 1) }}" text-anchor="end" font-size="9" fill="#6b7280">{{ number_format($v, $decimais, ',', '') }}</text>
        @endfor

        {{-- Eixo X: horas do ficheiro na VERTICAL (como no Excel) ou tempos da tabela na horizontal --}}
        @foreach ($rotulosX as $i => $rotulo)
            @if ($comCurva)
                <text x="{{ round($x($i), 1) }}" y="{{ $baseX + 8 }}" transform="rotate(-90 {{ round($x($i), 1) }} {{ $baseX + 8 }})" text-anchor="end" font-size="8" fill="#6b7280">{{ $rotulo }}</text>
            @else
                <text x="{{ round($x($i), 1) }}" y="{{ $baseX + 14 }}" text-anchor="middle" font-size="9" fill="#6b7280">{{ $rotulo }}</text>
            @endif
        @endforeach

        {{-- Séries --}}
        @foreach (['p', 'n'] as $serie)
            @php($pts = collect($pontos[$serie])->map(fn ($v, $i) => round($x($i), 1).','.round($y($v), 1))->implode(' '))
            @if ($pontos[$serie] !== [])
                @if (count($pontos[$serie]) > 1)
                    <polyline points="{{ $pts }}" fill="none" stroke="{{ $cores[$serie] }}" stroke-width="{{ $comCurva ? 1.5 : 2 }}" />
                @endif
                @unless ($comCurva)
                    @foreach ($pontos[$serie] as $i => $v)
                        <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y($v), 1) }}" r="2.5" fill="{{ $cores[$serie] }}" />
                    @endforeach
                @endunless
            @endif
        @endforeach

        {{-- Legenda --}}
        @foreach (['p', 'n'] as $i => $serie)
            @php($lx = $mEsq + $i * 90)
            <line x1="{{ $lx }}" y1="{{ $altura - 6 }}" x2="{{ $lx + 18 }}" y2="{{ $altura - 6 }}" stroke="{{ $cores[$serie] }}" stroke-width="2" />
            <text x="{{ $lx + 23 }}" y="{{ $altura - 3 }}" font-size="9" fill="#374151">{{ $rotulos[$serie] }}</text>
        @endforeach
    </svg>
@endif
