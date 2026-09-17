{{-- Barra de páginas das listagens (set. 2026).

     Substitui a que vem com o Livewire, que nesta aplicação saía sem forma nenhuma: o
     Tailwind só gera as classes que encontra em resources/ e app/, e as dela vivem em
     vendor/ — nem botões, nem caixas, nem cor. Aqui usam-se os tokens da casa, que estão
     em resources/ e por isso são sempre gerados.

     Duas feições, conforme o tamanho da listagem:
       · até 7 páginas — os números, que é o mais cómodo quando são poucas;
       · daí para cima — primeira/anterior, a página actual numa caixa que se pode escrever,
         e seguinte/última. Alinhar 718 números não servia a ninguém. --}}
@php
    $ultima = $paginator->lastPage();
    $atual = $paginator->currentPage();
    $poucas = $ultima <= 7;
    $nome = $paginator->getPageName();

    $grupo = 'inline-flex items-center overflow-hidden rounded-lg border border-borda bg-white';
    $tecla = 'inline-flex h-8 min-w-8 items-center justify-center px-2.5 text-[13px] font-medium text-texto-medio transition hover:bg-verde-50 hover:text-verde-700';
    $teclaMorta = 'inline-flex h-8 min-w-8 cursor-default items-center justify-center px-2.5 text-[13px] font-medium text-texto-fraco opacity-45';
    $teclaAtiva = 'inline-flex h-8 min-w-8 items-center justify-center px-2.5 text-[13px] font-semibold text-white bg-verde-600';
@endphp

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Navegação por páginas"
         class="flex flex-col items-center gap-3 sm:flex-row sm:justify-between">

        <p class="text-[13px] text-texto-fraco">
            <span class="font-semibold text-texto-medio">{{ number_format($paginator->firstItem(), 0, ',', ' ') }}–{{ number_format($paginator->lastItem(), 0, ',', ' ') }}</span>
            de {{ number_format($paginator->total(), 0, ',', ' ') }}
        </p>

        <div class="flex items-center gap-2">
            @if ($poucas)
                <div class="{{ $grupo }} divide-x divide-borda">
                    @if ($paginator->onFirstPage())
                        <span class="{{ $teclaMorta }}" aria-hidden="true">@include('components.paginacao-seta', ['sentido' => 'anterior'])</span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $nome }}')" wire:loading.attr="disabled" class="{{ $tecla }}">
                            @include('components.paginacao-seta', ['sentido' => 'anterior'])
                            <span class="sr-only">Anterior</span>
                        </button>
                    @endif

                    @foreach (range(1, $ultima) as $pagina)
                        @if ($pagina === $atual)
                            <span class="{{ $teclaAtiva }}" aria-current="page">{{ $pagina }}</span>
                        @else
                            <button type="button" wire:click="gotoPage({{ $pagina }}, '{{ $nome }}')" wire:loading.attr="disabled" class="{{ $tecla }}">{{ $pagina }}</button>
                        @endif
                    @endforeach

                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $nome }}')" wire:loading.attr="disabled" class="{{ $tecla }}">
                            @include('components.paginacao-seta', ['sentido' => 'seguinte'])
                            <span class="sr-only">Seguinte</span>
                        </button>
                    @else
                        <span class="{{ $teclaMorta }}" aria-hidden="true">@include('components.paginacao-seta', ['sentido' => 'seguinte'])</span>
                    @endif
                </div>
            @else
                {{-- Muitas páginas: escrever o número é mais rápido do que procurá-lo. --}}
                <div x-data="{
                        ir(valor) {
                            const n = Math.min({{ $ultima }}, Math.max(1, parseInt(valor, 10) || {{ $atual }}));
                            if (n !== {{ $atual }}) { $wire.call('gotoPage', n, '{{ $nome }}'); }
                            else { this.$refs.pagina.value = {{ $atual }}; }
                        },
                     }"
                     class="flex items-center gap-2">

                    <div class="{{ $grupo }} divide-x divide-borda">
                        @if ($paginator->onFirstPage())
                            <span class="{{ $teclaMorta }}" aria-hidden="true">@include('components.paginacao-seta', ['sentido' => 'primeira'])</span>
                            <span class="{{ $teclaMorta }}" aria-hidden="true">@include('components.paginacao-seta', ['sentido' => 'anterior'])</span>
                        @else
                            <button type="button" wire:click="gotoPage(1, '{{ $nome }}')" wire:loading.attr="disabled" class="{{ $tecla }}">
                                @include('components.paginacao-seta', ['sentido' => 'primeira'])
                                <span class="sr-only">Primeira página</span>
                            </button>
                            <button type="button" wire:click="previousPage('{{ $nome }}')" wire:loading.attr="disabled" class="{{ $tecla }}">
                                @include('components.paginacao-seta', ['sentido' => 'anterior'])
                                <span class="sr-only">Anterior</span>
                            </button>
                        @endif
                    </div>

                    <div class="flex items-center gap-1.5 text-[13px] text-texto-fraco">
                        <label class="sr-only" for="pagina-{{ $nome }}">Página</label>
                        <input id="pagina-{{ $nome }}" x-ref="pagina" type="number" min="1" max="{{ $ultima }}"
                               value="{{ $atual }}" inputmode="numeric" aria-label="Página, de {{ $ultima }}"
                               @keydown.enter.prevent="ir($event.target.value)"
                               @blur="ir($event.target.value)"
                               class="h-8 w-14 rounded-lg border border-borda bg-white text-center text-[13px] font-semibold text-texto-forte focus:border-verde-400 focus:outline-none focus:ring-2 focus:ring-verde-100">
                        <span class="whitespace-nowrap">de {{ number_format($ultima, 0, ',', ' ') }}</span>
                    </div>

                    <div class="{{ $grupo }} divide-x divide-borda">
                        @if ($paginator->hasMorePages())
                            <button type="button" wire:click="nextPage('{{ $nome }}')" wire:loading.attr="disabled" class="{{ $tecla }}">
                                @include('components.paginacao-seta', ['sentido' => 'seguinte'])
                                <span class="sr-only">Seguinte</span>
                            </button>
                            <button type="button" wire:click="gotoPage({{ $ultima }}, '{{ $nome }}')" wire:loading.attr="disabled" class="{{ $tecla }}">
                                @include('components.paginacao-seta', ['sentido' => 'ultima'])
                                <span class="sr-only">Última página</span>
                            </button>
                        @else
                            <span class="{{ $teclaMorta }}" aria-hidden="true">@include('components.paginacao-seta', ['sentido' => 'seguinte'])</span>
                            <span class="{{ $teclaMorta }}" aria-hidden="true">@include('components.paginacao-seta', ['sentido' => 'ultima'])</span>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </nav>
@endif
