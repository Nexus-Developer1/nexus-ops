{{-- Etiqueta QR do equipamento (90 x 50 mm) — para imprimir e colar no equipamento.
     O QR contém o URL da ficha: qualquer câmara de telemóvel abre-a diretamente.
     Sem o cliente de propósito: o equipamento pode mudar de cliente, a etiqueta não. --}}
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111827; }
        .etiqueta { width: 100%; border-collapse: collapse; }
        .etiqueta td { vertical-align: middle; }
        .qr { width: 44mm; padding: 3mm; }
        .qr img { width: 38mm; height: 38mm; }
        .info { padding: 3mm 4mm 3mm 0; }
        .serie { font-size: 13pt; font-weight: bold; word-break: break-all; }
        .modelo { font-size: 9pt; margin-top: 2mm; color: #374151; }
        .rodape { font-size: 6.5pt; margin-top: 4mm; color: #6b7280; }
    </style>
</head>
<body>
    <table class="etiqueta">
        <tr>
            <td class="qr"><img src="{{ $qrPng }}" alt="QR da ficha do equipamento"></td>
            <td class="info">
                <div class="serie">{{ $equipamento->numero_serie ?? 'Equip. #' . $equipamento->id }}</div>
                <div class="modelo">{{ trim(($equipamento->fabricante ?? '') . ' ' . ($equipamento->modelo ?? '')) ?: '—' }}</div>
                <div class="rodape">Nexus Infra — aponte a câmara ao código para abrir a ficha do equipamento</div>
            </td>
        </tr>
    </table>
</body>
</html>
