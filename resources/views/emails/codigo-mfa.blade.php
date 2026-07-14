<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Código de acesso Nexus Infra</title>
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
                            <p style="margin:0 0 22px; font-size:15px; line-height:1.6; color:#374151;">Use o código abaixo para concluir o início de sessão no <strong style="color:#111827;">Nexus Infra</strong>.</p>

                            {{-- Código --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
                                <tr>
                                    <td align="center" style="background-color:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:18px 30px;">
                                        <span style="font-size:34px; font-weight:700; letter-spacing:10px; color:#166534; font-family:'Courier New',Courier,monospace;">{{ $codigo }}</span>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 6px; font-size:13px; line-height:1.6; color:#6b7280;">O código expira em {{ $validade }} minutos e só pode ser usado uma vez.</p>
                            <p style="margin:0 0 26px; font-size:13px; line-height:1.6; color:#6b7280;">Se não foi você a tentar entrar, ignore este email e considere alterar a sua palavra-passe.</p>
                        </td>
                    </tr>

                    {{-- Rodapé --}}
                    <tr>
                        <td style="padding:18px 36px 30px; border-top:1px solid #f3f4f6;">
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#9ca3af;">Este é um email automático do Nexus Infra. Por favor não responda.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
