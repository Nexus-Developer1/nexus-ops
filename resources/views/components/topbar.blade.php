@props(['breadcrumb' => []])

{{-- Barra superior reutilizável: breadcrumb + ações (slot). No telemóvel a barra faz wrap
     (o breadcrumb em cima, as ações por baixo) em vez de os botões transbordarem. --}}
<header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-b border-borda bg-white px-4 py-4 sm:px-10 sm:py-5 shadow-topbar">
    <nav class="flex min-w-0 items-center gap-2 text-sm">
        @foreach ($breadcrumb as $item)
            @if (! $loop->first)
                <span class="text-texto-fraco">/</span>
            @endif
            <span class="{{ $loop->last ? 'font-medium text-texto-forte' : 'text-texto-medio' }}">{{ $item }}</span>
        @endforeach
    </nav>
    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
        {{ $slot }}
    </div>
</header>
