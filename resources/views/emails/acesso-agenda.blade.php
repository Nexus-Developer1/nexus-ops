<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso à agenda no Outlook</title>
</head>
{{-- Email com o acesso à agenda no Outlook, pedido pelo próprio na página da Agenda.
     Vai SEMPRE só para o email da conta que carregou no botão. --}}
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                    <tr><td style="height:6px; line-height:6px; font-size:0; background-color:#16a34a;">&nbsp;</td></tr>

                    <tr>
                        <td style="padding:28px 36px 6px;">
                            <div style="font-size:22px; font-weight:800; color:#16a34a; line-height:1;">Nexus Infra</div>
                            <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#9ca3af; margin-top:3px;">Agenda</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:14px 36px 4px;">
                            <h1 style="margin:0 0 14px; font-size:20px; font-weight:600; color:#111827;">Olá {{ $nome }},</h1>
                            <p style="margin:0 0 18px; font-size:15px; line-height:1.6; color:#374151;">
                                Aqui está o acesso à <strong style="color:#111827;">agenda da Nexus Infra no Outlook</strong>.
                                Basta subscrever uma vez: a partir daí os eventos aparecem sozinhos e vão-se atualizando.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin:0 0 18px;">
                                <tr><td style="padding:14px 16px 8px; font-size:16px; font-weight:700; color:#111827;">Como subscrever</td></tr>
                                <tr><td style="padding:0 16px 12px; font-size:14px; line-height:1.7; color:#374151;">
                                    <strong>No Outlook do computador:</strong> Ficheiro → Definições da Conta → Calendários da Internet → Novo, e cola o endereço abaixo.<br>
                                    <strong>No Outlook web:</strong> Calendário → Adicionar calendário → Subscrever a partir da Web, e cola o mesmo endereço.
                                </td></tr>
                                <tr><td style="padding:0 16px 14px;">
                                    <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">O seu endereço pessoal (não o partilhe):</div>
                                    <div style="word-break:break-all; font-family:Consolas,monospace; font-size:13px; color:#111827; background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px;">{{ $url }}</div>
                                </td></tr>
                            </table>

                            @include('emails._botao', ['url' => $url, 'texto' => 'Abrir no calendário'])

                            <p style="margin:0 0 18px; font-size:13px; line-height:1.6; color:#6b7280;">
                                Este endereço é pessoal e serve de senha: quem o tiver vê a sua agenda. Se achar que ficou exposto,
                                peça a um administrador para o regenerar — o antigo deixa de funcionar.
                            </p>
                        </td>
                    </tr>

                    <tr><td style="padding:0 36px 8px;"><div style="height:1px; background-color:#e5e7eb;"></div></td></tr>
                    <tr>
                        <td style="padding:12px 36px 26px; font-size:12px; line-height:1.6; color:#9ca3af;">
                            Recebe este email porque o pediu na página da Agenda do Nexus Infra.<br>
                            Nexus Infra · Technical Suite
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
