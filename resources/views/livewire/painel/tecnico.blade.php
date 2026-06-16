<div>
    <x-topbar :breadcrumb="['Painel']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Bom dia, {{ auth()->user()->nome }}</h1>
            <p class="mt-2 text-sm text-texto-medio">A sua operação · {{ \Illuminate\Support\Carbon::now()->locale('pt')->translatedFormat('l, d \d\e F \d\e Y') }}</p>

            {{-- Intervenções em curso --}}
            @if ($emCurso->isNotEmpty())
                <div class="mt-8 rounded-xl border border-aviso-200 bg-aviso-100/60 px-5 py-4">
                    <div class="text-sm font-semibold text-texto-forte">Tem {{ $emCurso->count() }} {{ $emCurso->count() === 1 ? 'intervenção' : 'intervenções' }} em curso</div>
                    <ul class="mt-2 space-y-1">
                        @foreach ($emCurso as $i)
                            <li class="text-sm text-texto-medio">
                                <a href="{{ route('intervencoes.formulario', $i) }}" wire:navigate class="font-medium text-verde-700 hover:underline">
                                    Intervenção #{{ $i->id }}
                                </a>
                                · {{ $i->equipamento->local->cliente->nome ?? '—' }} · {{ trim($i->equipamento->fabricante . ' ' . $i->equipamento->modelo) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-2">
                {{-- Próximas visitas --}}
                <section class="cartao">
                    <div class="flex items-center justify-between px-6 py-5">
                        <h2 class="text-lg font-semibold text-texto-forte">As minhas próximas visitas</h2>
                        <a href="{{ route('agenda') }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Agenda</a>
                    </div>
                    <ul class="border-t border-borda">
                        @forelse ($proximasVisitas as $v)
                            <li class="border-b border-borda px-6 py-3.5 last:border-0">
                                <div class="text-sm font-medium text-texto-forte">{{ $v->titulo }}</div>
                                <div class="text-xs text-texto-fraco">{{ $v->inicio->translatedFormat('d M Y · H:i') }}</div>
                            </li>
                        @empty
                            <li class="px-6 py-8 text-center text-sm text-texto-medio">Sem visitas agendadas.</li>
                        @endforelse
                    </ul>
                </section>

                {{-- Relatórios recentes --}}
                <section class="cartao">
                    <div class="flex items-center justify-between px-6 py-5">
                        <h2 class="text-lg font-semibold text-texto-forte">Os meus relatórios recentes</h2>
                        <a href="{{ route('relatorios') }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Ver todos</a>
                    </div>
                    <ul class="border-t border-borda">
                        @forelse ($relatoriosRecentes as $r)
                            <li class="flex items-center justify-between border-b border-borda px-6 py-3.5 last:border-0">
                                <div>
                                    <div class="text-sm font-medium text-texto-forte">{{ $r->numero }}</div>
                                    <div class="text-xs text-texto-fraco">{{ $r->data->translatedFormat('d M Y') }}</div>
                                </div>
                                <a href="{{ route('relatorios.pdf', $r) }}" target="_blank" class="text-sm font-medium text-verde-600 hover:underline">PDF</a>
                            </li>
                        @empty
                            <li class="px-6 py-8 text-center text-sm text-texto-medio">Sem relatórios.</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>
    </main>
</div>
