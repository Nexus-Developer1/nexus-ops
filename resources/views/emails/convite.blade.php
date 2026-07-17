<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Convite Nexus Infra</title>
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
                            <p style="margin:0 0 12px; font-size:15px; line-height:1.6; color:#374151;">Foi convidado(a) para aceder ao <strong style="color:#111827;">Nexus Infra</strong>.</p>
                            <p style="margin:0 0 26px; font-size:15px; line-height:1.6; color:#374151;">Para ativar a sua conta, defina a sua palavra-passe no botão abaixo.</p>

                            {{-- Botão (verde) --}}
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" bgcolor="#16a34a" style="border-radius:10px;">
                                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" style="display:inline-block; padding:14px 30px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:10px;">Definir palavra-passe</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:26px 0 6px; font-size:13px; line-height:1.6; color:#6b7280;">Este convite expira em {{ $validade }} dias.</p>
                            <p style="margin:0; font-size:13px; line-height:1.6; color:#6b7280;">Se não estava à espera deste convite, ignore este email.</p>
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
