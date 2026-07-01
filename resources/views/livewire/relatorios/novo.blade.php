<div x-data="{ tab: 'gerais' }">
    <x-topbar :breadcrumb="['Relatórios', $relatorioId ? 'Rascunho' : 'Novo']">
        <a href="{{ route('relatorios') }}" class="botao-secundario">Cancelar</a>
        <button wire:click="guardarRascunho" wire:loading.attr="disabled" wire:target="guardarRascunho" class="botao-secundario">
            <span wire:loading.remove wire:target="guardarRascunho">Guardar rascunho</span>
            <span wire:loading wire:target="guardarRascunho">A guardar…</span>
        </button>
        <button wire:click="finalizar" wire:loading.attr="disabled" wire:target="finalizar" wire:confirm="Finalizar o relatório? Gera o PDF e fica como documento oficial." class="botao-primario">
            <span wire:loading.remove wire:target="finalizar">Finalizar relatório</span>
            <span wire:loading wire:target="finalizar">A finalizar…</span>
        </button>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">

            {{-- Cabeçalho --}}
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Relatório de Intervenção Técnica</h1>
                    <p class="mt-2 text-sm text-texto-medio">Preencha todos os campos obrigatórios para submeter a folha de obra.</p>
                </div>
                <span class="etiqueta {{ \App\Enums\EstadoRelatorio::Rascunho->classesEtiqueta() }} uppercase tracking-wide">Rascunho</span>
            </div>

            {{-- Tabs --}}
            <div class="mt-8 flex gap-8 border-b border-borda">
                <button @click="tab='gerais'" :class="tab==='gerais' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Dados Gerais</button>
                <button @click="tab='diagnostico'" :class="tab==='diagnostico' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Diagnóstico</button>
            </div>

            {{-- ===== DADOS GERAIS ===== --}}
            <div x-show="tab==='gerais'" class="space-y-5">

                {{-- Equipamento e Intervenção --}}
                <section class="cartao mt-7" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m6-14h1m-1 4h1m4-4h1m-1 4h1m-5 8v-4a1 1 0 011-1h2a1 1 0 011 1v4"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Equipamento e Intervenção</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-6 px-6 pb-7">
                        {{-- Modo: relatório de contrato (equipamentos vêm do contrato) vs individual (à mão). --}}
                        <div class="sm:col-span-2">
                            <label class="campo-label">Tipo de relatório</label>
                            <div class="inline-flex rounded-lg border border-borda bg-fundo p-1">
                                <button type="button" wire:click="definirModo('contrato')" class="rounded-md px-4 py-1.5 text-sm font-medium transition {{ $modo === 'contrato' ? 'bg-white text-texto-forte shadow-sm' : 'text-texto-medio hover:text-texto-forte' }}">Relatório de contrato</button>
                                <button type="button" wire:click="definirModo('individual')" class="rounded-md px-4 py-1.5 text-sm font-medium transition {{ $modo === 'individual' ? 'bg-white text-texto-forte shadow-sm' : 'text-texto-medio hover:text-texto-forte' }}">Relatório individual</button>
                            </div>
                        </div>

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

                            {{-- Equipamentos do contrato (pré-preenchidos e editáveis — remove os não intervencionados). --}}
                            <div class="sm:col-span-2">
                                <label class="campo-label">Equipamentos do contrato</label>
                                @if ($equipamentoPrincipal || $cobertosSelecionados->isNotEmpty())
                                    <div class="flex flex-wrap gap-2">
                                        @if ($equipamentoPrincipal)
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-verde-200 bg-verde-50 px-3 py-1 text-xs font-medium text-verde-700" wire:key="princ-{{ $equipamentoPrincipal->id }}">
                                                {{ $equipamentoPrincipal->numero_serie ?? '—' }} · {{ trim($equipamentoPrincipal->fabricante . ' ' . $equipamentoPrincipal->modelo) ?: '—' }}
                                                <span class="text-[10px] uppercase tracking-wide text-verde-600/70">principal</span>
                                                <button type="button" wire:click="removerEquipamentoDoRelatorio({{ $equipamentoPrincipal->id }})" class="text-verde-600/60 hover:text-perigo-600" title="Remover">
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </span>
                                        @endif
                                        @foreach ($cobertosSelecionados as $e)
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-borda bg-fundo px-3 py-1 text-xs text-texto-forte" wire:key="ctcob-{{ $e->id }}">
                                                {{ $e->numero_serie ?? '—' }} · {{ trim($e->fabricante . ' ' . $e->modelo) ?: '—' }}
                                                <button type="button" wire:click="removerEquipamentoDoRelatorio({{ $e->id }})" class="text-texto-fraco hover:text-perigo-600" title="Remover">
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-sm text-texto-medio">Escolhe um contrato para carregar os equipamentos.</p>
                                @endif
                                @error('equipamento_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                        @else
                        <div>
                            <label class="campo-label" for="equip-combo">Equipamento <span class="text-perigo-500">*</span></label>
                            {{-- Pesquisa server-side (~20 resultados): não carrega os ~17k equipamentos. --}}
                            <div wire:key="combo-equip-principal" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                <input id="equip-combo" type="text"
                                    wire:model.live.debounce.300ms="equipamentoBusca"
                                    @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                    @keydown.arrow-down.prevent="aberto = true; if ($refs['e' + (destaque + 1)]) destaque++"
                                    @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                    @keydown.enter.prevent="$refs['e' + destaque]?.click()"
                                    class="campo-input pr-10" placeholder="Pesquisar por nº de série, fabricante ou cliente..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    @forelse ($equipamentosPrincipalFiltrados as $idx => $e)
                                        <li x-ref="e{{ $idx }}" wire:key="ep-{{ $e->id }}"
                                            wire:click="selecionarEquipamentoPrincipal({{ $e->id }})" @click="aberto = false"
                                            @mouseenter="destaque = {{ $idx }}"
                                            :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                            class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            {{ $e->local?->cliente?->nome ?? '—' }} · {{ trim($e->tipo->rotulo() . ' ' . $e->modelo) }} ({{ $e->numero_serie ?? '—' }})
                                        </li>
                                    @empty
                                        <li class="px-4 py-2 text-sm text-texto-medio">{{ $equipamentoBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum equipamento encontrado.' }}</li>
                                    @endforelse
                                </ul>
                            </div>
                            @error('equipamento_id') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>

                        {{-- Equipamentos adicionais cobertos pelo mesmo relatório (além do principal). --}}
                        <div class="sm:col-span-2">
                            <label class="campo-label">Equipamentos adicionais cobertos</label>
                            {{-- Pesquisa server-side (~20 resultados); ao escolher, adiciona o chip. --}}
                            <div wire:key="combo-equip-coberto" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                <input type="text" wire:model.live.debounce.300ms="cobertoBusca"
                                    @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                    @keydown.arrow-down.prevent="aberto = true; if ($refs['co' + (destaque + 1)]) destaque++"
                                    @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                    @keydown.enter.prevent="$refs['co' + destaque]?.click()"
                                    class="campo-input" placeholder="Pesquisar e adicionar outro equipamento..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    @forelse ($equipamentosCobertosFiltrados as $idx => $e)
                                        <li x-ref="co{{ $idx }}" wire:key="eco-{{ $e->id }}"
                                            wire:click="adicionarEquipamentoCoberto({{ $e->id }})" @click="aberto = false"
                                            @mouseenter="destaque = {{ $idx }}"
                                            :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                            class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            {{ $e->local?->cliente?->nome ?? '—' }} · {{ trim($e->tipo->rotulo() . ' ' . $e->modelo) }} ({{ $e->numero_serie ?? '—' }})
                                        </li>
                                    @empty
                                        <li class="px-4 py-2 text-sm text-texto-medio">{{ $cobertoBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum equipamento encontrado.' }}</li>
                                    @endforelse
                                </ul>
                            </div>

                            @if ($cobertosSelecionados->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($cobertosSelecionados as $e)
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-borda bg-fundo px-3 py-1 text-xs text-texto-forte" wire:key="coberto-{{ $e->id }}">
                                            {{ $e->numero_serie ?? '—' }} · {{ trim($e->fabricante . ' ' . $e->modelo) ?: '—' }}
                                            <button type="button" wire:click="removerEquipamentoCoberto({{ $e->id }})" class="text-texto-fraco hover:text-perigo-600" title="Remover">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                            <p class="mt-1.5 text-xs text-texto-fraco">O equipamento principal acima é a referência da intervenção; estes são cobertos pelo mesmo relatório.</p>
                        </div>
                        @endif

                        <div>
                            <label class="campo-label">Tipo de intervenção <span class="text-perigo-500">*</span></label>
                            <select wire:model="tipo" class="campo-select">
                                @foreach ($tipos as $t)
                                    <option value="{{ $t->value }}">{{ $t->rotulo() }}</option>
                                @endforeach
                            </select>
                            @error('tipo') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="campo-label">Data de intervenção</label>
                            <input wire:model="data" type="date" class="campo-input">
                            @error('data') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <div
                            x-data="{
                                inicio: $wire.entangle('hora_inicio'),
                                fim: $wire.entangle('hora_fim'),
                                get duracao() {
                                    if (!this.inicio || !this.fim) return '';
                                    const [hi, mi] = this.inicio.split(':').map(Number);
                                    const [hf, mf] = this.fim.split(':').map(Number);
                                    let min = (hf * 60 + mf) - (hi * 60 + mi);
                                    if (isNaN(min) || min < 0) return '';
                                    const h = Math.floor(min / 60), m = min % 60;
                                    if (h && m) return h + 'h' + String(m).padStart(2, '0');
                                    if (h) return h + 'h';
                                    return m + 'min';
                                },
                            }"
                        >
                            <label class="campo-label">Horas</label>
                            <div class="grid grid-cols-2 gap-4">
                                <input type="time" x-model="inicio" class="campo-input" aria-label="Hora de início">
                                <input type="time" x-model="fim" class="campo-input" aria-label="Hora de fim">
                            </div>
                            <p x-show="duracao" x-cloak class="mt-1 text-xs text-texto-medio">Duração: <span class="font-medium text-texto-forte" x-text="duracao"></span></p>
                            @error('hora_inicio') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            @error('hora_fim') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </section>

                {{-- Constatações Técnicas --}}
                <section class="cartao" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Constatações Técnicas</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="px-6 pb-7">
                        <label class="campo-label">Resumo da intervenção</label>
                        <textarea wire:model="resumo" rows="3" class="campo-input resize-none" placeholder="Descreva as constatações técnicas observadas durante a intervenção…"></textarea>
                    </div>
                </section>

                {{-- Ficha de medições (UPS) — só no modo contrato, uma por equipamento coberto. --}}
                @if ($modo === 'contrato')
                <section class="cartao" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Ficha de medições (UPS)</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="space-y-4 px-6 pb-7">
                        @if ($equipamentoPrincipal || $cobertosSelecionados->isNotEmpty())
                            @if ($equipamentoPrincipal)
                                <x-relatorios.ficha-ups
                                    :prefixo="'fichas.' . $equipamentoPrincipal->id"
                                    :titulo="(trim($equipamentoPrincipal->fabricante . ' ' . $equipamentoPrincipal->modelo) ?: 'UPS') . ' · ' . ($equipamentoPrincipal->numero_serie ?? '—')"
                                    :principal="true" />
                            @endif
                            @foreach ($cobertosSelecionados as $e)
                                <x-relatorios.ficha-ups
                                    :prefixo="'fichas.' . $e->id"
                                    :titulo="(trim($e->fabricante . ' ' . $e->modelo) ?: 'UPS') . ' · ' . ($e->numero_serie ?? '—')"
                                    :principal="false" />
                            @endforeach
                        @else
                            <p class="text-sm text-texto-medio">Escolhe um contrato para preencher as fichas de medição dos equipamentos.</p>
                        @endif
                    </div>
                </section>
                @endif

                {{-- Checklist (relatório individual) --}}
                @if ($modo !== 'contrato')
                <section class="cartao" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7l2 2 4-4"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Checklist</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="space-y-4 px-6 pb-7" x-data="checklist">
                        {{-- Contentor das etapas (SortableJS — reordenar etapas) --}}
                        <div data-etapas-root class="space-y-4">
                            @foreach ($etapas as $ei => $etapa)
                                @php($total = count($etapa['itens']))
                                @php($feitos = collect($etapa['itens'])->where('concluido', true)->count())
                                <div wire:key="etapa-{{ $etapa['uid'] }}" data-etapa="{{ $etapa['uid'] }}" x-data="{ aberta: true }" class="rounded-lg border border-borda bg-fundo/40">
                                    {{-- Cabeçalho da etapa --}}
                                    <div class="flex items-center gap-2 px-3 py-2.5">
                                        <span class="pega-etapa cursor-grab text-texto-fraco transition hover:text-texto-medio" title="Arrastar etapa">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h.01M8 12h.01M8 18h.01M16 6h.01M16 12h.01M16 18h.01"/></svg>
                                        </span>
                                        <input wire:model="etapas.{{ $ei }}.titulo" type="text" class="campo-input flex-1 font-medium" placeholder="Título da etapa (ex.: Inspeção)">
                                        <span class="shrink-0 whitespace-nowrap text-xs text-texto-fraco">{{ $feitos }}/{{ $total }} concluídos</span>
                                        <button type="button" @click="aberta=!aberta" class="shrink-0 text-texto-fraco transition hover:text-texto-medio" title="Colapsar/expandir">
                                            <svg :class="aberta && 'rotate-180'" class="h-5 w-5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                        <button type="button" wire:click="removerEtapa('{{ $etapa['uid'] }}')" @if ($total) wire:confirm="Remover esta etapa e os seus {{ $total }} {{ \Illuminate\Support\Str::plural('item', $total) }}?" @endif class="shrink-0 text-texto-fraco transition hover:text-perigo-500" title="Remover etapa">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>

                                    {{-- Itens da etapa --}}
                                    <div x-show="aberta" x-transition class="space-y-2 px-3 pb-3">
                                        <div data-itens="{{ $etapa['uid'] }}" class="space-y-2">
                                            @foreach ($etapa['itens'] as $ii => $item)
                                                <div wire:key="item-{{ $item['uid'] }}" data-item="{{ $item['uid'] }}" class="flex items-center gap-2 rounded-lg bg-white px-2 py-1.5">
                                                    <span class="pega-item cursor-grab text-texto-fraco transition hover:text-texto-medio" title="Arrastar item">
                                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h.01M8 12h.01M8 18h.01M16 6h.01M16 12h.01M16 18h.01"/></svg>
                                                    </span>
                                                    <input wire:model.live="etapas.{{ $ei }}.itens.{{ $ii }}.concluido" type="checkbox" class="h-4 w-4 shrink-0 rounded border-borda text-verde-600 focus:ring-verde-500">
                                                    <input wire:model="etapas.{{ $ei }}.itens.{{ $ii }}.descricao" type="text" class="campo-input flex-1" placeholder="Item de verificação...">
                                                    <input wire:model="etapas.{{ $ei }}.itens.{{ $ii }}.observacao" type="text" class="campo-input w-48" placeholder="Observação">
                                                    <button type="button" wire:click="removerItem('{{ $etapa['uid'] }}', '{{ $item['uid'] }}')" class="shrink-0 text-texto-fraco transition hover:text-perigo-500" title="Remover item"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                                                </div>
                                            @endforeach
                                        </div>
                                        <button type="button" wire:click="adicionarItem('{{ $etapa['uid'] }}')" class="inline-flex items-center gap-1.5 text-sm font-medium text-verde-600 hover:text-verde-700">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                                            Adicionar item
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <button type="button" wire:click="adicionarEtapa" class="inline-flex items-center gap-1.5 text-sm font-medium text-verde-600 hover:text-verde-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                            Adicionar etapa
                        </button>
                    </div>
                </section>
                @endif

                {{-- Recomendações e Próximos Passos --}}
                <section class="cartao" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Recomendações e Próximos Passos</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="flex gap-3 px-6 pb-7">
                        <input wire:model="recomendacao" type="text" class="campo-input flex-1" placeholder="Ex: Substituição de baterias">
                        <select wire:model="prioridade" class="campo-select w-40 shrink-0">
                            <option value="Baixa">Baixa</option>
                            <option value="Normal">Normal</option>
                            <option value="Alta">Alta</option>
                        </select>
                    </div>
                </section>

                {{-- Registo Fotográfico --}}
                <section class="cartao" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Registo Fotográfico</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="px-6 pb-7">
                        <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-borda py-8 text-texto-fraco transition hover:border-verde-400 hover:text-verde-500">
                            <input type="file" wire:model="fotos" multiple accept="image/*" class="hidden">
                            <svg wire:loading.remove wire:target="fotos" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                            <svg wire:loading wire:target="fotos" class="h-7 w-7 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12a8 8 0 018-8"/></svg>
                            <span class="text-xs font-medium" wire:loading.remove wire:target="fotos">Carregar fotos (pode selecionar várias)</span>
                            <span class="text-xs font-medium" wire:loading wire:target="fotos">A enviar…</span>
                        </label>

                        @if ($anexosExistentes->count())
                            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                                @foreach ($anexosExistentes as $ax)
                                    <div class="group relative aspect-square overflow-hidden rounded-xl bg-zinc-800" wire:key="ax-{{ $ax->id }}">
                                        <img src="{{ route('anexos.ver', $ax) }}" class="h-full w-full object-cover">
                                        <button type="button" wire:click="removerAnexoExistente({{ $ax->id }})" class="absolute right-2 top-2 flex h-7 w-7 items-center justify-center rounded-lg bg-black/50 text-white opacity-0 transition group-hover:opacity-100 hover:bg-perigo-500">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($fotos)
                            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                                @foreach ($fotos as $foto)
                                    <div class="aspect-square overflow-hidden rounded-xl bg-zinc-800" wire:key="foto-{{ $loop->index }}">
                                        <img src="{{ $foto->temporaryUrl() }}" class="h-full w-full object-cover">
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('fotos.*') <p class="mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                </section>
            </div>

            {{-- ===== DIAGNÓSTICO ===== --}}
            <div x-show="tab==='diagnostico'" x-cloak class="space-y-5">
                <section class="cartao mt-7">
                    <div class="flex items-center gap-3 px-6 py-5">
                        <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span>
                        <h2 class="text-lg font-semibold text-texto-forte">Diagnóstico do Equipamento</h2>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-6 border-t border-borda px-6 py-6">
                        <div>
                            <label class="campo-label">Estado geral</label>
                            <select wire:model="estado_geral" class="campo-select">
                                <option value="">—</option>
                                <option value="Operacional">Operacional</option>
                                <option value="Degradado">Degradado</option>
                                <option value="Crítico">Crítico</option>
                            </select>
                        </div>
                        <div><label class="campo-label">Carga atual (%)</label><input wire:model="carga" type="number" class="campo-input" placeholder="Ex: 62"></div>
                        <div><label class="campo-label">Tensão de entrada (V)</label><input wire:model="tensao_entrada" type="number" class="campo-input" placeholder="Ex: 230"></div>
                        <div><label class="campo-label">Tensão de saída (V)</label><input wire:model="tensao_saida" type="number" class="campo-input" placeholder="Ex: 230"></div>
                        <div class="col-span-2"><label class="campo-label">Anomalias detetadas</label><textarea wire:model="anomalias" rows="2" class="campo-input resize-none" placeholder="Registe quaisquer anomalias observadas..."></textarea></div>
                    </div>
                </section>
            </div>

        </div>
    </main>
</div>
