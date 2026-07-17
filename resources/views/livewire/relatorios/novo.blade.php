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

            {{-- Tabs: Dados Gerais / Diagnóstico + um separador por equipamento (ambos os modos). --}}
            <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-1 border-b border-borda">
                <button @click="tab='gerais'" :class="tab==='gerais' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Dados Gerais</button>

                @if ($equipamentoPrincipal || $cobertosSelecionados->isNotEmpty())
                    <span class="mx-1 h-4 w-px bg-borda" aria-hidden="true"></span>
                    @if ($equipamentoPrincipal)
                        <button wire:key="tab-btn-{{ $equipamentoPrincipal->id }}" @click="tab='equip-{{ $equipamentoPrincipal->id }}'" :class="tab==='equip-{{ $equipamentoPrincipal->id }}' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px inline-flex items-center gap-1.5 border-b-2 pb-3 text-sm transition">
                            {{ $equipamentoPrincipal->numero_serie ?? '—' }}
                            <span class="rounded-full bg-verde-50 px-1.5 py-0.5 text-[9px] font-medium uppercase tracking-wide text-verde-700">principal</span>
                        </button>
                    @endif
                    @foreach ($cobertosSelecionados as $e)
                        <button wire:key="tab-btn-{{ $e->id }}" @click="tab='equip-{{ $e->id }}'" :class="tab==='equip-{{ $e->id }}' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">
                            {{ $e->numero_serie ?? '—' }}
                        </button>
                    @endforeach
                @endif
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

                            {{-- Escolha de equipamentos por faixa (auto/lista/pesquisa), filtrada ao CONTRATO. --}}
                            @include('livewire.relatorios.selecao-equipamentos')
                        @else
                        {{-- Atalho: pesquisa GLOBAL por nº de série → resolve o cliente automaticamente. --}}
                        <div class="sm:col-span-2">
                            <label class="campo-label" for="serie-combo">Nº de série do equipamento</label>
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
                            <p class="mt-1.5 text-xs text-texto-fraco">Atalho: escolhe o equipamento pelo nº de série e o cliente é resolvido automaticamente.</p>
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

                        {{-- Técnicos: o principal (quem cria) fica fixo; podem juntar-se outros técnicos.
                             A lista vem da BD a cada render, por isso reflete quem for entrando. --}}
                        <div class="sm:col-span-2">
                            <label class="campo-label">Técnicos</label>
                            <div class="flex flex-wrap gap-2">
                                @if ($tecnicoPrincipal)
                                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-verde-300 bg-verde-50 px-3 py-2 text-sm font-medium text-verde-700">
                                        {{ $tecnicoPrincipal->nome }}
                                        <span class="text-xs font-normal text-verde-600">· principal</span>
                                    </span>
                                @endif

                                @foreach ($tecnicos as $t)
                                    @if (! $tecnicoPrincipal || $t->id !== $tecnicoPrincipal->id)
                                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition {{ in_array($t->id, $colaboradorIds) ? 'border-verde-400 bg-verde-50 text-verde-700' : 'border-borda text-texto-medio hover:bg-fundo' }}">
                                            <input type="checkbox" wire:model.live="colaboradorIds" value="{{ $t->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                                            {{ $t->nome }}
                                        </label>
                                    @endif
                                @endforeach
                            </div>

                            @if ($tecnicos->where('id', '!=', optional($tecnicoPrincipal)->id)->isEmpty())
                                <p class="mt-1.5 text-xs text-texto-fraco">Não há outros técnicos para adicionar.</p>
                            @endif
                            @error('colaboradorIds.*') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
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

                {{-- Recomendações e próximos passos passaram para CADA ficha de equipamento (abaixo). --}}

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
                                        {{-- Sempre visível: no telemóvel não há hover, e é o ecrã que os técnicos usam
                                             em campo. Alvo de toque maior (h-9 w-9) e fundo com contraste sobre a foto. --}}
                                        <button type="button" wire:click="removerAnexoExistente({{ $ax->id }})" wire:confirm="Remover esta foto?" class="absolute right-1.5 top-1.5 flex h-9 w-9 items-center justify-center rounded-lg bg-black/60 text-white transition hover:bg-perigo-500">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Fotos escolhidas mas ainda por gravar (acumuladas entre seleções). --}}
                        @if ($fotosNovas)
                            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                                @foreach ($fotosNovas as $indice => $foto)
                                    <div class="relative aspect-square overflow-hidden rounded-xl bg-zinc-800" wire:key="foto-nova-{{ $indice }}">
                                        <img src="{{ $foto->temporaryUrl() }}" class="h-full w-full object-cover">
                                        <button type="button" wire:click="removerFotoNova({{ $indice }})" class="absolute right-1.5 top-1.5 flex h-9 w-9 items-center justify-center rounded-lg bg-black/60 text-white transition hover:bg-perigo-500" title="Remover">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('fotos.*') <p class="mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        @error('fotosNovas.*') <p class="mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                </section>
            </div>

            {{-- ===== FICHAS DE MEDIÇÃO (uma "página" por equipamento) — ambos os modos ===== --}}
            {{-- Nota: construir a lista com diretivas INLINE (bloco raw de PHP partiria a compilação). --}}
            @if ($equipamentoPrincipal || $cobertosSelecionados->isNotEmpty())
                @php($equipamentosFicha = collect())
                @if ($equipamentoPrincipal) @php($equipamentosFicha->push(['e' => $equipamentoPrincipal, 'principal' => true])) @endif
                @foreach ($cobertosSelecionados as $e) @php($equipamentosFicha->push(['e' => $e, 'principal' => false])) @endforeach
                @foreach ($equipamentosFicha as $item)
                    @php($e = $item['e'])
                    <div x-show="tab==='equip-{{ $e->id }}'" x-cloak class="space-y-5" wire:key="tab-ficha-{{ $e->id }}">
                        <section class="cartao mt-7">
                            <div class="flex items-center justify-between gap-3 px-6 py-5">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span>
                                    <div class="min-w-0">
                                        <h2 class="flex items-center gap-2 text-lg font-semibold text-texto-forte">
                                            <span class="truncate">{{ $e->numero_serie ?? '—' }}</span>
                                            @if ($item['principal'])
                                                <span class="shrink-0 rounded-full bg-verde-50 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-verde-700">principal</span>
                                            @endif
                                        </h2>
                                        <p class="truncate text-sm text-texto-medio">{{ trim($e->fabricante . ' ' . $e->modelo) ?: 'UPS' }}</p>
                                    </div>
                                </div>
                                {{-- Remove o equipamento do relatório e volta aos Dados Gerais. --}}
                                <button type="button" @click="tab='gerais'" wire:click="removerEquipamentoDoRelatorio({{ $e->id }})" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-texto-medio transition hover:text-perigo-600" title="Remover equipamento do relatório">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    Remover
                                </button>
                            </div>
                            <div class="border-t border-borda px-6 py-6">
                                <x-relatorios.ficha-ups :prefixo="'fichas.' . $e->id" />
                            </div>
                        </section>
                    </div>
                @endforeach
            @endif

        </div>
    </main>
</div>
