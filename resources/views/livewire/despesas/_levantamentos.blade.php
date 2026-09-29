{{-- Levantamentos de dinheiro do cartão do técnico (set. 2026): dia, valor e o talão do
     multibanco (obrigatório). As despesas pagas com esse dinheiro levam «Dinheiro levantado» em
     «Pago por», e as contas por baixo dizem quanto sobra (a devolver). --}}
<div class="mt-6 rounded-lg border border-borda p-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h3 class="text-sm font-semibold text-texto-forte">Levantamentos do cartão</h3>
        <button type="button" wire:click="adicionarLevantamento" class="botao-secundario w-full shrink-0 justify-center sm:w-auto">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
            Levantamento
        </button>
    </div>

    @foreach ($levantamentos as $i => $lev)
        @php($gravados = $lev['levantamento_id'] ? ($taloesPorLevantamento[$lev['levantamento_id']] ?? collect()) : collect())
        <div wire:key="lev-{{ $i }}" class="mt-3 grid grid-cols-2 gap-3 rounded-lg border border-borda/60 bg-fundo/40 p-3 sm:grid-cols-[10rem_8rem_1fr_auto] sm:items-start">
            <div>
                <label class="campo-label">Dia <span class="text-perigo-500">*</span></label>
                <input wire:model="levantamentos.{{ $i }}.dia" type="date" class="campo-input px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="campo-label">Valor (€) <span class="text-perigo-500">*</span></label>
                <input wire:model.live.debounce.500ms="levantamentos.{{ $i }}.valor" type="number" step="0.01" min="0" inputmode="decimal" class="campo-input px-2 py-1.5 text-right text-sm" placeholder="0,00">
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="campo-label">Talão do multibanco <span class="text-perigo-500">*</span></label>
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" @click="abrirTalao({{ $i }})" class="rounded-md border border-borda bg-white p-2 text-texto-medio hover:text-verde-700" title="Digitalizar talão (câmara + filtro de documento)">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0a4 4 0 11-8 0 4 4 0 018 0zM4 16H2m2-5.5L2.5 9M20 10.5L21.5 9M7 4h10l1 3H6l1-3z"/></svg>
                    </button>
                    <label class="cursor-pointer rounded-md border border-borda bg-white p-2 text-texto-medio hover:text-verde-700" title="Tirar foto ao talão">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <input type="file" wire:model="talaoLevantamentoUpload.{{ $i }}" accept="image/*" capture="environment" class="hidden">
                    </label>
                    <label class="cursor-pointer rounded-md border border-borda bg-white p-2 text-texto-medio hover:text-verde-700" title="Escolher da galeria">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <input type="file" wire:model="talaoLevantamentoUpload.{{ $i }}" accept="image/*" multiple class="hidden">
                    </label>
                    <span wire:loading wire:target="talaoLevantamentoUpload.{{ $i }},talaoDigitalizado" class="text-xs text-texto-medio">a carregar…</span>

                    @foreach ($gravados as $talao)
                        <span class="group relative" wire:key="tg-{{ $talao->id }}">
                            <a href="{{ route('despesas.recibos.ver', $talao) }}" target="_blank">
                                <img src="{{ route('despesas.recibos.ver', $talao) }}" alt="Talão" class="h-12 w-12 rounded border border-borda object-cover">
                            </a>
                            <button type="button" wire:click="removerTalaoGravado({{ $talao->id }})" wire:confirm="Remover este talão?"
                                class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-perigo-600 text-white sm:hidden sm:group-hover:flex" title="Remover">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </span>
                    @endforeach
                    @foreach ($taloesPendentes[$i] ?? [] as $j => $pendente)
                        <span class="group relative" wire:key="tp-{{ $i }}-{{ $j }}">
                            @if ($pendente->isPreviewable())
                                <img src="{{ $pendente->temporaryUrl() }}" alt="Talão pendente" class="h-12 w-12 rounded border border-verde-300 object-cover">
                            @else
                                <span class="flex h-12 w-12 items-center justify-center rounded border border-perigo-300 bg-perigo-50 text-[10px] text-perigo-600" title="{{ $pendente->getClientOriginalName() }}">?</span>
                            @endif
                            <button type="button" wire:click="removerTalaoPendente({{ $i }}, {{ $j }})"
                                class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-perigo-600 text-white sm:hidden sm:group-hover:flex" title="Remover">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </span>
                    @endforeach
                </div>
            </div>
            <div class="col-span-2 text-right sm:col-span-1 sm:pt-6">
                <button type="button" wire:click="removerLevantamento({{ $i }})" class="text-xs font-medium text-texto-fraco hover:text-perigo-600" title="Remover levantamento">Remover</button>
            </div>
        </div>
        @foreach (['dia', 'valor', 'talao'] as $campo)
            @error("levantamentos.$i.$campo") <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
        @endforeach
        @error("talaoLevantamentoUpload.$i.*") <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @endforeach

    @include('livewire.despesas._contas-dinheiro', ['contas' => $contasDinheiro])
</div>
