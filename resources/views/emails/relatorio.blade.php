<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório {{ $relatorio->numero }}</title>
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

                    {{-- Corpo: a mensagem escrita à mão --}}
                    <tr>
                        <td style="padding:16px 36px 4px; font-size:15px; line-height:1.6; color:#374151;">
                            {!! nl2br(e($mensagem)) !!}
                        </td>
                    </tr>

                    {{-- Anexo (PDF) --}}
                    <tr>
                        <td style="padding:22px 36px 4px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;">
                                <tr>
                                    <td style="padding:12px 16px; font-size:14px; color:#166534;">
                                        📎 Em anexo: <strong>Relatório {{ $relatorio->numero }}</strong> (PDF) — com as medições e o registo fotográfico.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Rodapé --}}
                    <tr>
                        <td style="padding:22px 36px 30px;">
                            <div style="border-top:1px solid #e5e7eb; padding-top:16px;">
                                <p style="margin:0; font-size:12px; line-height:1.5; color:#9ca3af;">Relatório de intervenção técnica emitido pelo Nexus Infra.</p>
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
