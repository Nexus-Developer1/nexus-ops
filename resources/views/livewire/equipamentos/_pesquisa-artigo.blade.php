{{-- Pesquisa no catálogo do PHC para adicionar componentes (recebe $artigosFiltrados).
     Escolher um artigo acrescenta a linha "REF — designação" (qtd 1, editável depois). --}}
<div class="relative max-w-md">
    <input wire:model.live.debounce.300ms="artigoBusca" type="text" class="campo-input"
        placeholder="Adicionar do PHC — pesquisar por referência ou designação..." autocomplete="off">
    @if (trim($artigoBusca) !== '')
        <ul class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-borda bg-white py-1 shadow-lg">
            @forelse ($artigosFiltrados as $artigo)
                <li>
                    <button type="button" wire:key="artigo-{{ $artigo->id }}"
                        wire:click="adicionarComponenteArtigo({{ $artigo->id }})"
                        class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm transition hover:bg-verde-50">
                        <span class="min-w-0">
                            <span class="font-medium text-texto-forte">{{ $artigo->id_erp }}</span>
                            <span class="block truncate text-xs text-texto-medio">{{ $artigo->designacao ?? '—' }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-texto-fraco">{{ $artigo->faminome ?? '' }}</span>
                    </button>
                </li>
            @empty
                <li class="px-4 py-2 text-sm text-texto-medio">Nenhum artigo encontrado no catálogo sincronizado do PHC.</li>
            @endforelse
        </ul>
    @endif
</div>
