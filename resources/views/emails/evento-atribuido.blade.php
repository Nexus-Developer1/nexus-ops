<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo agendamento · Nexus Infra</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                    {{-- Faixa verde no topo --}}
                    <tr><td style="height:6px; line-height:6px; font-size:0; background-color:#16a34a;">&nbsp;</td></tr>

                    {{-- Cabeçalho / marca --}}
                    <tr>
                        <td style="padding:28px 36px 6px;">
                            <div style="font-size:22px; font-weight:800; color:#16a34a; line-height:1;">Nexus Infra</div>
                            <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#9ca3af; margin-top:3px;">Technical Suite</div>
                        </td>
                    </tr>

                    {{-- Corpo --}}
                    <tr>
                        <td style="padding:14px 36px 4px;">
                            <h1 style="margin:0 0 14px; font-size:20px; font-weight:600; color:#111827;">Olá {{ $nome }},</h1>
                            <p style="margin:0 0 18px; font-size:15px; line-height:1.6; color:#374151;">Foi-lhe atribuído um novo evento na agenda da <strong style="color:#111827;">Nexus Infra</strong>.</p>

                            {{-- Detalhes do evento (caixa cinza clara) --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:10px;">
                                <tr>
                                    <td style="padding:18px 22px;">
                                        <div style="font-size:16px; font-weight:600; color:#111827; margin-bottom:10px;">{{ $evento->titulo }}</div>
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px; line-height:1.7; color:#374151;">
                                            <tr>
                                                <td style="padding-right:14px; color:#9ca3af; font-size:12px; text-transform:uppercase; letter-spacing:1px; vertical-align:top;">Quando</td>
                                                <td style="color:#111827; font-weight:500;">{{ $evento->inicio->translatedFormat('l, d \d\e F \d\e Y') }} · {{ $evento->inicio->format('H:i') }}–{{ $evento->fim->format('H:i') }}</td>
                                            </tr>
                                            @if ($evento->cliente)
                                                <tr>
                                                    <td style="padding-right:14px; color:#9ca3af; font-size:12px; text-transform:uppercase; letter-spacing:1px; vertical-align:top;">Cliente</td>
                                                    <td>{{ $evento->cliente->nome }}</td>
                                                </tr>
                                            @endif
                                            @if ($evento->local)
                                                <tr>
                                                    <td style="padding-right:14px; color:#9ca3af; font-size:12px; text-transform:uppercase; letter-spacing:1px; vertical-align:top;">Local</td>
                                                    <td>{{ $evento->local->designacao }}@if ($evento->local->morada) · {{ $evento->local->morada }}@endif</td>
                                                </tr>
                                            @endif
                                            @if ($evento->equipamento)
                                                <tr>
                                                    <td style="padding-right:14px; color:#9ca3af; font-size:12px; text-transform:uppercase; letter-spacing:1px; vertical-align:top;">Equipamento</td>
                                                    <td>{{ trim(($evento->equipamento->fabricante ?? '') . ' ' . ($evento->equipamento->modelo ?? '')) ?: '—' }}@if ($evento->equipamento->numero_serie) ({{ $evento->equipamento->numero_serie }})@endif</td>
                                                </tr>
                                            @endif
                                            @if ($evento->tecnicoLabel)
                                                <tr>
                                                    <td style="padding-right:14px; color:#9ca3af; font-size:12px; text-transform:uppercase; letter-spacing:1px; vertical-align:top;">Técnicos</td>
                                                    <td>{{ $evento->tecnicoLabel }}</td>
                                                </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- Botão (verde) --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;">
                                <tr>
                                    <td align="center" bgcolor="#16a34a" style="border-radius:10px;">
                                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" style="display:inline-block; padding:14px 30px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:10px;">Ver agenda</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:24px 0 6px; font-size:13px; line-height:1.6; color:#6b7280;">Se este agendamento não lhe diz respeito, contacte a equipa de operações.</p>
                        </td>
                    </tr>

                    {{-- Separador + link de recurso --}}
                    <tr>
                        <td style="padding:22px 36px 30px;">
                            <div style="border-top:1px solid #e5e7eb; padding-top:16px;">
                                <p style="margin:0 0 6px; font-size:12px; line-height:1.5; color:#9ca3af;">Se o botão não funcionar, copie e cole este endereço no seu navegador:</p>
                                <a href="{{ $url }}" style="font-size:12px; color:#16a34a; word-break:break-all;">{{ $url }}</a>
                            </div>
                        </td>
                    </tr>
                </table>

                <p style="margin:18px 0 0; font-size:11px; letter-spacing:1px; color:#9ca3af;">NEXUS SOLUTIONS · OPERATIONS</p>
            </td>
        </tr>
    </table>
</body>
</html>
