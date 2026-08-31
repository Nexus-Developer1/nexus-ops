@props([
    // Valores do teste de descarga: [linha => [col => valor]] (linhas/cols de FichaMedicao).
    'dados' => [],
    'largura' => 560,
    'altura' => 230,
])

@php
    use App\Models\FichaMedicao;

    // Pontos por série (Vbat+ / Vbat−), pela ordem das linhas da tabela (Início, 1 min, …).
    // Aceita vírgula decimal; ignora células vazias/não numéricas. SVG puro (polyline, line,
    // text) — desenha igual no browser e no DomPDF, sem JavaScript.
    $series = ['vbat_pos' => ['rotulo' => 'Vbat +', 'cor' => '#2563eb'], 'vbat_neg' => ['rotulo' => 'Vbat −', 'cor' => '#ea580c']];
    $linhas = array_keys(FichaMedicao::LINHAS_DESCARGA);
    $pontos = [];
    foreach ($series as $col => $s) {
        foreach (array_values($linhas) as $i => $lk) {
            $bruto = str_replace(',', '.', trim((string) ($dados[$lk][$col] ?? '')));
            if ($bruto !== '' && is_numeric($bruto)) {
                $pontos[$col][$i] = (float) $bruto;
            }
        }
    }
    $todos = array_merge(...array_values(array_map('array_values', $pontos ?: [[]])));
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

        $mEsq = 46; $mDir = 10; $mTopo = 12; $mFundo = 34;
        $w = $largura - $mEsq - $mDir;
        $h = $altura - $mTopo - $mFundo;
        $n = count($linhas);
        $x = fn (int $i) => $mEsq + ($n > 1 ? $w * $i / ($n - 1) : 0);
        $y = fn (float $v) => $mTopo + $h - $h * ($v - $yMin) / ($yMax - $yMin);
        $decimais = $passo < 1 ? 1 : 0;
    @endphp
    <svg width="{{ $largura }}" height="{{ $altura }}" viewBox="0 0 {{ $largura }} {{ $altura }}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Gráfico do teste de descarga (Vbat)">
        {{-- Grelha e eixo Y --}}
        @for ($v = $yMin; $v <= $yMax + $passo / 2; $v += $passo)
            <line x1="{{ $mEsq }}" y1="{{ round($y($v), 1) }}" x2="{{ $largura - $mDir }}" y2="{{ round($y($v), 1) }}" stroke="#e5e7eb" stroke-width="1" />
            <text x="{{ $mEsq - 6 }}" y="{{ round($y($v) + 3, 1) }}" text-anchor="end" font-size="9" fill="#6b7280">{{ number_format($v, $decimais, ',', '') }}</text>
        @endfor

        {{-- Rótulos do eixo X (tempos da tabela) --}}
        @foreach (array_values(FichaMedicao::LINHAS_DESCARGA) as $i => $rotulo)
            <text x="{{ round($x($i), 1) }}" y="{{ $altura - $mFundo + 14 }}" text-anchor="middle" font-size="9" fill="#6b7280">{{ $rotulo }}</text>
        @endforeach

        {{-- Séries --}}
        @foreach ($series as $col => $s)
            @php($pts = collect($pontos[$col] ?? [])->map(fn ($v, $i) => round($x($i), 1).','.round($y($v), 1))->implode(' '))
            @if (($pontos[$col] ?? []) !== [])
                @if (count($pontos[$col]) > 1)
                    <polyline points="{{ $pts }}" fill="none" stroke="{{ $s['cor'] }}" stroke-width="2" />
                @endif
                @foreach ($pontos[$col] as $i => $v)
                    <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y($v), 1) }}" r="2.5" fill="{{ $s['cor'] }}" />
                @endforeach
            @endif
        @endforeach

        {{-- Legenda --}}
        @foreach (array_values($series) as $i => $s)
            @php($lx = $mEsq + $i * 90)
            <line x1="{{ $lx }}" y1="{{ $altura - 6 }}" x2="{{ $lx + 18 }}" y2="{{ $altura - 6 }}" stroke="{{ $s['cor'] }}" stroke-width="2" />
            <text x="{{ $lx + 23 }}" y="{{ $altura - 3 }}" font-size="9" fill="#374151">{{ $s['rotulo'] }}</text>
        @endforeach
    </svg>
@endif
