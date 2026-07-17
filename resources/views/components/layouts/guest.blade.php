@props(['titulo' => null])

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- PWA: instalável no telemóvel ("Adicionar ao ecrã principal"). --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0A2A18">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Nexus Infra">
    <link rel="apple-touch-icon" href="/img/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/img/icon-192.png">
    <title>{{ $titulo ? $titulo . ' — Nexus Infra' : 'Nexus Infra' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">
    {{ $slot }}
    @livewireScripts
</body>
</html>
