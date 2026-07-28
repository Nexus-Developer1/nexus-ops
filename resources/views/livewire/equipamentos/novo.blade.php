<div>
    <x-topbar :breadcrumb="['Equipamentos', 'Novo equipamento']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <form wire:submit="guardar" class="mx-auto max-w-3xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Novo equipamento</h1>
                    <p class="mt-2 text-sm text-texto-medio">Registo manual de um equipamento não vendido por nós — fica disponível em contratos e relatórios.</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('ativos') }}" wire:navigate class="botao-secundario">Cancelar</a>
                    <button type="submit" class="botao-primario">Registar equipamento</button>
                </div>
            </div>

            {{-- Cliente e local --}}
            <section class="cartao mt-8">
                <div class="px-6 py-5"><h2 class="text-lg font-semibold text-texto-forte">Cliente e local</h2></div>
                <div class="grid grid-cols-1 gap-x-8 gap-y-6 border-t border-borda px-6 py-6 sm:grid-cols-2">
                    <div>
                        <label class="campo-label" for="cliente-combo">Cliente <span class="text-perigo-500">*</span></label>
                        <div x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                            <input id="cliente-combo" type="text"
                                wire:model.live.debounce.300ms="clienteBusca"
                                @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                @keydown.arrow-down.prevent="aberto = true; if ($refs['o' + (destaque + 1)]) destaque++"
                                @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                @keydown.enter.prevent="$refs['o' + destaque]?.click()"
                                class="campo-input pr-10" placeholder="Pesquisar por nome ou NIF..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                            <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                @forelse ($clientesFiltrados as $idx => $cl)
                                    <li x-ref="o{{ $idx }}" wire:key="cl-{{ $cl->id }}"
                                        wire:click="selecionarCliente({{ $cl->id }})" @click="aberto = false"
                                        @mouseenter="destaque = {{ $idx }}"
                                        :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                        class="cursor-pointer px-4 py-2 text-sm" role="option">
                                        <span class="font-medium">{{ $cl->nome }}</span>
                                        <span class="text-xs text-texto-fraco"> · NIF {{ $cl->nif ?? '—' }}</span>
                                    </li>
                                @empty
                                    <li class="px-4 py-2 text-sm text-texto-medio">{{ $clienteBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum cliente encontrado.' }}</li>
                                @endforelse
                            </ul>
                        </div>
                        @if ($cliente_id)
                            <button type="button" wire:click="limparCliente" class="mt-1.5 text-xs font-medium text-texto-fraco hover:text-perigo-600">Remover cliente</button>
                        @endif
                        @error('cliente_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="campo-label">Local</label>
                        @if (! $cliente_id)
                            <p class="text-sm text-texto-medio">Escolhe primeiro o cliente.</p>
                        @elseif ($locais->isEmpty())
                            <p class="text-sm text-texto-medio">Sem locais — vai para a "Instalação principal" do cliente.</p>
                        @else
                            <select wire:model="local_id" class="campo-select">
                                @foreach ($locais as $l)
                                    <option value="{{ $l->id }}">{{ $l->designacao }}</option>
                                @endforeach
                            </select>
                        @endif
                        @error('local_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Dados do equipamento --}}
            <section class="cartao mt-8">
                <div class="px-6 py-5"><h2 class="text-lg font-semibold text-texto-forte">Dados do equipamento</h2></div>
                <div class="grid grid-cols-1 gap-x-8 gap-y-6 border-t border-borda px-6 py-6 sm:grid-cols-2">
                    <div>
                        <label class="campo-label">Tipo <span class="text-perigo-500">*</span></label>
                        <select wire:model="tipo" class="campo-select">
                            @foreach ($tipos as $t)
                                <option value="{{ $t->value }}">{{ $t->rotulo() }}</option>
                            @endforeach
                        </select>
                        @error('tipo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Estado</label>
                        <select wire:model="estado" class="campo-select">
                            @foreach ($estados as $e)
                                <option value="{{ $e->value }}">{{ $e->rotulo() }}</option>
                            @endforeach
                        </select>
                        @error('estado') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Fabricante</label>
                        <input wire:model="fabricante" type="text" class="campo-input" placeholder="Ex: APC, Eaton, Vertiv...">
                        @error('fabricante') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Modelo</label>
                        <input wire:model="modelo" type="text" class="campo-input" placeholder="Ex: Smart-UPS SRT 5000">
                        @error('modelo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Nº de série</label>
                        <input wire:model="numero_serie" type="text" class="campo-input" placeholder="Ex: 3S2145X01234">
                        @error('numero_serie') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Cliente final</label>
                        <input wire:model="cliente_final" type="text" class="campo-input" placeholder="Utilizador real (mesmo que não esteja no sistema)">
                        @error('cliente_final') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="campo-label">Localização da instalação</label>
                        <input wire:model="localizacao_instalacao" type="text" class="campo-input" placeholder="Ex: Edifício B, piso 2, sala UPS">
                        @error('localizacao_instalacao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="campo-label">Instalação</label>
                            <input wire:model="data_instalacao" type="date" class="campo-input">
                            @error('data_instalacao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="campo-label">Fim de garantia</label>
                            <input wire:model="fim_garantia" type="date" class="campo-input">
                            @error('fim_garantia') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </section>

            {{-- Bancos de baterias (parte do mesmo equipamento) — um UPS pode ter vários --}}
            <section class="cartao mt-8">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-texto-forte">Bancos de baterias</h2>
                        <p class="mt-1 text-xs text-texto-fraco">Fazem parte deste equipamento (não são equipamentos à parte). Podes adicionar mais do que um.</p>
                    </div>
                    <button type="button" wire:click="adicionarBanco" class="botao-secundario">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                        Banco
                    </button>
                </div>
                <div class="border-t border-borda px-6 py-6">
                    @forelse ($bancos as $i => $banco)
                        <div wire:key="banco-{{ $i }}" class="mb-4 rounded-lg border border-borda p-4 last:mb-0">
                            <div class="mb-3 flex items-center justify-between">
                                <span class="text-sm font-semibold text-texto-medio">Banco {{ $i + 1 }}</span>
                                <button type="button" wire:click="removerBanco({{ $i }})" class="text-xs font-medium text-texto-fraco hover:text-perigo-600">Remover</button>
                            </div>
                            <div class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
                                <div>
                                    <label class="campo-label">Nº de série do banco</label>
                                    <input wire:model="bancos.{{ $i }}.numero_serie" type="text" class="campo-input" placeholder="Identificação do banco">
                                    @error('bancos.'.$i.'.numero_serie') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="campo-label">Modelo / fabricante</label>
                                    <input wire:model="bancos.{{ $i }}.modelo" type="text" class="campo-input" placeholder="Ex: Riello / bloco 12V">
                                    @error('bancos.'.$i.'.modelo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="campo-label">Capacidade (Ah / V)</label>
                                    <input wire:model="bancos.{{ $i }}.capacidade" type="text" class="campo-input" placeholder="Ex: 7 Ah / 384 V">
                                    @error('bancos.'.$i.'.capacidade') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="campo-label">Nº de baterias</label>
                                    <input wire:model="bancos.{{ $i }}.num_baterias" type="number" min="0" class="campo-input" placeholder="Ex: 16">
                                    @error('bancos.'.$i.'.num_baterias') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="campo-label">Data de instalação</label>
                                    <input wire:model="bancos.{{ $i }}.data_instalacao" type="date" class="campo-input">
                                    @error('bancos.'.$i.'.data_instalacao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="campo-label">Próxima troca</label>
                                    <input wire:model="bancos.{{ $i }}.proxima_troca" type="date" class="campo-input">
                                    @error('bancos.'.$i.'.proxima_troca') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-texto-medio">Sem bancos de baterias. Usa "Banco" para adicionar (a próxima troca mais próxima alimenta os alertas de manutenção).</p>
                    @endforelse
                </div>
            </section>

            {{-- Componentes do sistema (equipamentos compostos, ex.: deteção de incêndio) --}}
            <section class="cartao mt-8">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-texto-forte">Componentes do sistema</h2>
                        <p class="mt-1 text-xs text-texto-fraco">Para equipamentos compostos (ex.: deteção/extinção de incêndio) — designação + quantidade.</p>
                    </div>
                    <button type="button" wire:click="adicionarComponente" class="botao-secundario">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                        Componente
                    </button>
                </div>
                <div class="border-t border-borda px-6 py-6">
                    @forelse ($componentes as $i => $comp)
                        <div class="mb-3 flex items-start gap-3 last:mb-0" wire:key="comp-{{ $i }}">
                            <input wire:model="componentes.{{ $i }}.designacao" type="text" class="campo-input flex-1" placeholder="Ex: Detetor ótico convencional 701P">
                            <input wire:model="componentes.{{ $i }}.quantidade" type="number" min="1" class="campo-input w-24" placeholder="Qtd">
                            <button type="button" wire:click="removerComponente({{ $i }})" class="mt-2 shrink-0 text-texto-fraco hover:text-perigo-600" title="Remover">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    @empty
                        <p class="text-sm text-texto-medio">Sem componentes. Usa "+ Componente" para adicionar (cilindro, detetores, baterias, botoneiras…).</p>
                    @endforelse
                </div>
            </section>

            {{-- Notas --}}
            <section class="cartao mt-8">
                <div class="border-t-0 px-6 py-6">
                    <label class="campo-label">Notas</label>
                    <textarea wire:model="notas" rows="3" class="campo-input" placeholder="Observações sobre o equipamento..."></textarea>
                    @error('notas') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                </div>
            </section>

            <div class="mt-8 flex items-center justify-end gap-3">
                <a href="{{ route('ativos') }}" wire:navigate class="botao-secundario">Cancelar</a>
                <button type="submit" class="botao-primario">Registar equipamento</button>
            </div>
        </form>
    </main>
</div>
