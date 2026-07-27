<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronização com o PHC</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                    {{-- Faixa no topo: verde = sucesso, vermelha = falha --}}
                    <tr><td style="height:6px; line-height:6px; font-size:0; background-color:{{ $falhou ? '#dc2626' : '#16a34a' }};">&nbsp;</td></tr>

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
                            @if ($falhou)
                                <h1 style="margin:0 0 14px; font-size:20px; font-weight:600; color:#111827;">A sincronização com o PHC falhou</h1>
                                <p style="margin:0 0 18px; font-size:15px; line-height:1.6; color:#374151;">A sincronização agendada <strong style="color:#111827;">não terminou com sucesso</strong>. Resultado por etapa:</p>
                            @else
                                <h1 style="margin:0 0 14px; font-size:20px; font-weight:600; color:#111827;">Sincronização feita com sucesso</h1>
                                <p style="margin:0 0 18px; font-size:15px; line-height:1.6; color:#374151;">A sincronização agendada com o PHC <strong style="color:#111827;">terminou sem erros</strong>. Resultado por etapa:</p>
                            @endif

                            {{-- Caixa com o resultado por etapa --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{{ $falhou ? '#fef2f2' : '#f0fdf4' }}; border:1px solid {{ $falhou ? '#fecaca' : '#bbf7d0' }}; border-radius:10px;">
                                @foreach ($resultados as $etapa => $r)
                                    <tr>
                                        <td style="padding:12px 16px; {{ ! $loop->last ? 'border-bottom:1px solid ' . ($falhou ? '#fecaca' : '#bbf7d0') . ';' : '' }}">
                                            <div style="font-size:13px; font-weight:700; color:{{ $r['ok'] ? '#166534' : '#991b1b' }};">{{ $r['ok'] ? '✓' : '✗' }} {{ $etapa }}</div>
                                            <div style="font-size:13px; line-height:1.5; color:{{ $r['ok'] ? '#15803d' : '#7f1d1d' }}; margin-top:2px;">{{ $r['detalhe'] }}</div>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            @if ($falhou)
                                <p style="margin:20px 0 6px; font-size:13px; line-height:1.6; color:#6b7280;">A próxima corrida agendada (08h, 13h e 19h) volta a tentar automaticamente; o detalhe técnico fica no log da aplicação.</p>
                            @else
                                <p style="margin:20px 0 6px; font-size:13px; line-height:1.6; color:#6b7280;">Sincronizações agendadas: 08h, 13h e 19h, todos os dias.</p>
                            @endif
                        </td>
                    </tr>

                    <tr><td style="padding:10px 36px 30px;"></td></tr>
                </table>

                <p style="margin:18px 0 0; font-size:11px; letter-spacing:1px; color:#9ca3af;">NEXUS SOLUTIONS · OPERATIONS</p>
            </td>
        </tr>
    </table>
</body>
</html>
