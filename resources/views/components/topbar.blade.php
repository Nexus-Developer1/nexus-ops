@props(['breadcrumb' => []])

{{-- Barra superior reutilizável: breadcrumb + ações (slot). --}}
<header class="flex items-center justify-between border-b border-borda bg-white px-4 py-4 sm:px-10 sm:py-5 shadow-topbar">
    <nav class="flex items-center gap-2 text-sm">
        @foreach ($breadcrumb as $item)
            @if (! $loop->first)
                <span class="text-texto-fraco">/</span>
            @endif
            <span class="{{ $loop->last ? 'font-medium text-texto-forte' : 'text-texto-medio' }}">{{ $item }}</span>
        @endforeach
    </nav>
    <div class="flex items-center gap-3">
        {{ $slot }}
    </div>
</header>
