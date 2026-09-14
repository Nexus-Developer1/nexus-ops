{{-- Origem dos equipamentos: contrato (modo contrato) ou nº de série / cliente (individual).
     Grelha própria — os filhos usam sm:col-span-2 e este bloco vive dentro de um wrapper. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-6">
    @if ($modo === 'contrato')
        {{-- Contrato: filtragem client-side instantânea (poucos contratos); ao escolher carrega os equipamentos. --}}
        <div class="sm:col-span-2">
            <label class="campo-label" for="contrato-combo">Contrato <span class="text-perigo-500">*</span></label>
            <div
                wire:key="combo-contrato"
                x-data="{
                    contratos: @js($contratos->map(fn ($c) => ['id' => $c->id, 'label' => $c->numero . ' · ' . ($c->cliente?->nome ?? '—')])->values()),
                    inicial: @js((string) $contratoBusca),
                    query: '',
                    aberto: false,
                    destaque: 0,
                    norm(s) { return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
                    get filtrados() {
                        const n = this.norm(this.query);
                        if (n === '') return this.contratos;
                        return this.contratos.filter(c => this.norm(c.label).includes(n));
                    },
                    init() { this.query = this.inicial; },
                    abrir() { this.aberto = true; this.destaque = 0; },
                    fechar() { this.aberto = false; },
                    mover(d) { if (!this.aberto) { this.abrir(); return; } const n = this.filtrados.length; if (n === 0) return; this.destaque = (this.destaque + d + n) % n; },
                    escolherDestaque() { const c = this.filtrados[this.destaque]; if (c) this.escolher(c); },
                    escolher(c) { this.query = c.label; this.aberto = false; this.$wire.selecionarContrato(c.id); },
                }"
                @click.outside="fechar()"
                @keydown.escape.stop="fechar()"
                class="relative"
            >
                <input id="contrato-combo" type="text" x-model="query"
                    @focus="abrir()" @click="abrir()" @input="abrir()"
                    @keydown.arrow-down.prevent="mover(1)" @keydown.arrow-up.prevent="mover(-1)" @keydown.enter.prevent="escolherDestaque()"
                    class="campo-input pr-10" placeholder="Pesquisar contrato por número ou cliente..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                    <template x-for="(c, i) in filtrados" :key="c.id">
                        <li @click="escolher(c)" @mouseenter="destaque = i" :class="i === destaque ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'" class="cursor-pointer px-4 py-2 text-sm" role="option">
                            <span x-text="c.label"></span>
                        </li>
                    </template>
                    <li x-show="filtrados.length === 0" class="px-4 py-2 text-sm text-texto-medio">Nenhum contrato encontrado.</li>
                </ul>
            </div>
            @error('contrato_id') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
        </div>

        {{-- Escolha de equipamentos por faixa (auto/lista/pesquisa), filtrada ao CONTRATO. --}}
        @include('livewire.relatorios.selecao-equipamentos')
    @else
    {{-- Atalho: pesquisa GLOBAL por nº de série → resolve o cliente automaticamente. --}}
    <div class="sm:col-span-2">
        <label class="campo-label" for="serie-combo">Nº de série do equipamento <span class="text-perigo-500">*</span></label>
        <div wire:key="combo-serie" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
            <input id="serie-combo" type="text"
                wire:model.live.debounce.300ms="serieBusca"
                @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                @keydown.arrow-down.prevent="aberto = true; if ($refs['s' + (destaque + 1)]) destaque++"
                @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                @keydown.enter.prevent="$refs['s' + destaque]?.click()"
                class="campo-input pr-10" placeholder="Escreve o nº de série — o cliente é preenchido automaticamente..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
            <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                @forelse ($equipamentosPorSerie as $idx => $e)
                    <li x-ref="s{{ $idx }}" wire:key="es-{{ $e->id }}"
                        wire:click="selecionarPorSerie({{ $e->id }})" @click="aberto = false"
                        @mouseenter="destaque = {{ $idx }}"
                        :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                        class="cursor-pointer px-4 py-2 text-sm" role="option">
                        <span class="font-medium">{{ $e->numero_serie ?? '—' }}</span>
                        <span class="text-xs text-texto-fraco"> · {{ trim($e->fabricante . ' ' . $e->modelo) ?: '—' }} · {{ $e->local?->cliente?->nome ?? '—' }}</span>
                    </li>
                @empty
                    <li class="px-4 py-2 text-sm text-texto-medio">{{ trim($serieBusca) === '' ? 'Escreve o nº de série…' : 'Nenhum equipamento com esse nº de série.' }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="sm:col-span-2 flex items-center gap-3 text-xs uppercase tracking-wide text-texto-fraco">
        <span class="h-px flex-1 bg-borda"></span>ou escolhe o cliente<span class="h-px flex-1 bg-borda"></span>
    </div>

    {{-- Individual: escolhe-se o CLIENTE e anexam-se todos os equipamentos dele. --}}
    <div class="sm:col-span-2">
        <label class="campo-label" for="cliente-combo">Cliente <span class="text-perigo-500">*</span></label>
        {{-- Pesquisa server-side (~20 resultados): não carrega os milhares de clientes. --}}
        <div wire:key="combo-cliente" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
            <input id="cliente-combo" type="text"
                wire:model.live.debounce.300ms="clienteBusca"
                @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                @keydown.arrow-down.prevent="aberto = true; if ($refs['c' + (destaque + 1)]) destaque++"
                @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                @keydown.enter.prevent="$refs['c' + destaque]?.click()"
                class="campo-input pr-10" placeholder="Pesquisar cliente por nome ou NIF..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
            <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                @forelse ($clientesFiltrados as $idx => $c)
                    <li x-ref="c{{ $idx }}" wire:key="cl-{{ $c->id }}"
                        wire:click="selecionarCliente({{ $c->id }})" @click="aberto = false"
                        @mouseenter="destaque = {{ $idx }}"
                        :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                        class="cursor-pointer px-4 py-2 text-sm" role="option">
                        {{ $c->nome }}@if ($c->nif) <span class="text-texto-fraco">· {{ $c->nif }}</span>@endif
                    </li>
                @empty
                    <li class="px-4 py-2 text-sm text-texto-medio">{{ $clienteBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum cliente encontrado.' }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- Escolha de equipamentos por faixa (auto/lista/pesquisa), filtrada ao CLIENTE. --}}
    @include('livewire.relatorios.selecao-equipamentos')
    @endif
</div>
