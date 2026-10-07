{{-- Uma página na lista do caderno (também as subpáginas). Arrastar para cima de outra do
     mesmo nível muda a ordem. Variáveis: $p (página), $ativa (id aberto), $tinta (cor do
     separador), $sub (é subpágina), $filhas (subpáginas, só nas de cima). --}}
<li wire:key="pag-{{ $p->id }}" data-id="{{ $p->id }}" draggable="true"
    x-data="{ aberta: true }"
    :class="sobre === {{ $p->id }} && arrastado !== {{ $p->id }} && 'caderno-alvo'"
    x-on:dragstart.stop="arrastado = {{ $p->id }}; $event.dataTransfer.effectAllowed = 'move'"
    x-on:dragend.stop="arrastado = null; sobre = null"
    x-on:dragover.prevent.stop="if (arrastado) sobre = {{ $p->id }}"
    x-on:dragleave.stop="if (sobre === {{ $p->id }}) sobre = null"
    x-on:drop.prevent.stop="if (arrastado && arrastado !== {{ $p->id }}) { $wire.reordenarPaginas(window.reordenar(ids($el), arrastado, {{ $p->id }})) } arrastado = null; sobre = null">
    <div class="flex items-start">
        @if (! $sub && $filhas !== [])
            <button type="button" @click="aberta = !aberta" class="mt-2.5 shrink-0 rounded p-0.5 text-texto-fraco hover:bg-white/60" :aria-label="aberta ? 'Esconder subpáginas' : 'Mostrar subpáginas'">
                <svg class="h-3.5 w-3.5 transition" :class="aberta && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
        @else
            <span class="w-[1.125rem] shrink-0"></span>
        @endif
        <button type="button" @click="window.cadernoMudar(() => $wire.selecionarPagina({{ $p->id }}))"
            class="caderno-item {{ $p->id === $ativa ? 'caderno-item-ativo' : '' }} {{ $sub ? 'pl-2.5' : '' }}"
            @if ($p->id === $ativa) style="border-left-color: {{ $tinta }};" @endif>
            <span class="block truncate {{ $sub ? 'text-[13px]' : 'text-sm' }} font-medium text-texto-forte">{{ $p->titulo }}</span>
            @if ($p->resumo !== '')
                <span class="mt-0.5 block truncate text-xs text-texto-medio">{{ $p->resumo }}</span>
            @endif
            <span class="mt-1 flex items-center gap-2 text-[11px] text-texto-fraco">
                <span>{{ $p->updated_at->format('d/m/Y') }}</span>
                @if ($p->tem_imagens)
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-label="Tem imagens"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                @endif
                @if ($p->tem_ficheiros)
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-label="Tem ficheiros"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                @endif
                @if ($p->equipamento)
                    <span class="flex min-w-0 items-center gap-1" title="{{ $p->equipamento->rotuloCaderno() }}">
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>
                        <span class="truncate">{{ $p->equipamento->rotuloCaderno() }}</span>
                    </span>
                @endif
            </span>
        </button>
    </div>

    @if (! $sub && $filhas !== [])
        <ul x-show="aberta" class="mt-0.5 space-y-0.5 pl-4" data-lista
            x-data="{ arrastado: null, sobre: null, ids(el) { return [...el.closest('[data-lista]').querySelectorAll(':scope > [data-id]')].map((e) => Number(e.dataset.id)) } }">
            @foreach ($filhas as $filha)
                @include('livewire.clientes.caderno-pagina-item', ['p' => $filha, 'sub' => true, 'filhas' => []])
            @endforeach
        </ul>
    @endif
</li>
