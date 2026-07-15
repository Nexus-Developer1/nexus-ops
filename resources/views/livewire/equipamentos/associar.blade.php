<div>
    <x-topbar :breadcrumb="['Ativos', 'Associar a local']">
        <a href="{{ route('ativos') }}" class="botao-secundario">Cancelar</a>
        <button wire:click="guardar" class="botao-primario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            Guardar
        </button>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-2xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Associar equipamento a local</h1>
            <p class="mt-2 text-sm text-texto-medio">Escolha um equipamento existente e o local onde está instalado.</p>

            <section class="cartao mt-8">
                <div class="flex items-center gap-3 px-6 py-5">
                    <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3"/></svg></span>
                    <h2 class="text-lg font-semibold text-texto-forte">Equipamento e Local</h2>
                </div>
                <div class="space-y-6 border-t border-borda px-6 py-6">

                    {{-- Equipamento --}}
                    <div>
                        <label class="campo-label" for="equip-combo">Equipamento <span class="text-perigo-500">*</span></label>

                        @if ($equipamentoFixo)
                            {{-- Veio da ficha: equipamento fixo (carregado individualmente, não os 17k). --}}
                            <input type="text" class="campo-input bg-fundo" value="{{ $equipamentoAtual ? ($equipamentoAtual->local?->cliente?->nome ?? '—') . ' · ' . trim($equipamentoAtual->tipo->rotulo() . ' ' . $equipamentoAtual->modelo) . ' (' . ($equipamentoAtual->numero_serie ?? '—') . ')' : '—' }}" disabled>
                        @else
                            {{-- Associação livre: pesquisa server-side (~30 resultados) — nunca carrega os 17k. --}}
                            <div wire:key="combo-equip" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                <input id="equip-combo" type="text"
                                    wire:model.live.debounce.300ms="equipamentoBusca"
                                    @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                    @keydown.arrow-down.prevent="aberto = true; if ($refs['e' + (destaque + 1)]) destaque++"
                                    @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                    @keydown.enter.prevent="$refs['e' + destaque]?.click()"
                                    class="campo-input pr-10" placeholder="Pesquisar por nº de série, fabricante ou modelo..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    @forelse ($equipamentosFiltrados as $idx => $e)
                                        <li x-ref="e{{ $idx }}" wire:key="eq-{{ $e->id }}"
                                            wire:click="selecionarEquipamento({{ $e->id }})" @click="aberto = false"
                                            @mouseenter="destaque = {{ $idx }}"
                                            :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                            class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            <span class="font-medium text-texto-forte">{{ $e->numero_serie ?? '—' }}</span>
                                            <span class="text-xs text-texto-fraco"> · {{ trim($e->tipo->rotulo() . ' ' . $e->modelo) ?: '—' }} · {{ $e->local?->cliente?->nome ?? 'sem cliente' }}</span>
                                        </li>
                                    @empty
                                        <li class="px-4 py-2 text-sm text-texto-medio">{{ $equipamentoBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum equipamento encontrado.' }}</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endif
                        @error('equipamento_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Local: escolher um existente OU criar um novo (ex.: mover para um cliente sem locais). --}}
                    <div>
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <label class="campo-label !mb-0">Local <span class="text-perigo-500">*</span></label>
                            <div class="inline-flex rounded-lg border border-borda bg-fundo p-1">
                                <button type="button" wire:click="definirModoLocal('existente')" class="rounded-md px-3 py-1 text-xs font-medium transition {{ $modoLocal === 'existente' ? 'bg-white text-texto-forte shadow-sm' : 'text-texto-medio hover:text-texto-forte' }}">Local existente</button>
                                <button type="button" wire:click="definirModoLocal('novo')" class="rounded-md px-3 py-1 text-xs font-medium transition {{ $modoLocal === 'novo' ? 'bg-white text-texto-forte shadow-sm' : 'text-texto-medio hover:text-texto-forte' }}">Novo local</button>
                            </div>
                        </div>

                        @if ($modoLocal === 'existente')
                            {{-- Pesquisa server-side (~30 resultados) — nunca carrega os ~600 locais. --}}
                            <div wire:key="combo-local" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                <input id="local-combo" type="text"
                                    wire:model.live.debounce.300ms="localBusca"
                                    @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                    @keydown.arrow-down.prevent="aberto = true; if ($refs['l' + (destaque + 1)]) destaque++"
                                    @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                    @keydown.enter.prevent="$refs['l' + destaque]?.click()"
                                    class="campo-input pr-10" placeholder="Pesquisar local por cliente ou designação..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    @forelse ($locaisFiltrados as $idx => $l)
                                        <li x-ref="l{{ $idx }}" wire:key="lo-{{ $l->id }}"
                                            wire:click="selecionarLocal({{ $l->id }})" @click="aberto = false"
                                            @mouseenter="destaque = {{ $idx }}"
                                            :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                            class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            <span class="font-medium text-texto-forte">{{ $l->cliente?->nome ?? '—' }}</span>
                                            <span class="text-xs text-texto-fraco"> · {{ $l->designacao }}</span>
                                        </li>
                                    @empty
                                        <li class="px-4 py-2 text-sm text-texto-medio">{{ $localBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum local encontrado.' }}</li>
                                    @endforelse
                                </ul>
                            </div>
                            @error('local_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        @else
                            {{-- Novo local: cliente de destino + designação (+ morada). Cria-o na hora. --}}
                            <div class="space-y-4 rounded-lg border border-borda bg-fundo px-4 py-4">
                                <div>
                                    <label class="campo-label" for="novolocal-cliente">Cliente de destino <span class="text-perigo-500">*</span></label>
                                    <div wire:key="combo-novolocal-cliente" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                        <input id="novolocal-cliente" type="text"
                                            wire:model.live.debounce.300ms="novoLocalClienteBusca"
                                            @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                            @keydown.arrow-down.prevent="aberto = true; if ($refs['nc' + (destaque + 1)]) destaque++"
                                            @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                            @keydown.enter.prevent="$refs['nc' + destaque]?.click()"
                                            class="campo-input pr-10" placeholder="Pesquisar cliente por nome ou NIF..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                        <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                            @forelse ($clientesFiltrados as $idx => $cl)
                                                <li x-ref="nc{{ $idx }}" wire:key="ncl-{{ $cl->id }}"
                                                    wire:click="selecionarNovoLocalCliente({{ $cl->id }})" @click="aberto = false"
                                                    @mouseenter="destaque = {{ $idx }}"
                                                    :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                                    class="cursor-pointer px-4 py-2 text-sm" role="option">
                                                    <span class="font-medium">{{ $cl->nome }}</span>
                                                    <span class="text-xs text-texto-fraco"> · NIF {{ $cl->nif ?? '—' }}</span>
                                                </li>
                                            @empty
                                                <li class="px-4 py-2 text-sm text-texto-medio">{{ $novoLocalClienteBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum cliente encontrado.' }}</li>
                                            @endforelse
                                        </ul>
                                    </div>
                                    @error('novoLocalClienteId') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="campo-label">Designação <span class="text-perigo-500">*</span></label>
                                        <input wire:model="novoLocalDesignacao" type="text" class="campo-input" placeholder="Ex: Instalação principal">
                                        @error('novoLocalDesignacao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="campo-label">Morada</label>
                                        <input wire:model="novoLocalMorada" type="text" class="campo-input" placeholder="Opcional">
                                        @error('novoLocalMorada') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                                <p class="text-xs text-texto-fraco">Cria o local para o cliente escolhido e associa-lhe o equipamento. Se já existir um local com esta designação, reutiliza-o.</p>
                            </div>
                        @endif
                    </div>

                </div>
            </section>

        </div>
    </main>
</div>
