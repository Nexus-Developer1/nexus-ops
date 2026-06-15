<div>
    <x-topbar :breadcrumb="['Manutenção', 'Contratos', $contrato ? 'Editar' : 'Novo']" />

    <main class="flex-1 px-10 py-9">
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
                <div class="grid grid-cols-2 gap-x-8 gap-y-6 border-t border-borda px-6 py-6">
                    <div>
                        <label class="campo-label">Nº do contrato</label>
                        <input wire:model="numero" type="text" class="campo-input" placeholder="2026/0007">
                        @error('numero') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Cliente</label>
                        <select wire:model.live="cliente_id" class="campo-select">
                            <option value="">Selecione o cliente...</option>
                            @foreach ($clientes as $c)
                                <option value="{{ $c->id }}">{{ $c->nome }}</option>
                            @endforeach
                        </select>
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
                    <div>
                        <label class="campo-label">Modelo de faturação</label>
                        <select wire:model="modelo_faturacao" class="campo-select">
                            <option value="">Selecione...</option>
                            @foreach ($modelosFaturacao as $m)
                                <option value="{{ $m->value }}">{{ $m->rotulo() }}</option>
                            @endforeach
                        </select>
                        @error('modelo_faturacao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
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
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($equipamentos as $e)
                                <label class="flex items-center gap-3 rounded-lg border border-borda px-4 py-3 hover:bg-fundo" wire:key="equip-{{ $e->id }}">
                                    <input wire:model="equipamentoIds" type="checkbox" value="{{ $e->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-texto-forte">{{ $e->fabricante }} {{ $e->modelo }}</span>
                                        <span class="block truncate text-xs text-texto-fraco">{{ $e->numero_serie ?? '—' }} · {{ $e->local->designacao }}</span>
                                    </span>
                                </label>
                            @endforeach
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
                        <div class="mb-3 grid grid-cols-12 items-start gap-3 last:mb-0" wire:key="plano-{{ $i }}">
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
                        <div class="mb-3 grid grid-cols-12 items-start gap-3 last:mb-0" wire:key="sla-{{ $i }}">
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
