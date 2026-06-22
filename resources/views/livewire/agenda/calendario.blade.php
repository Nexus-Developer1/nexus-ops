<div>
    <x-topbar :breadcrumb="['Manutenção', 'Agenda']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl" x-data="agendaCalendario">

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Agenda</h1>
                    <p class="mt-2 text-sm text-texto-medio">Arraste para reagendar · selecione um intervalo livre para criar evento ou ausência.</p>
                </div>
                <div class="flex items-center gap-3">
                    @unless (auth()->user()->ehCliente())
                        <button type="button" wire:click="abrirAusencia" class="botao-secundario" title="Marcar ausência de um técnico">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                            Marcar ausência
                        </button>
                    @endunless
                    @if ($urlIcal)
                        <a href="{{ $urlIcal }}" class="botao-secundario" title="Subscrever em calendário externo">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            iCal
                        </a>
                    @endif
                    @unless (auth()->user()->ehTecnico())
                        <select wire:model.live="tecnicoId" class="campo-select w-56">
                            <option value="">Todos os técnicos</option>
                            @foreach ($tecnicos as $t)
                                <option value="{{ $t['id'] }}">{{ $t['nome'] }}</option>
                            @endforeach
                        </select>
                    @endunless
                </div>
            </div>

            {{-- Confirmação (ex.: rascunho de relatório gerado a partir do evento) --}}
            @if (session('sucesso'))
                <div class="mt-6 flex items-center gap-2 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm font-medium text-verde-700">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    {{ session('sucesso') }}
                </div>
            @endif

            {{-- Aviso de conflito ao reagendar --}}
            <div x-show="erro" x-cloak x-transition
                 class="mt-6 flex items-center gap-2 rounded-lg border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm font-medium text-perigo-600">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span x-text="erro"></span>
            </div>

            {{-- Calendário (FullCalendar montado pelo Alpine) --}}
            <div class="cartao mt-6 p-5">
                <div x-ref="cal" wire:ignore></div>
            </div>

            {{-- Painel de detalhe do evento (iniciar visita → intervenção) --}}
            @if ($evento)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fecharModal">
                    <div class="w-full max-w-md rounded-xl border border-borda bg-white shadow-xl">
                        <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                            <div>
                                <h2 class="text-lg font-semibold text-texto-forte">{{ $evento->titulo }}</h2>
                                <p class="mt-1 text-sm text-texto-medio">{{ $evento->tipo->rotulo() }} · {{ $evento->estado->rotulo() }}</p>
                            </div>
                            <button wire:click="fecharModal" class="text-texto-fraco hover:text-texto-forte">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <dl class="space-y-3 px-6 py-5 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-texto-fraco">Quando</dt>
                                <dd class="text-right font-medium text-texto-forte">{{ $evento->inicio->translatedFormat('d M Y · H:i') }} – {{ $evento->fim->format('H:i') }}</dd>
                            </div>
                            @if ($evento->cliente)
                                <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Cliente</dt><dd class="text-right font-medium text-texto-forte">{{ $evento->cliente->nome }}</dd></div>
                            @endif
                            @if ($evento->equipamento)
                                <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Equipamento</dt><dd class="text-right font-medium text-texto-forte">{{ trim($evento->equipamento->fabricante . ' ' . $evento->equipamento->modelo) ?: $evento->equipamento->numero_serie }}</dd></div>
                            @endif
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Técnico</dt><dd class="text-right font-medium text-texto-forte">{{ $evento->tecnico?->nome ?? 'Por atribuir' }}</dd></div>
                        </dl>

                        <div class="flex items-center justify-end gap-3 border-t border-borda px-6 py-4">
                            @if ($evento->intervencao_id)
                                <a href="{{ route('intervencoes.formulario', $evento->intervencao_id) }}" class="botao-primario">Abrir intervenção</a>
                            @elseif (in_array($evento->tipo, [\App\Enums\TipoEvento::VisitaPreventiva, \App\Enums\TipoEvento::Intervencao]))
                                <button wire:click="fecharModal" class="botao-secundario">Fechar</button>
                                <button wire:click="iniciarVisita" wire:loading.attr="disabled" class="botao-primario">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Iniciar visita
                                </button>
                            @elseif ($evento->tipo === \App\Enums\TipoEvento::Outro)
                                <button wire:click="removerEvento" wire:confirm="Remover este evento?" class="botao inline-flex items-center gap-2 bg-perigo-600 px-5 py-2.5 text-white hover:bg-perigo-500">Remover</button>
                                <button wire:click="fecharModal" class="botao-secundario">Fechar</button>
                            @else
                                <button wire:click="fecharModal" class="botao-secundario">Fechar</button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Painel de detalhe de uma ausência --}}
            @if ($ausencia)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fecharAusencia">
                    <div class="w-full max-w-md rounded-xl border border-borda bg-white shadow-xl">
                        <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                            <h2 class="text-lg font-semibold text-texto-forte">Ausência</h2>
                            <button wire:click="fecharAusencia" class="text-texto-fraco hover:text-texto-forte">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <dl class="space-y-3 px-6 py-5 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Técnico</dt><dd class="text-right font-medium text-texto-forte">{{ $ausencia->tecnico?->nome }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Motivo</dt><dd class="text-right font-medium text-texto-forte">{{ $ausencia->motivo ?: '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">De</dt><dd class="text-right font-medium text-texto-forte">{{ $ausencia->inicio->translatedFormat('d M Y · H:i') }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Até</dt><dd class="text-right font-medium text-texto-forte">{{ $ausencia->fim->translatedFormat('d M Y · H:i') }}</dd></div>
                        </dl>
                        <div class="flex items-center justify-end gap-3 border-t border-borda px-6 py-4">
                            <button wire:click="fecharAusencia" class="botao-secundario">Fechar</button>
                            <button wire:click="removerAusencia" wire:confirm="Remover esta ausência?" class="botao inline-flex items-center gap-2 bg-perigo-600 px-5 py-2.5 text-white hover:bg-perigo-500">Remover</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Modal de criação de evento próprio (campo único "Tipo de evento") --}}
            @if ($modalCriar)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fecharCriar">
                    <form wire:submit="criarEvento" class="w-full max-w-md rounded-xl border border-borda bg-white shadow-xl">
                        <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                            <h2 class="text-lg font-semibold text-texto-forte">Novo evento</h2>
                            <button type="button" wire:click="fecharCriar" class="text-texto-fraco hover:text-texto-forte">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="space-y-5 px-6 py-5">
                            {{-- Tipo de evento: campo único pesquisável ligado ao lookup (cresce com o uso).
                                 Filtra os existentes à medida que se escreve; "Adicionar «X»" guarda um novo. --}}
                            <div
                                x-data="{
                                    tipos: @js($assuntos->map(fn ($a) => ['nome' => $a->nome])->values()),
                                    titulo: $wire.entangle('formTitulo'),
                                    aberto: false,
                                    destaque: 0,
                                    norm(s) { return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
                                    get filtrados() {
                                        const n = this.norm(this.titulo);
                                        if (n === '') return this.tipos;
                                        return this.tipos.filter(t => this.norm(t.nome).includes(n));
                                    },
                                    get existeExato() {
                                        const n = this.norm(this.titulo);
                                        return n !== '' && this.tipos.some(t => this.norm(t.nome) === n);
                                    },
                                    abrir() { this.aberto = true; this.destaque = 0; },
                                    fechar() { this.aberto = false; },
                                    escolher(nome) { this.titulo = nome; this.aberto = false; },
                                    adicionar() {
                                        const v = (this.titulo || '').trim();
                                        if (v === '') return;
                                        this.$wire.adicionarAssunto(v).then(ok => {
                                            if (ok) { this.tipos.push({ nome: v }); this.aberto = false; }
                                        });
                                    },
                                }"
                                @click.outside="fechar()"
                                @keydown.escape.stop="fechar()"
                                class="relative"
                            >
                                <label class="campo-label" for="tipo-combo">Tipo de evento</label>
                                <input id="tipo-combo" type="text" x-model="titulo"
                                    @focus="abrir()" @click="abrir()" @input="abrir()"
                                    @keydown.enter.prevent="existeExato ? fechar() : adicionar()"
                                    class="campo-input" placeholder="Ex: Reunião" autocomplete="off"
                                    role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    <template x-for="(t, i) in filtrados" :key="t.nome">
                                        <li @click="escolher(t.nome)" @mouseenter="destaque = i" :class="i === destaque ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'" class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            <span x-text="t.nome"></span>
                                        </li>
                                    </template>
                                    <li x-show="titulo && titulo.trim() !== '' && !existeExato" @click="adicionar()" class="flex cursor-pointer items-center gap-1.5 px-4 py-2 text-sm font-medium text-verde-600 hover:bg-verde-50">
                                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                                        <span>Adicionar «<span x-text="titulo.trim()"></span>»</span>
                                    </li>
                                    <li x-show="filtrados.length === 0 && (!titulo || titulo.trim() === '')" class="px-4 py-2 text-sm text-texto-medio">Escreva para criar um tipo de evento.</li>
                                </ul>
                                @error('formTitulo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                @error('novoAssunto') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>

                            {{-- Equipamento opcional: pesquisa server-side (~17k registos, nunca carregar tudo).
                                 Ao escolher, o evento herda local e cliente do equipamento. --}}
                            <div>
                                <label class="campo-label" for="equip-evento-combo">Equipamento (opcional)</label>
                                <div x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                    <input
                                        id="equip-evento-combo"
                                        type="text"
                                        wire:model.live.debounce.300ms="formEquipamentoBusca"
                                        @focus="aberto = true"
                                        @click="aberto = true"
                                        @input="aberto = true; destaque = 0"
                                        @keydown.arrow-down.prevent="aberto = true; if ($refs['eopt' + (destaque + 1)]) destaque++"
                                        @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                        @keydown.enter.prevent="$refs['eopt' + destaque]?.click()"
                                        class="campo-input pr-10"
                                        placeholder="Pesquisar por nº de série, fabricante ou modelo... (opcional)"
                                        autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                    <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>

                                    <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                        @forelse ($equipamentosFiltrados as $idx => $eq)
                                            <li x-ref="eopt{{ $idx }}" wire:key="eq-{{ $eq->id }}"
                                                wire:click="selecionarEquipamento({{ $eq->id }})"
                                                @click="aberto = false"
                                                @mouseenter="destaque = {{ $idx }}"
                                                :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                                class="cursor-pointer px-4 py-2 text-sm" role="option">
                                                <span class="font-medium">{{ $eq->numero_serie ?? '—' }}</span>
                                                <span class="text-xs text-texto-fraco"> · {{ trim($eq->fabricante . ' ' . $eq->modelo) ?: '—' }} · {{ $eq->local?->cliente?->nome ?? '—' }}</span>
                                            </li>
                                        @empty
                                            <li class="px-4 py-2 text-sm text-texto-medio">
                                                {{ $formEquipamentoBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum equipamento encontrado.' }}
                                            </li>
                                        @endforelse
                                    </ul>
                                </div>
                                @error('formEquipamentoId') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="campo-label">Técnico (opcional)</label>
                                <select wire:model="formTecnicoId" class="campo-select">
                                    <option value="">Por atribuir</option>
                                    @foreach ($tecnicos as $t)
                                        <option value="{{ $t['id'] }}">{{ $t['nome'] }}</option>
                                    @endforeach
                                </select>
                                @error('formTecnicoId') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="campo-label">Início</label>
                                    <input wire:model="formInicio" type="datetime-local" class="campo-input">
                                    @error('formInicio') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="campo-label">Fim</label>
                                    <input wire:model="formFim" type="datetime-local" class="campo-input">
                                    @error('formFim') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-borda px-6 py-4">
                            <button type="button" wire:click="fecharCriar" class="botao-secundario">Cancelar</button>
                            <button type="submit" class="botao-primario">Criar</button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Modal dedicado de marcação de ausência (grava em tecnico_disponibilidade) --}}
            @if ($modalAusencia)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fecharMarcarAusencia">
                    <form wire:submit="marcarAusencia" class="w-full max-w-md rounded-xl border border-borda bg-white shadow-xl">
                        <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                            <h2 class="text-lg font-semibold text-texto-forte">Marcar ausência</h2>
                            <button type="button" wire:click="fecharMarcarAusencia" class="text-texto-fraco hover:text-texto-forte">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="space-y-5 px-6 py-5">
                            <div>
                                <label class="campo-label">Técnico</label>
                                <select wire:model="ausTecnicoId" class="campo-select">
                                    <option value="">Selecione...</option>
                                    @foreach ($tecnicos as $t)
                                        <option value="{{ $t['id'] }}">{{ $t['nome'] }}</option>
                                    @endforeach
                                </select>
                                @error('ausTecnicoId') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="campo-label">Motivo</label>
                                <input wire:model="ausMotivo" type="text" class="campo-input" placeholder="Ex: Férias">
                                @error('ausMotivo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="campo-label">Início</label>
                                    <input wire:model="ausInicio" type="datetime-local" class="campo-input">
                                    @error('ausInicio') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="campo-label">Fim</label>
                                    <input wire:model="ausFim" type="datetime-local" class="campo-input">
                                    @error('ausFim') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-borda px-6 py-4">
                            <button type="button" wire:click="fecharMarcarAusencia" class="botao-secundario">Cancelar</button>
                            <button type="submit" class="botao-primario">Marcar ausência</button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Legenda de técnicos --}}
            <div class="mt-5 flex flex-wrap items-center gap-6 text-sm text-texto-medio">
                @foreach ($tecnicos as $t)
                    <span class="inline-flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full" style="background-color: {{ $t['cor'] }}"></span> {{ $t['nome'] }}
                    </span>
                @endforeach
                <span class="inline-flex items-center gap-2">
                    <span class="h-3 w-3 rounded-full" style="background-color: #94a3b8"></span> Por atribuir
                </span>
            </div>

        </div>
    </main>
</div>
