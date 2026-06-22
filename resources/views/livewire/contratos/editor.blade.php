<div>
    <x-topbar :breadcrumb="['Manutenção', 'Contratos', $contrato ? 'Editar' : 'Novo']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <form wire:submit="guardar" class="mx-auto max-w-4xl">

            <div class="flex items-center justify-between">
                <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $contrato ? 'Editar contrato' : 'Novo contrato' }}</h1>
                <div class="flex items-center gap-3">
                    <a href="{{ $contrato ? route('contratos.ficha', $contrato) : route('contratos') }}" wire:navigate class="botao-secundario">Cancelar</a>
                    <button type="submit" class="botao-primario">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Guardar
                    </button>
                </div>
            </div>

            {{-- Dados gerais --}}
            <section class="cartao mt-8">
                <div class="px-6 py-5"><h2 class="text-lg font-semibold text-texto-forte">Dados gerais</h2></div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-6 border-t border-borda px-6 py-6">
                    <div>
                        <label class="campo-label">Nº do contrato</label>
                        <input wire:model="numero" type="text" class="campo-input" placeholder="2026/0007">
                        @error('numero') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label" for="cliente-combo">Cliente</label>
                        {{-- Combobox com pesquisa server-side (nome/NIF/nº ERP). Guarda em cliente_id. --}}
                        <div x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                            <input
                                id="cliente-combo"
                                type="text"
                                wire:model.live.debounce.300ms="clienteBusca"
                                @focus="aberto = true"
                                @click="aberto = true"
                                @input="aberto = true; destaque = 0"
                                @keydown.arrow-down.prevent="aberto = true; if ($refs['opt' + (destaque + 1)]) destaque++"
                                @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                @keydown.enter.prevent="$refs['opt' + destaque]?.click()"
                                class="campo-input pr-10"
                                placeholder="Pesquisar por nome, NIF ou nº de cliente..."
                                autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                            <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>

                            <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                @forelse ($clientesFiltrados as $idx => $cl)
                                    <li x-ref="opt{{ $idx }}" wire:key="cl-{{ $cl->id }}"
                                        wire:click="selecionarCliente({{ $cl->id }})"
                                        @click="aberto = false"
                                        @mouseenter="destaque = {{ $idx }}"
                                        :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                        class="cursor-pointer px-4 py-2 text-sm" role="option">
                                        <span class="font-medium">{{ $cl->nome }}</span>
                                        <span class="text-xs text-texto-fraco"> · NIF {{ $cl->nif ?? '—' }} · Nº {{ $cl->id_erp ?? '—' }}</span>
                                    </li>
                                @empty
                                    <li class="px-4 py-2 text-sm text-texto-medio">
                                        {{ $clienteBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum cliente encontrado.' }}
                                    </li>
                                @endforelse
                            </ul>
                        </div>
                        @error('cliente_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Data de início</label>
                        <input wire:model="data_inicio" type="date" class="campo-input">
                        @error('data_inicio') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Data de fim</label>
                        <input wire:model="data_fim" type="date" class="campo-input">
                        @error('data_fim') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Tipo</label>
                        <select wire:model="tipo" class="campo-select">
                            <option value="">Selecione...</option>
                            @foreach ($tiposContrato as $t)
                                <option value="{{ $t->value }}">{{ $t->rotulo() }}</option>
                            @endforeach
                        </select>
                        @error('tipo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div x-data="{ adicionando: false, novo: '' }">
                        <label class="campo-label">Modelo de faturação</label>

                        {{-- Seleção de um modelo existente --}}
                        <select x-show="!adicionando" wire:model="modelo_faturacao_id" class="campo-select">
                            <option value="">Selecione...</option>
                            @foreach ($modelosFaturacao as $m)
                                <option value="{{ $m->id }}">{{ $m->nome }}</option>
                            @endforeach
                        </select>

                        {{-- Adicionar um novo modelo (fica guardado na BD) --}}
                        <div x-show="adicionando" x-cloak class="flex gap-2">
                            <input x-model="novo" type="text" class="campo-input flex-1" placeholder="Nome do novo modelo"
                                @keydown.enter.prevent="$wire.adicionarModelo(novo).then(ok => { if (ok) { adicionando = false; novo = ''; } })">
                            <button type="button" class="botao-primario shrink-0"
                                @click="$wire.adicionarModelo(novo).then(ok => { if (ok) { adicionando = false; novo = ''; } })">Guardar</button>
                            <button type="button" class="botao-secundario shrink-0"
                                @click="adicionando = false; novo = ''; $wire.cancelarModelo()">Cancelar</button>
                        </div>

                        <button type="button" x-show="!adicionando" @click="adicionando = true"
                            class="mt-1.5 inline-flex items-center gap-1.5 text-sm font-medium text-verde-600 hover:text-verde-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                            Adicionar outro registo
                        </button>

                        @error('modelo_faturacao_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        @error('novoModelo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Valor (€)</label>
                        <input wire:model="valor" type="number" step="0.01" class="campo-input" placeholder="Ex: 1200.00">
                        @error('valor') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Período de faturação</label>
                        <input wire:model="periodo_faturacao" type="text" class="campo-input" placeholder="Ex: mensal, trimestral...">
                        @error('periodo_faturacao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Aviso de renovação (dias)</label>
                        <input wire:model="periodo_aviso_dias" type="number" class="campo-input" placeholder="30">
                        @error('periodo_aviso_dias') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-center gap-3 pt-7">
                        <input wire:model="renovacao_automatica" type="checkbox" id="renov" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                        <label for="renov" class="text-sm text-texto-medio">Renovação automática</label>
                    </div>
                    <div class="col-span-2">
                        <label class="campo-label">Coberturas</label>
                        <textarea wire:model="coberturas" rows="2" class="campo-input" placeholder="O que está incluído no contrato..."></textarea>
                    </div>
                    <div class="col-span-2">
                        <label class="campo-label">Exclusões</label>
                        <textarea wire:model="exclusoes" rows="2" class="campo-input" placeholder="O que fica de fora..."></textarea>
                    </div>
                </div>
            </section>

            {{-- Equipamentos cobertos --}}
            <section class="cartao mt-8">
                <div class="px-6 py-5"><h2 class="text-lg font-semibold text-texto-forte">Equipamentos cobertos</h2></div>
                <div class="border-t border-borda px-6 py-6">
                    @if (! $cliente_id)
                        <p class="text-sm text-texto-medio">Selecione um cliente para ver os equipamentos disponíveis.</p>
                    @elseif ($equipamentos->isEmpty())
                        <p class="text-sm text-texto-medio">Este cliente não tem equipamentos registados.</p>
                    @else
                        <div
                            x-data="{
                                busca: '',
                                itens: @js($equipamentos->map(fn ($e) => ['nome' => trim($e->fabricante . ' ' . $e->modelo), 'serie' => $e->numero_serie ?? ''])->values()),
                                norm(s) { return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
                                visivel(i) {
                                    const n = this.norm(this.busca);
                                    if (n === '') return true;
                                    const it = this.itens[i];
                                    return this.norm(it.nome).includes(n) || this.norm(it.serie).includes(n);
                                },
                                get nenhum() {
                                    const n = this.norm(this.busca);
                                    if (n === '') return false;
                                    return !this.itens.some(it => this.norm(it.nome).includes(n) || this.norm(it.serie).includes(n));
                                },
                            }"
                        >
                            <div class="relative mb-4">
                                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 17a6 6 0 100-12 6 6 0 000 12z"/></svg>
                                <input type="text" x-model="busca" placeholder="Pesquisar por nome ou nº de série..." autocomplete="off" class="campo-input pl-10 pr-10">
                                <button type="button" x-show="busca !== ''" x-cloak @click="busca = ''" class="absolute right-3 top-1/2 -translate-y-1/2 text-texto-fraco hover:text-texto-forte" aria-label="Limpar pesquisa">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ($equipamentos as $e)
                                    <label x-show="visivel({{ $loop->index }})" class="flex items-center gap-3 rounded-lg border border-borda px-4 py-3 hover:bg-fundo" wire:key="equip-{{ $e->id }}">
                                        <input wire:model="equipamentoIds" type="checkbox" value="{{ $e->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-texto-forte">{{ $e->fabricante }} {{ $e->modelo }}</span>
                                            <span class="block truncate text-xs text-texto-fraco">{{ $e->numero_serie ?? '—' }} · {{ $e->local->designacao }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p x-show="nenhum" x-cloak class="mt-1 text-sm text-texto-medio">Sem equipamentos correspondentes.</p>
                        </div>
                    @endif
                </div>
            </section>

            {{-- Planos de visita --}}
            <section class="cartao mt-8">
                <div class="flex items-center justify-between px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-texto-forte">Planos de visita</h2>
                        <p class="mt-0.5 text-xs text-texto-fraco">Periodicidade por tipo de equipamento — alimenta a geração de visitas preventivas.</p>
                    </div>
                    <button type="button" wire:click="adicionarPlano" class="botao-secundario">+ Plano</button>
                </div>
                <div class="border-t border-borda px-6 py-6">
                    @forelse ($planos as $i => $plano)
                        <div class="mb-3 grid grid-cols-1 sm:grid-cols-12 items-start gap-3 last:mb-0" wire:key="plano-{{ $i }}">
                            <div class="col-span-4">
                                <select wire:model="planos.{{ $i }}.equipamento_tipo" class="campo-select">
                                    <option value="">Tipo...</option>
                                    @foreach ($tiposEquipamento as $t)
                                        <option value="{{ $t->value }}">{{ $t->rotulo() }}</option>
                                    @endforeach
                                </select>
                                @error('planos.'.$i.'.equipamento_tipo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-4">
                                <select wire:model="planos.{{ $i }}.periodicidade" class="campo-select">
                                    <option value="">Periodicidade...</option>
                                    @foreach ($periodicidades as $p)
                                        <option value="{{ $p->value }}">{{ $p->rotulo() }}</option>
                                    @endforeach
                                </select>
                                @error('planos.'.$i.'.periodicidade') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-3">
                                <input wire:model="planos.{{ $i }}.duracao_estimada_min" type="number" class="campo-input" placeholder="Duração (min)">
                            </div>
                            <div class="col-span-1 flex justify-end pt-2">
                                <button type="button" wire:click="removerPlano({{ $i }})" class="text-texto-fraco hover:text-perigo-600" title="Remover">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-texto-medio">Sem planos de visita. Adicione pelo menos um para poder ativar o contrato.</p>
                    @endforelse
                </div>
            </section>

            {{-- SLAs --}}
            <section class="cartao mt-8">
                <div class="flex items-center justify-between px-6 py-5">
                    <h2 class="text-lg font-semibold text-texto-forte">SLAs</h2>
                    <button type="button" wire:click="adicionarSla" class="botao-secundario">+ SLA</button>
                </div>
                <div class="border-t border-borda px-6 py-6">
                    @forelse ($slas as $i => $sla)
                        <div class="mb-3 grid grid-cols-1 sm:grid-cols-12 items-start gap-3 last:mb-0" wire:key="sla-{{ $i }}">
                            <div class="col-span-3">
                                <select wire:model="slas.{{ $i }}.prioridade" class="campo-select">
                                    <option value="">Prioridade...</option>
                                    @foreach ($prioridades as $p)
                                        <option value="{{ $p->value }}">{{ $p->rotulo() }}</option>
                                    @endforeach
                                </select>
                                @error('slas.'.$i.'.prioridade') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-3">
                                <input wire:model="slas.{{ $i }}.tempo_resposta_horas" type="number" class="campo-input" placeholder="Resposta (h)">
                            </div>
                            <div class="col-span-3">
                                <input wire:model="slas.{{ $i }}.tempo_resolucao_horas" type="number" class="campo-input" placeholder="Resolução (h)">
                            </div>
                            <div class="col-span-2">
                                <select wire:model="slas.{{ $i }}.horario_cobertura" class="campo-select">
                                    <option value="8x5">8x5</option>
                                    <option value="24x7">24x7</option>
                                </select>
                            </div>
                            <div class="col-span-1 flex justify-end pt-2">
                                <button type="button" wire:click="removerSla({{ $i }})" class="text-texto-fraco hover:text-perigo-600" title="Remover">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-texto-medio">Sem SLAs definidos.</p>
                    @endforelse
                </div>
            </section>

        </form>
    </main>
</div>
