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
                            @php($eqSel = $equipamentos->firstWhere('id', $equipamento_id))
                            <input type="text" class="campo-input bg-fundo" value="{{ $eqSel ? ($eqSel->local?->cliente?->nome ?? '—') . ' · ' . trim($eqSel->tipo->rotulo() . ' ' . $eqSel->modelo) . ' (' . ($eqSel->numero_serie ?? '—') . ')' : '—' }}" disabled>
                        @else
                            <div
                                x-data="{
                                    equipamentos: @js($equipamentos->map(fn ($e) => ['id' => $e->id, 'label' => ($e->local?->cliente?->nome ?? '—') . ' · ' . trim($e->tipo->rotulo() . ' ' . $e->modelo) . ' (' . ($e->numero_serie ?? '—') . ')'])->values()),
                                    inicial: @js((string) $equipamento_id),
                                    query: '',
                                    aberto: false,
                                    destaque: 0,
                                    norm(s) { return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
                                    get filtrados() {
                                        const n = this.norm(this.query);
                                        if (n === '') return this.equipamentos;
                                        return this.equipamentos.filter(e => this.norm(e.label).includes(n));
                                    },
                                    init() {
                                        const sel = this.equipamentos.find(e => String(e.id) === String(this.inicial));
                                        if (sel) this.query = sel.label;
                                    },
                                    abrir() { this.aberto = true; this.destaque = 0; },
                                    fechar() { this.aberto = false; },
                                    mover(d) { if (!this.aberto) { this.abrir(); return; } const n = this.filtrados.length; if (n === 0) return; this.destaque = (this.destaque + d + n) % n; },
                                    escolherDestaque() { const e = this.filtrados[this.destaque]; if (e) this.escolher(e); },
                                    escolher(e) { this.query = e.label; this.aberto = false; this.$wire.set('equipamento_id', e.id, false); },
                                }"
                                @click.outside="fechar()"
                                @keydown.escape.stop="fechar()"
                                class="relative"
                            >
                                <input id="equip-combo" type="text" x-model="query" @focus="abrir()" @click="abrir()" @input="abrir()"
                                    @keydown.arrow-down.prevent="mover(1)" @keydown.arrow-up.prevent="mover(-1)" @keydown.enter.prevent="escolherDestaque()"
                                    class="campo-input pr-10" placeholder="Pesquisar por cliente, modelo ou nº de série..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    <template x-for="(e, i) in filtrados" :key="e.id">
                                        <li @click="escolher(e)" @mouseenter="destaque = i" :class="i === destaque ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'" class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            <span x-text="e.label"></span>
                                        </li>
                                    </template>
                                    <li x-show="filtrados.length === 0" class="px-4 py-2 text-sm text-texto-medio">Nenhum equipamento encontrado.</li>
                                </ul>
                            </div>
                        @endif
                        @error('equipamento_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Local --}}
                    <div>
                        <label class="campo-label" for="local-combo">Local <span class="text-perigo-500">*</span></label>
                        <div
                            x-data="{
                                locais: @js($locais->map(fn ($l) => ['id' => $l->id, 'label' => ($l->cliente->nome ?? '—') . ' · ' . $l->designacao])->values()),
                                inicial: @js((string) $local_id),
                                query: '',
                                aberto: false,
                                destaque: 0,
                                norm(s) { return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
                                get filtrados() {
                                    const n = this.norm(this.query);
                                    if (n === '') return this.locais;
                                    return this.locais.filter(l => this.norm(l.label).includes(n));
                                },
                                init() {
                                    const sel = this.locais.find(l => String(l.id) === String(this.inicial));
                                    if (sel) this.query = sel.label;
                                },
                                abrir() { this.aberto = true; this.destaque = 0; },
                                fechar() { this.aberto = false; },
                                mover(d) { if (!this.aberto) { this.abrir(); return; } const n = this.filtrados.length; if (n === 0) return; this.destaque = (this.destaque + d + n) % n; },
                                escolherDestaque() { const l = this.filtrados[this.destaque]; if (l) this.escolher(l); },
                                escolher(l) { this.query = l.label; this.aberto = false; this.$wire.set('local_id', l.id, false); },
                            }"
                            @click.outside="fechar()"
                            @keydown.escape.stop="fechar()"
                            class="relative"
                        >
                            <input id="local-combo" type="text" x-model="query" @focus="abrir()" @click="abrir()" @input="abrir()"
                                @keydown.arrow-down.prevent="mover(1)" @keydown.arrow-up.prevent="mover(-1)" @keydown.enter.prevent="escolherDestaque()"
                                class="campo-input pr-10" placeholder="Pesquisar local por cliente ou designação..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                            <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                <template x-for="(l, i) in filtrados" :key="l.id">
                                    <li @click="escolher(l)" @mouseenter="destaque = i" :class="i === destaque ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'" class="cursor-pointer px-4 py-2 text-sm" role="option">
                                        <span x-text="l.label"></span>
                                    </li>
                                </template>
                                <li x-show="filtrados.length === 0" class="px-4 py-2 text-sm text-texto-medio">Nenhum local encontrado.</li>
                            </ul>
                        </div>
                        @error('local_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                </div>
            </section>

        </div>
    </main>
</div>
