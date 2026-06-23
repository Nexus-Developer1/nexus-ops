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
        .foto { width: 150px; height: 110px; object-fit: cover; border: 1px solid #e5e7eb; margin: 0 6px 6px 0; }
        .rodape { margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 8px; text-align: center; color: #9ca3af; font-size: 8px; letter-spacing: 1px; }
        .etiqueta { background: #ECFDF3; color: #166534; font-size: 9px; padding: 2px 6px; border-radius: 3px; }
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
                    <div class="marca">Nexus Ops</div>
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
    </table>

    <h2>Intervenção</h2>
    <table class="grelha">
        <tr>
            <td><div class="campo-rotulo">Tipo</div><div class="campo-valor">{{ $i->tipo->rotulo() }}</div></td>
            <td><div class="campo-rotulo">Técnico</div><div class="campo-valor">{{ $i->tecnico?->nome ?? '—' }}</div></td>
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

    @php($d = $i->diagnostico ?? [])
    @if (count($d))
        <h2>Diagnóstico</h2>
        <table class="grelha">
            @foreach (array_chunk($d, 2, true) as $par)
                <tr>
                    @foreach ($par as $chave => $valor)
                        <td><div class="campo-rotulo">{{ ucfirst(str_replace('_', ' ', $chave)) }}</div><div class="campo-valor">{{ $valor }}</div></td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif

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

    @if (count($fotos ?? []))
        <h2>Registo Fotográfico</h2>
        @foreach ($fotos as $foto)
            <img src="{{ $foto }}" class="foto">
        @endforeach
    @endif

    <div class="rodape">
        NEXUS SOLUTIONS OPERATIONS · Documento gerado em {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
