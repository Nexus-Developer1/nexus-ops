@props(['ativo' => ''])

@php
    $u = auth()->user();

    // Ícones por chave.
    $icones = [
        'dashboard'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM13 5a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1h-5a1 1 0 01-1-1V5zM4 14a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1v-5zM13 14a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1h-5a1 1 0 01-1-1v-5z"/>',
        'ativos'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7l2 2 4-4"/>',
        'clientes'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
        'relatorios' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
        'contratos'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h4"/>',
        'agenda'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>',
        'alertas'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
        'despesas'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>',
        'utilizadores' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
    ];

    // Navegação: o técnico é um ESPELHO do admin (mesma lista), a ÚNICA exceção é "Utilizadores".
    $itens = [
        ['id' => 'dashboard',  'label' => 'Dashboard',  'url' => route('dashboard')],
        ['id' => 'ativos',     'label' => 'Ativos',     'url' => route('ativos')],
        ['id' => 'clientes',   'label' => 'Clientes',   'url' => route('clientes')],
        ['id' => 'relatorios', 'label' => 'Relatórios', 'url' => route('relatorios')],
        ['id' => 'contratos',  'label' => 'Contratos',  'url' => route('contratos')],
        ['id' => 'despesas',   'label' => 'Despesas',   'url' => route('despesas')],
        ['id' => 'agenda',     'label' => 'Agenda',     'url' => route('agenda')],
        ['id' => 'alertas',    'label' => 'Alertas',    'url' => route('alertas')],
    ];

    // Gerir utilizadores: exclusivo do admin (Gate 'gerir-utilizadores'). Escondido dos técnicos.
    if ($u && $u->ehAdmin()) {
        $itens[] = ['id' => 'utilizadores', 'label' => 'Utilizadores', 'url' => route('utilizadores.adicionar')];
    }

    $itens = array_map(fn ($i) => $i + ['icon' => $icones[$i['id']]], $itens);

    $iniciais = $u
        ? \Illuminate\Support\Str::of($u->nome)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('')
        : '–';
@endphp

<aside class="bg-sidebar-grad fixed inset-y-0 left-0 z-40 flex h-screen w-sidebar shrink-0 flex-col px-5 py-7 transition-transform duration-200 ease-out -translate-x-full lg:sticky lg:top-0 lg:z-auto lg:translate-x-0"
       :class="sidebarAberta && '!translate-x-0'">
    <div class="flex items-start justify-between px-2">
        <div>
            <div class="text-2xl font-bold leading-none text-verde-400">Nexus Ops</div>
            <div class="mt-1 text-[11px] font-medium uppercase tracking-[0.22em] text-white/40">Technical Suite</div>
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

    <a href="{{ route('relatorios.novo') }}" class="botao-primario mt-6 w-full py-3">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
        Novo Relatório
    </a>

    <div class="mt-6 flex items-center gap-3 border-t border-white/10 pt-5">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-verde-600 text-sm font-semibold text-white">{{ $iniciais }}</div>
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-semibold text-white">{{ $u?->nome }}</div>
            <div class="truncate text-xs text-white/45">{{ $u?->email }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" title="Sair" class="flex h-8 w-8 items-center justify-center rounded-lg text-white/45 transition hover:bg-white/5 hover:text-white">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </button>
        </form>
    </div>
</aside>
