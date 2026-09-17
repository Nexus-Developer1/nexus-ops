{{-- Barra de páginas das listagens (set. 2026).
     Substitui a que vem com o Livewire, que nesta aplicação saía sem forma nenhuma: o
     Tailwind só gera as classes que encontra em resources/ e app/, e as dela vivem em
     vendor/ — nem botões, nem caixas, nem cor. Aqui usam-se os tokens da casa, que estão
     em resources/ e por isso são sempre gerados.

     Além dos números tem uma caixa para SALTAR para uma página: nos dossiers são mais de
     8000 páginas e ninguém lá chega de «seguinte» em «seguinte». --}}
@php
    $classeNumero = 'inline-flex h-9 min-w-9 items-center justify-center rounded-lg border px-3 text-sm font-medium transition';
    $classeInativo = $classeNumero.' border-borda bg-white text-texto-medio hover:border-verde-300 hover:text-verde-700';
    $classeAtivo = $classeNumero.' border-verde-600 bg-verde-600 text-white';
    $classeMorto = $classeNumero.' cursor-default border-borda bg-white text-texto-fraco opacity-60';
@endphp

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Navegação por páginas"
         class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

        <p class="text-sm text-texto-medio">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de
            <span class="font-semibold text-texto-forte">{{ number_format($paginator->total(), 0, ',', ' ') }}</span>
            <span class="text-texto-fraco">(página {{ $paginator->currentPage() }} de {{ number_format($paginator->lastPage(), 0, ',', ' ') }})</span>
        </p>

        <div class="flex flex-wrap items-center gap-1.5">
            @if ($paginator->onFirstPage())
                <span class="{{ $classeMorto }}" aria-disabled="true">Anterior</span>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="{{ $classeInativo }}" rel="prev">Anterior</button>
            @endif

            {{-- Números só em ecrã largo: no telemóvel ficam «Anterior/Seguinte» e o salto. --}}
            <span class="hidden items-center gap-1.5 sm:inline-flex">
                @foreach ($elements as $elemento)
                    @if (is_string($elemento))
                        <span class="px-1 text-sm text-texto-fraco">{{ $elemento }}</span>
                    @endif

                    @if (is_array($elemento))
                        @foreach ($elemento as $pagina => $url)
                            @if ($pagina == $paginator->currentPage())
                                <span class="{{ $classeAtivo }}" aria-current="page">{{ $pagina }}</span>
                            @else
                                <button type="button" wire:click="gotoPage({{ $pagina }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="{{ $classeInativo }}">{{ $pagina }}</button>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </span>

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="{{ $classeInativo }}" rel="next">Seguinte</button>
            @else
                <span class="{{ $classeMorto }}" aria-disabled="true">Seguinte</span>
            @endif

            {{-- Saltar para uma página. Só faz sentido quando há muitas. --}}
            @if ($paginator->lastPage() > 5)
                <div class="ml-1 flex items-center gap-1.5"
                     x-data="{
                         ir() {
                             const total = {{ $paginator->lastPage() }};
                             const n = Math.min(total, Math.max(1, parseInt(this.$refs.salto.value, 10) || 1));
                             this.$refs.salto.value = '';
                             $wire.call('gotoPage', n, '{{ $paginator->getPageName() }}');
                         },
                     }">
                    <label class="sr-only" for="salto-{{ $paginator->getPageName() }}">Ir para a página</label>
                    <input id="salto-{{ $paginator->getPageName() }}" x-ref="salto" type="number" min="1" max="{{ $paginator->lastPage() }}"
                           inputmode="numeric" placeholder="Pág."
                           @keydown.enter.prevent="ir()"
                           class="h-9 w-20 rounded-lg border border-borda px-2 text-sm text-texto-forte placeholder:text-texto-fraco focus:border-verde-400 focus:outline-none focus:ring-2 focus:ring-verde-100">
                    <button type="button" @click="ir()" wire:loading.attr="disabled" class="{{ $classeInativo }}">Ir</button>
                </div>
            @endif
        </div>
    </nav>
@endif
