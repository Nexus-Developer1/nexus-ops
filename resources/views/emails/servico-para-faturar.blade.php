<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Serviço para faturar — {{ $relatorio->numero }}</title>
</head>
{{-- Aviso ao comercial de que o serviço pode ser faturado (out. 2026) — mesmo layout dos outros
     emails da app (barra de cor, marca, cartão com os dados). O PDF do relatório vai em anexo. --}}
@php
    $i = $relatorio->intervencao;
    $e = $i?->equipamento;
    $cliente = $e?->local?->cliente;
    $linha = fn (string $rotulo, ?string $valor) => $valor === null || $valor === '' ? '' :
        '<tr><td style="padding:6px 16px; font-size:13px; color:#6b7280; width:38%; vertical-align:top;">'.e($rotulo).'</td>'
        .'<td style="padding:6px 16px; font-size:14px; color:#111827; vertical-align:top;">'.e($valor).'</td></tr>';
@endphp
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                    <tr><td style="height:6px; line-height:6px; font-size:0; background-color:#16a34a;">&nbsp;</td></tr>

                    <tr>
                        <td style="padding:28px 36px 6px;">
                            <div style="font-size:22px; font-weight:800; color:#16a34a; line-height:1;">{{ config('app.name') }}</div>
                            <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#9ca3af; margin-top:3px;">Relatórios</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:14px 36px 4px;">
                            <h1 style="margin:0 0 14px; font-size:20px; font-weight:600; color:#111827;">Olá,</h1>
                            <p style="margin:0 0 18px; font-size:15px; line-height:1.6; color:#374151;">
                                O serviço do relatório <strong style="color:#111827;">{{ $relatorio->numero }}</strong>
                                foi enviado ao cliente e <strong style="color:#16a34a;">pode ser faturado</strong>.
                                O relatório segue em anexo.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px; border:1px solid #e5e7eb; border-radius:10px; background-color:#f9fafb;">
                                <tr><td style="padding:6px 0;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                        {!! $linha('Cliente', $cliente ? $cliente->nome.($cliente->id_erp ? ' (nº '.$cliente->id_erp.')' : '') : null) !!}
                                        {!! $linha('Relatório', $relatorio->numero.' · '.$relatorio->data?->format('d/m/Y')) !!}
                                        {!! $linha('Intervenção', $i?->tipo?->rotulo()) !!}
                                        {!! $linha('Equipamento', $e ? trim(trim(($e->fabricante ?? '').' '.($e->modelo ?? '')).($e->numero_serie ? ' · '.$e->numero_serie : '')) : null) !!}
                                        {!! $linha('Técnico(s)', $i?->tecnicosLabel()) !!}
                                        {!! $linha('Contrato', $i?->contrato?->numero) !!}
                                        {!! $linha('Enviado por', $enviadoPor) !!}
                                    </table>
                                </td></tr>
                            </table>

                            {{-- Encomenda(s) de peças: o que o comercial precisa para faturar as peças. --}}
                            <p style="margin:0 0 8px; font-size:14px; font-weight:600; color:#111827;">Encomenda de peças</p>
                            @if ($encomendas === [])
                                <p style="margin:0 0 22px; font-size:14px; color:#6b7280;">Sem encomenda de peças associada a este serviço.</p>
                            @else
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px; border-left:4px solid #16a34a; background-color:#ecfdf5;">
                                    @foreach ($encomendas as $encomenda)
                                        <tr><td style="padding:8px 14px; font-size:15px; font-weight:600; color:#065f46;">{{ $encomenda }}</td></tr>
                                    @endforeach
                                </table>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 36px 26px; border-top:1px solid #f3f4f6; font-size:12px; color:#9ca3af;">
                            Email automático do {{ config('app.name') }} — enviado com o relatório ao cliente.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
