@props(['ativo' => ''])

@php
    // Navegação do portal de cliente (só leitura).
    $itens = [
        ['id' => 'inicio',      'label' => 'Início',      'url' => route('portal.dashboard'),    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>'],
        ['id' => 'equipamentos','label' => 'Equipamentos','url' => route('portal.equipamentos'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7l2 2 4-4"/>'],
        ['id' => 'relatorios',  'label' => 'Relatórios',  'url' => route('portal.relatorios'),   'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'],
    ];

    $u = auth()->user();
    $iniciais = $u
        ? \Illuminate\Support\Str::of($u->nome)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('')
        : '–';
@endphp

<aside class="bg-sidebar-grad fixed inset-y-0 left-0 z-40 flex h-screen w-sidebar shrink-0 flex-col px-5 py-7 transition-transform duration-200 ease-out -translate-x-full lg:sticky lg:top-0 lg:z-auto lg:translate-x-0"
       :class="sidebarAberta && '!translate-x-0'">
    <div class="flex items-start justify-between px-2">
        <div>
            <div class="flex items-center gap-1.5">
                <img src="{{ asset('img/nexus-1.png') }}" alt="Nexus" class="h-7 w-auto">
                <span class="text-2xl font-bold leading-none text-verde-400">Infra</span>
            </div>
            <div class="mt-2 text-[11px] font-medium uppercase tracking-[0.22em] text-white/40">Portal do Cliente</div>
        </div>
        <button @click="sidebarAberta = false" aria-label="Fechar menu" class="flex h-9 w-9 items-center justify-center rounded-lg text-white/60 transition hover:bg-white/10 hover:text-white lg:hidden">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <nav class="mt-10 flex flex-1 flex-col gap-1.5">
        @foreach ($itens as $item)
            @php($on = $ativo === $item['id'])
            <a href="{{ $item['url'] }}" class="nav-item {{ $on ? 'nav-item-ativo' : '' }}">
                @if ($on)
                    <span class="absolute left-0 top-1/2 h-7 w-1 -translate-x-5 -translate-y-1/2 rounded-r-full bg-sidebar-barra"></span>
                @endif
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">{!! $item['icon'] !!}</svg>
                <span class="{{ $on ? 'font-semibold' : '' }}">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="mt-6 flex items-center gap-3 border-t border-white/10 pt-5">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-verde-600 text-sm font-semibold text-white">{{ $iniciais }}</div>
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-semibold text-white">{{ $u?->nome }}</div>
            <div class="truncate text-xs text-white/45">{{ $u?->cliente?->nome }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" title="Sair" class="flex h-8 w-8 items-center justify-center rounded-lg text-white/45 transition hover:bg-white/5 hover:text-white">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </button>
        </form>
    </div>
</aside>
