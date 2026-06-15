<div x-data="{ tab: 'gerais' }">
    <x-topbar :breadcrumb="['Relatórios', 'Novo']">
        <a href="{{ route('relatorios') }}" class="botao-secundario">Cancelar</a>
        <button wire:click="submeter" wire:loading.attr="disabled" wire:target="submeter" class="botao-primario">
            <span wire:loading.remove wire:target="submeter">Gerar Relatório</span>
            <span wire:loading wire:target="submeter">A gerar…</span>
        </button>
    </x-topbar>

    <main class="flex-1 px-10 py-9">
        <div class="mx-auto max-w-5xl">

            {{-- Cabeçalho --}}
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Relatório de Intervenção Técnica</h1>
                    <p class="mt-2 text-sm text-texto-medio">Preencha todos os campos obrigatórios para submeter a folha de obra.</p>
                </div>
                <span class="etiqueta bg-aviso-100 text-aviso-500 uppercase tracking-wide">Em curso</span>
            </div>

            {{-- Tabs --}}
            <div class="mt-8 flex gap-8 border-b border-borda">
                <button @click="tab='gerais'" :class="tab==='gerais' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Dados Gerais</button>
                <button @click="tab='diagnostico'" :class="tab==='diagnostico' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Diagnóstico</button>
                <button @click="tab='fotografias'" :class="tab==='fotografias' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Fotografias</button>
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
                    <div x-show="aberto" x-transition class="grid grid-cols-2 gap-x-8 gap-y-6 px-6 pb-7">
                        <div>
                            <label class="campo-label">Equipamento <span class="text-perigo-500">*</span></label>
                            <select wire:model="equipamento_id" class="campo-select">
                                <option value="">— Selecione —</option>
                                @foreach ($equipamentos as $e)
                                    <option value="{{ $e->id }}">{{ $e->local?->cliente?->nome }} · {{ $e->tipo->rotulo() }} {{ $e->modelo }} ({{ $e->numero_serie }})</option>
                                @endforeach
                            </select>
                            @error('equipamento_id') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
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

                {{-- Checklist --}}
                <section class="cartao" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7l2 2 4-4"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Checklist</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="space-y-3 px-6 pb-7">
                        @foreach ($checklist as $i => $item)
                            <div class="flex items-center gap-3" wire:key="chk-{{ $i }}">
                                <input wire:model="checklist.{{ $i }}.concluido" type="checkbox" class="h-4 w-4 shrink-0 rounded border-borda text-verde-600 focus:ring-verde-500">
                                <input wire:model="checklist.{{ $i }}.descricao" type="text" class="campo-input flex-1" placeholder="Item de verificação...">
                                <input wire:model="checklist.{{ $i }}.observacao" type="text" class="campo-input w-48" placeholder="Observação">
                                <button type="button" wire:click="removerItem({{ $i }})" class="shrink-0 text-texto-fraco hover:text-perigo-500"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="adicionarItem" class="inline-flex items-center gap-1.5 text-sm font-medium text-verde-600 hover:text-verde-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                            Adicionar item
                        </button>
                    </div>
                </section>

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

                        @if ($fotos)
                            <div class="mt-4 grid grid-cols-4 gap-4">
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
                    <div class="grid grid-cols-2 gap-x-8 gap-y-6 border-t border-borda px-6 py-6">
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

            {{-- ===== FOTOGRAFIAS ===== --}}
            <div x-show="tab==='fotografias'" x-cloak class="mt-7">
                <section class="cartao">
                    <div class="flex items-center justify-between px-6 py-5">
                        <h2 class="text-lg font-semibold text-texto-forte">Fotografias selecionadas</h2>
                        <span class="text-sm text-texto-fraco">{{ count($fotos) }} {{ \Illuminate\Support\Str::plural('ficheiro', count($fotos)) }}</span>
                    </div>
                    <div class="border-t border-borda px-6 py-6">
                        @if ($fotos)
                            <div class="grid grid-cols-4 gap-4">
                                @foreach ($fotos as $foto)
                                    <div class="aspect-square overflow-hidden rounded-xl bg-zinc-800" wire:key="fotog-{{ $loop->index }}">
                                        <img src="{{ $foto->temporaryUrl() }}" class="h-full w-full object-cover">
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-texto-medio">Ainda não selecionou fotografias. Use o cartão "Registo Fotográfico" nos Dados Gerais.</p>
                        @endif
                    </div>
                </section>
            </div>

        </div>
    </main>
</div>
