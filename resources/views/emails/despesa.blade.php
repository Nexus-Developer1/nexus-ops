<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Despesas Nexus Infra</title>
</head>
{{-- Email do processo de validação das despesas (submissão e decisão) — mesmo layout dos
     outros emails da app (agenda, MFA): barra de cor, marca, cartão com os dados, botão verde.
     $modo: 'submetida' | 'decidida'; $variante (só na submissão): 'aprovador' (pedido de
     aprovação) | 'criador' (confirmação) | 'informativo' (financeiro, sem a parte de aprovar);
     $r: instantâneo (FluxoAprovacaoDespesas::instantaneo). --}}
@php
    $variante = $variante ?? '';
    $aprovada = ($r['estado'] ?? '') === 'aprovada';
    $rejeitada = ($r['estado'] ?? '') === 'rejeitada';
    $cor = $modo === 'decidida' ? ($aprovada ? '#16a34a' : '#dc2626') : '#a16207';
    $total = number_format($r['total'], 2, ',', ' ').' €';
    $titulo = $modo === 'decidida'
        ? 'Despesa nº '.$r['id'].' '.($aprovada ? 'aprovada' : 'rejeitada')
        : ($reenvio ? 'Despesa nº '.$r['id'].' corrigida — volta a aguardar aprovação' : 'Nova despesa aguarda aprovação');
@endphp
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                    <tr><td style="height:6px; line-height:6px; font-size:0; background-color:{{ $cor }};">&nbsp;</td></tr>

                    <tr>
                        <td style="padding:28px 36px 6px;">
                            <div style="font-size:22px; font-weight:800; color:#16a34a; line-height:1;">Nexus Infra</div>
                            <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#9ca3af; margin-top:3px;">Despesas</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:14px 36px 4px;">
                            <h1 style="margin:0 0 14px; font-size:20px; font-weight:600; color:#111827;">{{ $nome ? 'Olá '.$nome.',' : 'Olá,' }}</h1>
                            <p style="margin:0 0 18px; font-size:15px; line-height:1.6; color:#374151;">
                                @if ($modo === 'decidida')
                                    A despesa nº {{ $r['id'] }} de <strong style="color:#111827;">{{ $r['colaborador'] }}</strong> ({{ $total }}) foi
                                    <strong style="color:{{ $cor }};">{{ $aprovada ? 'APROVADA' : 'REJEITADA' }}</strong>
                                    por <strong style="color:#111827;">{{ $r['decisor'] ?? '—' }}</strong>@if ($r['decidido_em']) em {{ $r['decidido_em'] }}@endif.
                                @elseif ($variante === 'criador')
                                    A sua despesa {{ $reenvio ? 'foi corrigida e reenviada' : 'foi submetida' }} e <strong style="color:#111827;">aguarda aprovação</strong>.
                                    Será avisado(a) por email assim que for aprovada ou rejeitada — não precisa de fazer mais nada.
                                @elseif ($variante === 'informativo')
                                    Foi {{ $reenvio ? 'corrigida e reenviada' : 'registada' }} uma despesa de <strong style="color:#111827;">{{ $r['colaborador'] }}</strong>, que <strong style="color:#111827;">aguarda aprovação</strong>.
                                    Receberá novo email com a decisão do aprovador.
                                @elseif ($reenvio)
                                    A despesa nº {{ $r['id'] }} foi corrigida e volta a aguardar <strong style="color:#111827;">a sua aprovação</strong>.
                                @else
                                    Foi registada uma despesa que aguarda <strong style="color:#111827;">a sua aprovação</strong>.
                                @endif
                            </p>

                            @if ($rejeitada)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px; border-left:4px solid #dc2626; background-color:#fef2f2;">
                                    <tr><td style="padding:10px 14px; font-size:14px; color:#7f1d1d; white-space:pre-line;"><strong>Motivo:</strong> {{ $r['motivo'] ?: '—' }}</td></tr>
                                    <tr><td style="padding:0 14px 10px; font-size:12px; color:#991b1b;">Quem registou a despesa pode corrigi-la e voltar a guardar — fica de novo pendente de aprovação.</td></tr>
                                </table>
                            @endif

                            {{-- Ficha da despesa --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin:0 0 18px;">
                                <tr><td style="padding:14px 16px 6px; font-size:17px; font-weight:700; color:#111827;">Despesa nº {{ $r['id'] }} · {{ $total }}</td></tr>
                                <tr><td style="padding:0 16px 4px; font-size:14px; color:#374151;"><span style="color:#6b7280;">Colaborador:</span> <strong>{{ $r['colaborador'] }}</strong></td></tr>
                                <tr><td style="padding:0 16px 10px; font-size:14px; color:#374151;"><span style="color:#6b7280;">Estado:</span> {{ $modo === 'decidida' ? ($aprovada ? 'Aprovada' : 'Rejeitada') : 'Pendente de aprovação' }}</td></tr>
                                <tr><td style="padding:0 16px 12px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px; color:#374151; border-top:1px solid #e5e7eb;">
                                        @foreach (array_slice($r['linhas'], 0, 15) as $l)
                                            <tr>
                                                <td style="padding:6px 0; width:78px; color:#6b7280; vertical-align:top; border-bottom:1px solid #f3f4f6;">{{ $l['data'] }}</td>
                                                <td style="padding:6px 8px; vertical-align:top; border-bottom:1px solid #f3f4f6;"><span style="color:#6b7280;">{{ $l['categoria'] }}</span> · {{ $l['descricao'] }}</td>
                                                <td style="padding:6px 0; text-align:right; white-space:nowrap; font-weight:600; color:#111827; vertical-align:top; border-bottom:1px solid #f3f4f6;">{{ number_format($l['valor'], 2, ',', ' ') }} €</td>
                                            </tr>
                                        @endforeach
                                        @if (count($r['linhas']) > 15)
                                            <tr><td colspan="3" style="padding:6px 0; color:#6b7280;">… e mais {{ count($r['linhas']) - 15 }} linhas.</td></tr>
                                        @endif
                                    </table>
                                </td></tr>
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
                                <tr><td style="border-radius:10px; background-color:#16a34a;">
                                    <a href="{{ $r['url'] }}" style="display:inline-block; padding:12px 22px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">{{ $modo === 'submetida' && $variante === 'aprovador' ? 'Ver e aprovar despesa' : 'Ver despesa' }}</a>
                                </td></tr>
                            </table>

                            <p style="margin:0 0 6px; font-size:12px; line-height:1.6; color:#9ca3af;">
                                @if ($modo === 'decidida')
                                    Recebe este email porque registou a despesa ou faz parte do circuito de aprovação (aprovador / financeiro).
                                @elseif ($variante === 'aprovador')
                                    A aprovação ou rejeição é feita na ficha da despesa. Recebe este email porque é o aprovador das despesas.
                                @elseif ($variante === 'criador')
                                    Recebe este email como confirmação da submissão da sua despesa.
                                @else
                                    Recebe este email a título informativo, por fazer parte do circuito das despesas.
                                @endif
                            </p>
                        </td>
                    </tr>

                    <tr><td style="padding:12px 36px 22px; font-size:11px; color:#9ca3af;">Nexus Infra · Technical Suite</td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
