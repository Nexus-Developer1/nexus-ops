<div>
    <x-topbar :breadcrumb="['Despesas', $despesaId ? 'Editar' : 'Nova']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-3xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $despesaId ? 'Editar despesa' : 'Nova despesa' }}</h1>
            <p class="mt-2 text-sm text-texto-medio">Custo da operação · ligue a um cliente para acompanhar a rentabilidade.</p>

            <form wire:submit="guardar" class="cartao mt-8 p-6 sm:p-8">
                <div class="grid grid-cols-1 gap-x-8 gap-y-6 sm:grid-cols-2">
                    <div>
                        <label class="campo-label">Data <span class="text-perigo-500">*</span></label>
                        <input wire:model="data" type="date" class="campo-input">
                        @error('data') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    {{-- Categoria: campo pesquisável ligado à lookup (cresce com o uso).
                         Filtra as existentes à medida que se escreve; "Adicionar «X»" guarda uma nova. --}}
                    <div
                        x-data="{
                            categorias: @js($categorias->map(fn ($c) => ['nome' => $c->nome])->values()),
                            valor: $wire.entangle('categoria'),
                            aberto: false,
                            destaque: 0,
                            norm(s) { return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
                            get filtrados() {
                                const n = this.norm(this.valor);
                                if (n === '') return this.categorias;
                                return this.categorias.filter(c => this.norm(c.nome).includes(n));
                            },
                            get existeExato() {
                                const n = this.norm(this.valor);
                                return n !== '' && this.categorias.some(c => this.norm(c.nome) === n);
                            },
                            abrir() { this.aberto = true; this.destaque = 0; },
                            fechar() { this.aberto = false; },
                            escolher(nome) { this.valor = nome; this.aberto = false; },
                            adicionar() {
                                const v = (this.valor || '').trim();
                                if (v === '') return;
                                this.$wire.adicionarCategoria(v).then(ok => {
                                    if (ok) { this.categorias.push({ nome: v }); this.aberto = false; }
                                });
                            },
                        }"
                        @click.outside="fechar()"
                        @keydown.escape.stop="fechar()"
                        class="relative"
                    >
                        <label class="campo-label" for="categoria-combo">Categoria <span class="text-perigo-500">*</span></label>
                        <input id="categoria-combo" type="text" x-model="valor"
                            @focus="abrir()" @click="abrir()" @input="abrir()"
                            @keydown.enter.prevent="existeExato ? fechar() : adicionar()"
                            class="campo-input" placeholder="Ex: Material, Deslocação..." autocomplete="off"
                            role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                        <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                            <template x-for="(c, i) in filtrados" :key="c.nome">
                                <li @click="escolher(c.nome)" @mouseenter="destaque = i" :class="i === destaque ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'" class="cursor-pointer px-4 py-2 text-sm" role="option">
                                    <span x-text="c.nome"></span>
                                </li>
                            </template>
                            <li x-show="valor && valor.trim() !== '' && !existeExato" @click="adicionar()" class="flex cursor-pointer items-center gap-1.5 px-4 py-2 text-sm font-medium text-verde-600 hover:bg-verde-50">
                                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                                <span>Adicionar «<span x-text="valor.trim()"></span>»</span>
                            </li>
                            <li x-show="filtrados.length === 0 && (!valor || valor.trim() === '')" class="px-4 py-2 text-sm text-texto-medio">Escreva para criar uma categoria.</li>
                        </ul>
                        @error('categoria') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        @error('novaCategoria') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="campo-label">Descrição <span class="text-perigo-500">*</span></label>
                        <input wire:model="descricao" type="text" class="campo-input" placeholder="Ex: Baterias 12V ×4, deslocação ao Porto...">
                        @error('descricao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="campo-label">Valor (€) <span class="text-perigo-500">*</span></label>
                        <input wire:model="valor" type="number" step="0.01" min="0" class="campo-input" placeholder="0,00">
                        @error('valor') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Intervenção (opcional): pesquisa GLOBAL. Ao associar, herda cliente/equipamento/contrato. --}}
                    <div class="sm:col-span-2">
                        <label class="campo-label" for="intervencao-combo">Intervenção (opcional)</label>
                        @if ($intervencao_id)
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-verde-300 bg-verde-50 px-4 py-3">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-verde-800">{{ $intervencaoRotulo }}</span>
                                    <span class="block text-xs text-verde-600">Cliente, equipamento e contrato herdados desta intervenção.</span>
                                </span>
                                <button type="button" wire:click="limparIntervencao" class="shrink-0 text-xs font-medium text-texto-fraco hover:text-perigo-600">Remover</button>
                            </div>
                        @else
                            <div x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                <input id="intervencao-combo" type="text"
                                    wire:model.live.debounce.300ms="intervencaoBusca"
                                    @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                    @keydown.arrow-down.prevent="aberto = true; if ($refs['iv' + (destaque + 1)]) destaque++"
                                    @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                    @keydown.enter.prevent="$refs['iv' + destaque]?.click()"
                                    class="campo-input pr-10" placeholder="Pesquisar por nº de relatório, nº de série ou cliente..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    @forelse ($intervencoesFiltradas as $idx => $iv)
                                        <li x-ref="iv{{ $idx }}" wire:key="iv-{{ $iv->id }}"
                                            wire:click="selecionarIntervencao({{ $iv->id }})" @click="aberto = false"
                                            @mouseenter="destaque = {{ $idx }}"
                                            :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                            class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            <span class="font-medium">{{ $iv->relatorio?->numero ?? ('Intervenção #' . $iv->id) }}</span>
                                            <span class="text-xs text-texto-fraco"> · {{ $iv->equipamento?->numero_serie ?? '—' }} · {{ $iv->equipamento?->local?->cliente?->nome ?? '—' }} · {{ $iv->data_inicio?->format('d/m/Y') ?? '—' }}</span>
                                        </li>
                                    @empty
                                        <li class="px-4 py-2 text-sm text-texto-medio">{{ trim($intervencaoBusca) === '' ? 'Escreva para pesquisar…' : 'Nenhuma intervenção encontrada.' }}</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endif
                        <p class="mt-1.5 text-xs text-texto-fraco">Liga a despesa a uma intervenção — cliente, equipamento e contrato são preenchidos automaticamente.</p>
                        @error('intervencao_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Cliente: pesquisa server-side (opcional). Bloqueado quando herdado da intervenção. --}}
                    <div>
                        <label class="campo-label" for="cliente-combo">Cliente (opcional)</label>
                        @if ($intervencao_id)
                            <input type="text" value="{{ $clienteBusca ?: '—' }}" disabled class="campo-input bg-fundo text-texto-medio">
                            <p class="mt-1.5 text-xs text-texto-fraco">Herdado da intervenção.</p>
                        @else
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
                        @endif
                        @error('cliente_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Faturável vs incluído no contrato. --}}
                    <div class="sm:col-span-2">
                        <label class="flex items-start gap-3 rounded-lg border border-borda px-4 py-3">
                            <input wire:model="faturavel" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                            <span>
                                <span class="block text-sm font-medium text-texto-forte">Faturável à parte</span>
                                <span class="block text-xs text-texto-fraco">Se desligado, conta como incluído no contrato (não faturado ao cliente).</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="mt-8 flex items-center justify-end gap-3 border-t border-borda pt-6">
                    <a href="{{ route('despesas') }}" wire:navigate class="botao-secundario">Cancelar</a>
                    <button type="submit" class="botao-primario">{{ $despesaId ? 'Guardar alterações' : 'Registar despesa' }}</button>
                </div>
            </form>
        </div>
    </main>
</div>
