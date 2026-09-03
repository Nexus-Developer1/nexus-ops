<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda Nexus Infra</title>
</head>
@php
    $cor = match ($tipo) { 'criado' => '#16a34a', 'alterado' => '#a16207', default => '#dc2626' };
    $titulo = match ($tipo) { 'criado' => 'Foi associado(a) a um novo evento', 'alterado' => 'Um evento seu foi alterado', default => 'Um evento seu foi removido' };
    $quando = \App\Notifications\EventoAgendaNotificacao::quando($evento);
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
                            <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#9ca3af; margin-top:3px;">Agenda</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:14px 36px 4px;">
                            <h1 style="margin:0 0 14px; font-size:20px; font-weight:600; color:#111827;">Olá {{ $nome }},</h1>
                            <p style="margin:0 0 18px; font-size:15px; line-height:1.6; color:#374151;">{{ $titulo }} por <strong style="color:#111827;">{{ $autor }}</strong>.</p>

                            {{-- Ficha do evento (o estado ATUAL; no removido, o que existia) --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin:0 0 18px;">
                                <tr><td style="padding:14px 16px 6px; font-size:17px; font-weight:700; color:#111827; {{ $tipo === 'removido' ? 'text-decoration:line-through; color:#6b7280;' : '' }}">{{ $evento['titulo'] }}</td></tr>
                                <tr><td style="padding:0 16px 4px; font-size:14px; color:#374151;"><span style="color:#6b7280;">Quando:</span> <strong>{{ $quando }}</strong></td></tr>
                                @if (count($evento['segmentos'] ?? []) > 1)
                                    <tr><td style="padding:0 16px 4px; font-size:13px; color:#374151;">
                                        <span style="color:#6b7280;">Horas por dia:</span>
                                        @foreach ($evento['segmentos'] as $s)
                                            <div style="padding-left:12px;">{{ \Illuminate\Support\Carbon::parse($s[0])->format('d/m') }} · {{ \Illuminate\Support\Carbon::parse($s[0])->format('H:i') }}–{{ \Illuminate\Support\Carbon::parse($s[1])->format('H:i') }}</div>
                                        @endforeach
                                    </td></tr>
                                @endif
                                <tr><td style="padding:0 16px 4px; font-size:14px; color:#374151;"><span style="color:#6b7280;">Técnicos:</span> {{ $evento['tecnicos_nomes'] ?: '—' }}</td></tr>
                                @if ($evento['cliente'])<tr><td style="padding:0 16px 4px; font-size:14px; color:#374151;"><span style="color:#6b7280;">Cliente:</span> {{ $evento['cliente'] }}</td></tr>@endif
                                @if ($evento['equipamento'])<tr><td style="padding:0 16px 4px; font-size:14px; color:#374151;"><span style="color:#6b7280;">Equipamento:</span> {{ $evento['equipamento'] }}</td></tr>@endif
                                @if ($evento['contrato'])<tr><td style="padding:0 16px 4px; font-size:14px; color:#374151;"><span style="color:#6b7280;">Contrato:</span> {{ $evento['contrato'] }}</td></tr>@endif
                                @if ($evento['notas'] ?? null)<tr><td style="padding:0 16px 4px; font-size:14px; color:#374151; white-space:pre-line;"><span style="color:#6b7280;">Notas:</span> {{ $evento['notas'] }}</td></tr>@endif
                                <tr><td style="padding:6px 16px 14px;"></td></tr>
                            </table>

                            @if ($tipo === 'alterado' && $alteracoes !== [])
                                <p style="margin:0 0 8px; font-size:14px; font-weight:600; color:#111827;">O que mudou</p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px; font-size:14px; color:#374151;">
                                    @foreach ($alteracoes as $a)
                                        <tr>
                                            <td style="padding:4px 0; width:120px; color:#6b7280; vertical-align:top;">{{ $a['campo'] }}</td>
                                            <td style="padding:4px 0;"><span style="text-decoration:line-through; color:#9ca3af;">{{ $a['antes'] }}</span> &rarr; <strong style="color:#111827;">{{ $a['depois'] }}</strong></td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if ($tipo !== 'removido')
                                @include('emails._botao', ['url' => $url, 'texto' => 'Abrir a agenda'])
                            @endif

                            <p style="margin:0 0 6px; font-size:12px; line-height:1.6; color:#9ca3af;">Recebe este email porque está associado(a) ao evento na agenda do Nexus Infra. O evento também está no seu calendário (iCal).</p>
                        </td>
                    </tr>

                    <tr><td style="padding:12px 36px 22px; font-size:11px; color:#9ca3af;">Nexus Infra · Technical Suite</td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
