<div>
    <x-topbar :breadcrumb="['Alertas']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Alertas</h1>

            <x-toast-sucesso />

            {{-- Atribuição: quem deve tratar do alerta (equipa completa ou uma pessoa). --}}
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <label class="text-sm text-texto-medio">Atribuído a</label>
                <select wire:model.live="atribuido" class="campo-select w-56">
                    <option value="">Todos</option>
                    <option value="meus">Os meus (equipa + atribuídos a mim)</option>
                    <option value="equipa">Equipa completa (sem atribuição)</option>
                    @foreach ($equipa as $u)
                        <option value="{{ $u->id }}">{{ $u->nome }}</option>
                    @endforeach
                </select>
                <label class="ml-auto inline-flex items-center gap-2 text-sm text-texto-medio">
                    <input type="checkbox" wire:model.live="concluidos" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                    Mostrar concluídos
                </label>
            </div>


            {{-- Lista de alertas --}}
            <div class="cartao mt-6 overflow-hidden">
                <ul>
                    @forelse ($alertas as $a)
                        <li class="flex items-center gap-4 border-b border-borda px-6 py-4 last:border-0" wire:key="alerta-{{ $loop->index }}">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $a['severidade'] === 'alta' ? 'bg-perigo-100 text-perigo-600' : 'bg-aviso-100 text-aviso-500' }}">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-texto-forte">{{ $a['titulo'] }}</div>
                                <div class="text-xs text-texto-medio">{{ $a['descricao'] }}</div>
                                <div class="mt-0.5 text-xs text-texto-fraco">Atribuído a: {{ $a['atribuido_nome'] ?? 'equipa completa' }}</div>
                            </div>
                            <span class="etiqueta {{ $a['severidade'] === 'alta' ? 'bg-perigo-100 text-perigo-600' : 'bg-aviso-100 text-aviso-500' }}">
                                {{ $a['severidade'] === 'alta' ? 'Alta' : 'Média' }}
                            </span>
                            <a href="{{ $a['url'] }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Ver</a>
                            <button type="button" wire:click="concluir('{{ $a['chave'] }}')" wire:confirm="Dar este alerta como concluído? Sai do dashboard, do painel e do email diário." class="inline-flex items-center gap-1 text-sm font-medium text-texto-medio hover:text-verde-700" title="Concluir">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                Concluir
                            </button>
                        </li>
                    @empty
                        <li class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <svg class="h-10 w-10 text-verde-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <p class="text-sm font-medium text-texto-forte">Tudo em ordem</p>
                            <p class="text-sm text-texto-medio">Não há alertas neste momento.</p>
                        </li>
                    @endforelse
                </ul>
            </div>

            {{-- Histórico dos concluídos (só quando pedido): quem, quando e "Reabrir". --}}
            @if ($concluidos)
                <h2 class="mt-8 text-lg font-semibold text-texto-forte">Concluídos</h2>
                <div class="cartao mt-3 overflow-hidden">
                    <ul>
                        @forelse ($listaConcluidos as $c)
                            <li class="flex items-center gap-4 border-b border-borda px-6 py-3.5 last:border-0" wire:key="concluido-{{ $c->id }}">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-verde-50 text-verde-700">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium text-texto-forte">{{ $c->titulo }}</div>
                                    <div class="text-xs text-texto-medio">Concluído por {{ $c->utilizador?->nome ?? '—' }} a {{ $c->concluido_em->translatedFormat('d M Y, H:i') }}</div>
                                </div>
                                @if ($c->url)<a href="{{ $c->url }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Ver</a>@endif
                                <button type="button" wire:click="reabrir('{{ $c->chave }}')" class="text-sm font-medium text-texto-medio hover:text-texto-forte">Reabrir</button>
                            </li>
                        @empty
                            <li class="px-6 py-8 text-center text-sm text-texto-medio">Ainda não há alertas concluídos.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>
    </main>
</div>
