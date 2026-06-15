<div x-data="{ tab: 'gerais' }">
    <x-topbar :breadcrumb="['Relatórios', 'Intervenção #' . $intervencao->id]">
        <button wire:click="guardar" class="botao-secundario">Guardar Rascunho</button>
        <button wire:click="finalizar" wire:confirm="Finalizar a intervenção e gerar o relatório?" class="botao-primario">Finalizar Relatório</button>
    </x-topbar>

    <main class="flex-1 px-10 py-9">
        <div class="mx-auto max-w-5xl">

            {{-- Cabeçalho --}}
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Relatório de Intervenção Técnica</h1>
                    <p class="mt-2 text-sm text-texto-medio">Preencha todos os campos obrigatórios para submeter a folha de obra.</p>
                </div>
                <span class="etiqueta {{ $intervencao->estado->classesEtiqueta() }} uppercase tracking-wide">{{ $intervencao->estado->rotulo() }}</span>
            </div>

            {{-- Tabs --}}
            <div class="mt-8 flex gap-8 border-b border-borda">
                <button @click="tab='gerais'" :class="tab==='gerais' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Dados Gerais</button>
                <button @click="tab='diagnostico'" :class="tab==='diagnostico' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Diagnóstico</button>
                <button @click="tab='fotografias'" :class="tab==='fotografias' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Fotografias</button>
            </div>

            {{-- ===== DADOS GERAIS ===== --}}
            <div x-show="tab==='gerais'" class="space-y-5">
                {{-- Cliente e equipamento (read-only) --}}
                <section class="cartao mt-7" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m6-14h1m-1 4h1m4-4h1m-1 4h1m-5 8v-4a1 1 0 011-1h2a1 1 0 011 1v4"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Dados do Cliente e Equipamento</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="grid grid-cols-2 gap-x-8 gap-y-6 px-6 pb-7">
                        <div><label class="campo-label">Cliente</label><input type="text" class="campo-input bg-fundo" value="{{ $intervencao->equipamento->local->cliente->nome }}" disabled></div>
                        <div><label class="campo-label">Referência interna</label><input type="text" class="campo-input bg-fundo" value="{{ $intervencao->equipamento->numero_serie }}" disabled></div>
                        <div><label class="campo-label">Tipo de ativo</label><input type="text" class="campo-input bg-fundo" value="{{ $intervencao->equipamento->tipo->rotulo() }} · {{ $intervencao->equipamento->modelo }}" disabled></div>
                        <div><label class="campo-label">Data de intervenção</label><input type="text" class="campo-input bg-fundo" value="{{ $intervencao->data_inicio?->format('d/m/Y H:i') ?? '—' }}" disabled></div>
                    </div>
                </section>

                {{-- Constatações técnicas --}}
                <section class="cartao" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Constatações Técnicas</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="space-y-5 px-6 pb-7">
                        @if ($intervencao->tipo->value === 'corretiva')
                            <div><label class="campo-label">Problema reportado</label><textarea wire:model.live.debounce.800ms="descricao_problema" rows="2" class="campo-input resize-none" placeholder="Descreva o problema que motivou a intervenção..."></textarea></div>
                        @endif
                        <div><label class="campo-label">Resumo da intervenção</label><textarea wire:model.live.debounce.800ms="trabalho_realizado" rows="3" class="campo-input resize-none" placeholder="Descreva o trabalho realizado durante a intervenção..."></textarea></div>
                    </div>
                </section>

                {{-- Recomendações --}}
                <section class="cartao" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Recomendações e Observações</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="px-6 pb-7">
                        <textarea wire:model.live.debounce.800ms="observacoes" rows="3" class="campo-input resize-none" placeholder="Ex: Substituição de baterias recomendada no próximo trimestre."></textarea>
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
                            <select wire:model.live="estado_geral" class="campo-select">
                                <option value="">—</option>
                                <option value="Operacional">Operacional</option>
                                <option value="Degradado">Degradado</option>
                                <option value="Crítico">Crítico</option>
                            </select>
                        </div>
                        <div><label class="campo-label">Carga atual (%)</label><input wire:model.live.debounce.800ms="carga" type="number" class="campo-input" placeholder="Ex: 62"></div>
                        <div><label class="campo-label">Tensão de entrada (V)</label><input wire:model.live.debounce.800ms="tensao_entrada" type="number" class="campo-input" placeholder="Ex: 230"></div>
                        <div><label class="campo-label">Tensão de saída (V)</label><input wire:model.live.debounce.800ms="tensao_saida" type="number" class="campo-input" placeholder="Ex: 230"></div>
                        <div class="col-span-2"><label class="campo-label">Anomalias detetadas</label><textarea wire:model.live.debounce.800ms="anomalias" rows="2" class="campo-input resize-none" placeholder="Registe quaisquer anomalias observadas..."></textarea></div>
                    </div>
                </section>

                {{-- Checklist --}}
                <section class="cartao">
                    <div class="flex items-center justify-between px-6 py-5">
                        <h2 class="text-lg font-semibold text-texto-forte">Checklist</h2>
                        <span class="text-sm text-texto-fraco">{{ $intervencao->checklistItens->where('concluido', true)->count() }}/{{ $intervencao->checklistItens->count() }}</span>
                    </div>
                    <div class="border-t border-borda px-6 py-4">
                        @forelse ($intervencao->checklistItens as $item)
                            <div class="flex items-center gap-3 py-2" wire:key="item-{{ $item->id }}">
                                <button wire:click="alternarItem({{ $item->id }})" class="flex h-5 w-5 shrink-0 items-center justify-center rounded border {{ $item->concluido ? 'border-verde-500 bg-verde-500 text-white' : 'border-borda bg-white' }}">
                                    @if ($item->concluido)<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>@endif
                                </button>
                                <span class="flex-1 text-sm {{ $item->concluido ? 'text-texto-fraco line-through' : 'text-texto-forte' }}">{{ $item->descricao }}</span>
                                <button wire:click="removerItem({{ $item->id }})" class="text-texto-fraco hover:text-perigo-500"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                            </div>
                        @empty
                            <p class="py-2 text-sm text-texto-medio">Sem itens. Adicione um abaixo.</p>
                        @endforelse

                        <form wire:submit="adicionarItem" class="mt-3 flex gap-3">
                            <input wire:model="novoItem" type="text" class="campo-input" placeholder="Adicionar item ao checklist...">
                            <button type="submit" class="botao-secundario shrink-0">Adicionar</button>
                        </form>
                    </div>
                </section>
            </div>

            {{-- ===== FOTOGRAFIAS ===== --}}
            <div x-show="tab==='fotografias'" x-cloak class="mt-7">
                <section class="cartao">
                    <div class="flex items-center justify-between px-6 py-5">
                        <h2 class="text-lg font-semibold text-texto-forte">Registo Fotográfico</h2>
                        <span class="text-sm text-texto-fraco">{{ $intervencao->anexos->count() }} {{ \Illuminate\Support\Str::plural('ficheiro', $intervencao->anexos->count()) }}</span>
                    </div>
                    <div class="grid grid-cols-4 gap-4 border-t border-borda px-6 py-6">
                        <label class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-borda text-texto-fraco transition hover:border-verde-400 hover:text-verde-500">
                            <input type="file" wire:model="fotos" multiple accept="image/*" class="hidden">
                            <svg wire:loading.remove wire:target="fotos" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                            <svg wire:loading wire:target="fotos" class="h-7 w-7 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12a8 8 0 018-8"/></svg>
                            <span class="text-xs font-medium" wire:loading.remove wire:target="fotos">Carregar foto</span>
                            <span class="text-xs font-medium" wire:loading wire:target="fotos">A enviar…</span>
                        </label>

                        @foreach ($intervencao->anexos as $anexo)
                            <div class="group relative aspect-square overflow-hidden rounded-xl bg-zinc-800" wire:key="anexo-{{ $anexo->id }}">
                                <img src="{{ route('anexos.ver', $anexo) }}" alt="{{ $anexo->nome_ficheiro }}" class="h-full w-full object-cover">
                                <button wire:click="removerFoto({{ $anexo->id }})" class="absolute right-2 top-2 flex h-7 w-7 items-center justify-center rounded-lg bg-black/50 text-white opacity-0 transition group-hover:opacity-100 hover:bg-perigo-500">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                    @error('fotos.*') <p class="px-6 pb-4 text-xs text-perigo-500">{{ $message }}</p> @enderror
                </section>
            </div>

            {{-- Rodapé com indicador de auto-save --}}
            <footer class="mt-10 flex items-center justify-center gap-2 text-xs text-texto-fraco">
                <svg class="h-4 w-4 text-verde-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span wire:loading.remove>{{ $guardado ? 'Alterações guardadas' : 'Auto-Save ativo' }}</span>
                <span wire:loading class="text-aviso-500">A guardar…</span>
            </footer>

        </div>
    </main>
</div>
