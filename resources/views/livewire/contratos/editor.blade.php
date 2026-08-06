<div>
    <x-topbar :breadcrumb="['Manutenção', 'Contratos', $contrato ? 'Editar' : 'Novo']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <form wire:submit="guardar" class="mx-auto max-w-4xl">

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $contrato ? 'Editar contrato' : 'Novo contrato' }}</h1>
                    <p class="mt-2 text-sm text-texto-medio">Os campos marcados com <span class="text-perigo-500">*</span> são obrigatórios.</p>
                </div>
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
                        <label class="campo-label">Nº do contrato <span class="text-perigo-500">*</span></label>
                        <input wire:model="numero" type="text" class="campo-input" placeholder="2026/0007">
                        @error('numero') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label" for="cliente-combo">Cliente <span class="text-perigo-500">*</span></label>
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
                        <label class="campo-label">Data de início <span class="text-perigo-500">*</span></label>
                        <input wire:model="data_inicio" type="date" class="campo-input">
                        @error('data_inicio') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Data de fim <span class="text-perigo-500">*</span></label>
                        <input wire:model="data_fim" type="date" class="campo-input">
                        @error('data_fim') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Visitas incluídas</label>
                        <input wire:model="visitas_incluidas" type="number" min="1" class="campo-input" placeholder="—">
                        <p class="mt-1.5 text-xs text-texto-fraco">Opcional — total pela vida do contrato. Vazio = sem controlo de saldo.</p>
                        @error('visitas_incluidas') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Tipo <span class="text-perigo-500">*</span></label>
                        <select wire:model="tipo" class="campo-select">
                            <option value="">Selecione...</option>
                            @foreach ($tiposContrato as $t)
                                <option value="{{ $t->value }}">{{ $t->rotulo() }}</option>
                            @endforeach
                        </select>
                        @error('tipo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div x-data="{ adicionando: false, novo: '' }">
                        <label class="campo-label">Modelo de faturação <span class="text-perigo-500">*</span></label>

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
                        <label class="campo-label">Aviso de renovação (dias) <span class="text-perigo-500">*</span></label>
                        <input wire:model="periodo_aviso_dias" type="number" class="campo-input" placeholder="30">
                        @error('periodo_aviso_dias') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-center gap-3 sm:pt-7">
                        <input wire:model="renovacao_automatica" type="checkbox" id="renov" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                        <label for="renov" class="text-sm text-texto-medio">Renovação automática</label>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="campo-label">Coberturas</label>
                        <textarea wire:model="coberturas" rows="2" class="campo-input" placeholder="O que está incluído no contrato..."></textarea>
                    </div>
                    <div class="sm:col-span-2">
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
                        {{-- Filtro por tipo (UPS / Deteção de incêndio / …): só com 2+ tipos no cliente.
                             "Selecionar todos" respeita o tipo ativo — marca só esse tipo. --}}
                        @if ($tiposEquipamentos->count() > 1)
                            <div class="mb-4 flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="$set('filtroTipoEquipamento', '')"
                                    class="rounded-full px-3 py-1.5 text-xs font-medium transition {{ $filtroTipoEquipamento === '' ? 'bg-verde-600 text-white' : 'border border-borda text-texto-medio hover:text-texto-forte' }}">
                                    Todos
                                </button>
                                @foreach ($tiposEquipamentos as $t)
                                    <button type="button" wire:click="$set('filtroTipoEquipamento', '{{ $t->value }}')"
                                        class="rounded-full px-3 py-1.5 text-xs font-medium transition {{ $filtroTipoEquipamento === $t->value ? 'bg-verde-600 text-white' : 'border border-borda text-texto-medio hover:text-texto-forte' }}">
                                        {{ $t->rotulo() }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        <div
                            wire:key="picker-equipamentos-{{ $filtroTipoEquipamento ?: 'todos' }}"
                            x-data="{
                                busca: '',
                                itens: @js($equipamentos->map(fn ($e) => ['nome' => trim($e->fabricante . ' ' . $e->modelo), 'serie' => $e->numero_serie ?? '', 'local' => $e->localInstalacao()])->values()),
                                norm(s) { return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
                                casa(it, n) { return this.norm(it.nome).includes(n) || this.norm(it.serie).includes(n) || this.norm(it.local).includes(n); },
                                visivel(i) {
                                    const n = this.norm(this.busca);
                                    if (n === '') return true;
                                    return this.casa(this.itens[i], n);
                                },
                                get nenhum() {
                                    const n = this.norm(this.busca);
                                    if (n === '') return false;
                                    return !this.itens.some(it => this.casa(it, n));
                                },
                            }"
                        >
                            {{-- Marcar/desmarcar todos de uma vez (conveniência para contratos que cobrem muitos). --}}
                            <div class="mb-3 flex items-center justify-between">
                                <span class="text-xs text-texto-medio">{{ count($equipamentoIds) }} selecionado(s)</span>
                                <div class="flex items-center gap-4">
                                    <button type="button" wire:click="selecionarTodosEquipamentos" class="text-xs font-medium text-verde-700 hover:text-verde-800">Selecionar todos</button>
                                    <button type="button" wire:click="limparEquipamentos" class="text-xs font-medium text-texto-medio hover:text-texto-forte">Limpar</button>
                                </div>
                            </div>
                            <div class="relative mb-4">
                                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 17a6 6 0 100-12 6 6 0 000 12z"/></svg>
                                <input type="text" x-model="busca" placeholder="Pesquisar por nome, nº de série ou local..." autocomplete="off" class="campo-input pl-10 pr-10">
                                <button type="button" x-show="busca !== ''" x-cloak @click="busca = ''" class="absolute right-3 top-1/2 -translate-y-1/2 text-texto-fraco hover:text-texto-forte" aria-label="Limpar pesquisa">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ($equipamentos as $e)
                                    <label x-show="visivel({{ $loop->index }})" class="flex items-center gap-3 rounded-lg border border-borda px-4 py-3 hover:bg-fundo" wire:key="equip-{{ $e->id }}">
                                        <input wire:model="equipamentoIds" type="checkbox" value="{{ $e->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-texto-forte">{{ trim($e->fabricante . ' ' . $e->modelo) ?: $e->tipo->rotulo() }}</span>
                                            <span class="block truncate text-xs text-texto-fraco">{{ $e->numero_serie ?? '—' }}</span>
                                            {{-- ONDE está instalado (morada real, não o nome do local). --}}
                                            <span class="mt-0.5 flex items-center gap-1 text-xs text-texto-medio">
                                                <svg class="h-3 w-3 shrink-0 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                <span class="truncate">{{ $e->localInstalacao() }}</span>
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p x-show="nenhum" x-cloak class="mt-1 text-sm text-texto-medio">Sem equipamentos correspondentes.</p>
                        </div>
                    @endif
                </div>
            </section>

            {{-- SLAs --}}
            <section class="cartao mt-8">
                <div class="flex items-center justify-between px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-texto-forte">SLAs</h2>
                        <p class="mt-1 text-xs text-texto-fraco">Opcionais; em cada linha adicionada, a <span class="text-perigo-500">*</span>prioridade e a cobertura (8x5/24x7) são obrigatórias.</p>
                    </div>
                    <button type="button" wire:click="adicionarSla" class="botao-secundario">+ SLA</button>
                </div>
                <div class="border-t border-borda px-6 py-6">
                    @forelse ($slas as $i => $sla)
                        <div class="mb-4 grid grid-cols-2 sm:grid-cols-12 items-start gap-3 border-b border-borda pb-4 last:mb-0 last:border-0 last:pb-0 sm:border-0 sm:pb-0" wire:key="sla-{{ $i }}">
                            <div class="col-span-2 sm:col-span-3">
                                <select wire:model="slas.{{ $i }}.prioridade" class="campo-select">
                                    <option value="">Prioridade... *</option>
                                    @foreach ($prioridades as $p)
                                        <option value="{{ $p->value }}">{{ $p->rotulo() }}</option>
                                    @endforeach
                                </select>
                                @error('slas.'.$i.'.prioridade') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-3">
                                {{-- Resposta: em horas OU NBD (Next Business Day) — o NBD desativa as horas. --}}
                                <input wire:model="slas.{{ $i }}.tempo_resposta_horas" type="number" class="campo-input disabled:bg-fundo disabled:text-texto-fraco" placeholder="Resposta (h)" @if (! empty($slas[$i]['resposta_nbd'])) disabled @endif>
                                <label class="mt-1.5 inline-flex cursor-pointer items-center gap-1.5 text-xs text-texto-medio">
                                    <input type="checkbox" wire:model.live="slas.{{ $i }}.resposta_nbd" class="h-3.5 w-3.5 rounded border-borda text-verde-600 focus:ring-verde-600">
                                    NBD (next business day)
                                </label>
                            </div>
                            <div class="sm:col-span-3">
                                <input wire:model="slas.{{ $i }}.tempo_resolucao_horas" type="number" class="campo-input" placeholder="Resolução (h)">
                            </div>
                            <div class="sm:col-span-2">
                                <select wire:model="slas.{{ $i }}.horario_cobertura" class="campo-select">
                                    <option value="8x5">8x5</option>
                                    <option value="24x7">24x7</option>
                                </select>
                            </div>
                            <div class="col-span-2 flex justify-end sm:col-span-1 sm:pt-2">
                                <button type="button" wire:click="removerSla({{ $i }})" class="inline-flex items-center gap-1.5 text-sm font-medium text-texto-fraco hover:text-perigo-600 sm:gap-0" title="Remover">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    <span class="sm:hidden">Remover</span>
                                </button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-texto-medio">Sem SLAs definidos.</p>
                    @endforelse
                </div>
            </section>

            {{-- Alertas de visita programados: data + texto editável do aviso. Entram no painel
                 de alertas, no dashboard e no email diário a partir de 7 dias antes da data. --}}
            <section class="cartao mt-8">
                <div class="flex items-center justify-between px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-texto-forte">Alertas de visita</h2>
                        <p class="mt-1 text-xs text-texto-fraco">Programa avisos para agendar as visitas (as incluídas marcam-se à mão na agenda). O texto é editável e aparece no alerta a partir de 7 dias antes da data.</p>
                    </div>
                    <button type="button" wire:click="adicionarAlertaVisita" class="botao-secundario">+ Alerta</button>
                </div>
                <div class="border-t border-borda px-6 py-6">
                    @forelse ($alertasVisita as $i => $alerta)
                        <div class="mb-4 grid grid-cols-2 items-start gap-3 border-b border-borda pb-4 last:mb-0 last:border-0 last:pb-0 sm:grid-cols-12 sm:border-0 sm:pb-0" wire:key="alerta-visita-{{ $i }}">
                            <div class="col-span-2 sm:col-span-3">
                                <input wire:model="alertasVisita.{{ $i }}.data" type="date" class="campo-input">
                                @error('alertasVisita.'.$i.'.data') <p class="mt-1.5 text-xs text-perigo-500">Escolha a data do aviso.</p> @enderror
                            </div>
                            <div class="col-span-2 sm:col-span-8">
                                <input wire:model="alertasVisita.{{ $i }}.texto" type="text" class="campo-input" placeholder="Texto do aviso — ex.: Agendar 1.ª visita preventiva">
                                @error('alertasVisita.'.$i.'.texto') <p class="mt-1.5 text-xs text-perigo-500">Escreva o texto do aviso.</p> @enderror
                            </div>
                            <div class="col-span-2 flex justify-end sm:col-span-1 sm:pt-2">
                                <button type="button" wire:click="removerAlertaVisita({{ $i }})" class="inline-flex items-center gap-1.5 text-sm font-medium text-texto-fraco hover:text-perigo-600 sm:gap-0" title="Remover">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    <span class="sm:hidden">Remover</span>
                                </button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-texto-medio">Sem alertas programados. Usa "+ Alerta" para marcar a data e o texto do aviso.</p>
                    @endforelse
                </div>
            </section>

        </form>

        {{-- Popup pós-gravação (contrato em rascunho): ativar / suspender / manter rascunho.
             wire:key: bloco condicional com chave estável (lição do banner do dashboard). --}}
        @if ($modalEstado)
            <div wire:key="modal-estado-contrato" class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4">
                <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-borda bg-white shadow-xl">
                    <div class="border-b border-borda px-6 py-5">
                        <h2 class="text-lg font-semibold text-texto-forte">Contrato guardado ✓</h2>
                        <p class="mt-1 text-sm text-texto-medio">O que quer fazer com o contrato {{ $numero }}?</p>
                    </div>
                    <div class="space-y-3 px-6 py-5">
                        @if (count($equipamentoIds) > 0)
                            <button type="button" wire:click="decidirEstado('ativar')" class="botao-primario w-full justify-center">Ativar já</button>
                        @else
                            <button type="button" disabled class="botao-primario w-full cursor-not-allowed justify-center opacity-50" title="Associe pelo menos um equipamento antes de ativar">Ativar já</button>
                            <p class="text-center text-xs text-aviso-500">Para ativar, associe pelo menos um equipamento ao contrato.</p>
                        @endif
                        <button type="button" wire:click="decidirEstado('suspender')" class="botao-secundario w-full justify-center">Suspender</button>
                        <button type="button" wire:click="decidirEstado('rascunho')" class="w-full rounded-lg px-4 py-2.5 text-sm font-medium text-texto-medio transition hover:bg-fundo">Deixar em rascunho (decido depois)</button>
                    </div>
                    <div class="border-t border-borda px-6 py-4">
                        <p class="text-xs text-texto-fraco">Ativar põe o contrato em vigor — as visitas agendam-se depois, à mão, na agenda (nada é gerado automaticamente).</p>
                    </div>
                </div>
            </div>
        @endif
    </main>
</div>
