@props(['ativo' => '', 'titulo' => null])

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $titulo ? $titulo . ' — Nexus Infra' : 'Nexus Infra' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">
    <div class="flex min-h-screen" x-data="{ sidebarAberta: false }" @keydown.escape.window="sidebarAberta = false">
        <x-sidebar :ativo="$ativo" />

        {{-- Fundo escuro (só mobile, quando o menu está aberto) --}}
        <div x-show="sidebarAberta" x-cloak x-transition.opacity @click="sidebarAberta = false"
             class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- Barra superior mobile com botão de menu --}}
            <header class="flex items-center gap-3 border-b border-borda bg-white px-4 py-3 lg:hidden">
                <button @click="sidebarAberta = true" aria-label="Abrir menu"
                        class="flex h-10 w-10 items-center justify-center rounded-lg text-texto-medio transition hover:bg-fundo">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <span class="text-lg font-bold text-verde-600">Nexus Infra</span>
            </header>

            {{ $slot }}
        </div>
    </div>

    @livewireScripts
</body>
</html>
