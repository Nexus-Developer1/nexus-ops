{{-- Ficha de Verificações SADEI (equipamento de incêndio) — espelho da folha oficial 2024.
     Herda o scope do loop de fichas em pdf/relatorio.blade.php: $ficha, $relatorio, $i, $fe,
     $fcli, $locTexto, $locDerivado, $mostrarClienteFinal, $clienteFinalValor, $hIni, $hFim. --}}
@php
    use App\Models\FichaMedicao;
    $g = $ficha->sadei ?? [];
    $x = fn ($v, $alvo) => ($v ?? null) === $alvo ? 'X' : '';
@endphp

<div class="ficha-titulo">Ficha de Verificações SADEI</div>
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

<div class="ficha-seccao">Tipo de manutenção</div>
<table class="ficha-tab">
    <tr>
        <td>Trimestral</td><td class="cel-ok">{{ $x($g['tipo_manutencao'] ?? null, 'trimestral') }}</td>
        <td>Semestral</td><td class="cel-ok">{{ $x($g['tipo_manutencao'] ?? null, 'semestral') }}</td>
        <td>Anual</td><td class="cel-ok">{{ $x($g['tipo_manutencao'] ?? null, 'anual') }}</td>
    </tr>
</table>

{{-- Secções com estado + notas --}}
@foreach ([
    'Central de deteção e extinção de incêndio' => ['central', FichaMedicao::SADEI_CENTRAL, false],
    'Sistema de deteção' => ['detecao', FichaMedicao::SADEI_DETECAO, true],
    'Sistema de aspiração' => ['aspiracao', FichaMedicao::SADEI_ASPIRACAO, true],
    'Sistema por sensores' => ['sensores', FichaMedicao::SADEI_SENSORES, true],
] as $tituloSec => [$sec, $itens, $temNa])
    <div class="ficha-seccao">{{ $tituloSec }}</div>
    <table class="ficha-tab">
        <tr><th>Item</th><th class="cel-ok">OK</th><th class="cel-nok">KO</th>@if ($temNa)<th class="cel-na">N/A</th>@endif<th style="width:34%;">Notas</th></tr>
        @foreach ($itens as $k => $rotulo)
            <tr>
                <td>{{ $rotulo }}</td>
                <td class="cel-ok">{{ $x($g[$sec][$k]['estado'] ?? null, 'ok') }}</td>
                <td class="cel-nok">{{ $x($g[$sec][$k]['estado'] ?? null, 'ko') }}</td>
                @if ($temNa)<td class="cel-na">{{ $x($g[$sec][$k]['estado'] ?? null, 'na') }}</td>@endif
                <td>{{ $g[$sec][$k]['nota'] ?? '' }}</td>
            </tr>
        @endforeach
        @if ($sec === 'sensores')
            <tr><td colspan="{{ $temNa ? 4 : 3 }}"><span class="ficha-rot">Número de sensores</span></td><td>{{ $g['num_sensores'] ?? '—' }}</td></tr>
        @endif
    </table>
@endforeach

{{-- Verificações periódicas (estado apenas) --}}
@foreach ([
    'Verificação trimestral' => ['trimestral', FichaMedicao::SADEI_TRIMESTRAL],
    'Verificação semestral' => ['semestral', FichaMedicao::SADEI_SEMESTRAL],
    'Verificação anual' => ['anual', FichaMedicao::SADEI_ANUAL],
] as $tituloSec => [$sec, $itens])
    {{-- Sem o lembrete "inibir/repor": é instrução ao técnico durante a manutenção, não informação para o cliente. --}}
    <div class="ficha-seccao">{{ $tituloSec }}</div>
    <table class="ficha-tab">
        <tr><th>Item</th><th class="cel-ok">OK</th><th class="cel-nok">KO</th><th class="cel-na">N/A</th></tr>
        @foreach ($itens as $k => $rotulo)
            <tr>
                <td>{{ $rotulo }}</td>
                <td class="cel-ok">{{ $x($g[$sec][$k]['estado'] ?? null, 'ok') }}</td>
                <td class="cel-nok">{{ $x($g[$sec][$k]['estado'] ?? null, 'ko') }}</td>
                <td class="cel-na">{{ $x($g[$sec][$k]['estado'] ?? null, 'na') }}</td>
            </tr>
        @endforeach
    </table>
@endforeach

{{-- Dados dos cilindros --}}
<div class="ficha-seccao">Dados dos cilindros</div>
@foreach ([['Agente extintor', 'tipo_agente', 'cilindros'], ['Piloto', 'tipo_piloto', 'piloto']] as [$tituloGrelha, $campoTipo, $grelha])
    @php($linhas = $g[$grelha] ?? [])
    <table class="ficha-tab">
        <tr><td colspan="7"><span class="ficha-rot">Tipo de {{ strtolower($tituloGrelha) }}</span><br>{{ $g[$campoTipo] ?? '—' }}</td></tr>
        <tr>
            @foreach (FichaMedicao::SADEI_COLS_CILINDRO as $rotuloCol)<th>{{ $rotuloCol }}</th>@endforeach
            <th class="cel-ok">OK</th><th class="cel-nok">KO</th>
        </tr>
        @forelse ($linhas as $linha)
            <tr>
                @foreach (array_keys(FichaMedicao::SADEI_COLS_CILINDRO) as $col)<td>{{ $linha[$col] ?? '' }}</td>@endforeach
                <td class="cel-ok">{{ $x($linha['estado'] ?? null, 'ok') }}</td>
                <td class="cel-nok">{{ $x($linha['estado'] ?? null, 'ko') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="color:#9ca3af;">—</td></tr>
        @endforelse
    </table>
@endforeach

{{-- Relatório final --}}
<div class="ficha-seccao">Relatório final</div>
<table class="ficha-tab">
    <tr><th>Item</th><th class="cel-ok">OK</th><th class="cel-nok">KO</th></tr>
    <tr>
        <td>Equipamento em modo automático, com a solenoide colocada, e a funcionar corretamente</td>
        <td class="cel-ok">{{ $x($g['final_automatico'] ?? null, 'ok') }}</td>
        <td class="cel-nok">{{ $x($g['final_automatico'] ?? null, 'ko') }}</td>
    </tr>
</table>
@if (trim((string) $ficha->notas_finais) !== '')
    <table class="ficha-tab">
        <tr><td><span class="ficha-rot">Notas</span><br>{{ $ficha->notas_finais }}</td></tr>
    </table>
@endif
{{-- Sem coluna de prioridade nesta ficha (o campo saiu do formulário a pedido da equipa). --}}
@if (trim((string) $ficha->recomendacao) !== '')
    <div class="ficha-seccao">Recomendações e próximos passos</div>
    <table class="ficha-tab">
        <tr><td>{{ $ficha->recomendacao }}</td></tr>
    </table>
@endif
