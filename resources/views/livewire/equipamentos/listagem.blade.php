<div>
    <x-topbar :breadcrumb="['Início', 'Equipamentos']">
        <a href="{{ route('equipamentos.associar') }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            Associar a local
        </a>
        <a href="{{ route('equipamentos.novo') }}" wire:navigate class="botao-primario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
            Novo equipamento
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Equipamentos</h1>
            <p class="mt-2 text-sm text-texto-medio">{{ $equipamentos->total() }} {{ \Illuminate\Support\Str::plural('equipamento', $equipamentos->total()) }} registado{{ $equipamentos->total() === 1 ? '' : 's' }}.</p>

            {{-- Filtros num CARTÃO só (pedido da equipa, set. 2026: antes andavam soltos em duas
                 linhas, com larguras diferentes e o "Ordenar:" a flutuar à direita). Pesquisa em
                 cima, e por baixo os controlos em colunas IGUAIS, cada um com o seu rótulo. --}}
            <div class="cartao mt-8 p-4 sm:p-5">
                {{-- Pesquisa: procura sempre em TODOS os clientes. --}}
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por nº de série, modelo ou cliente...">
                </div>

                {{-- flex-1 com largura mínima: todos ficam da MESMA largura e passam à linha
                     seguinte inteiros (o filtro de família só existe com famílias do PHC). --}}
                <div class="mt-4 flex flex-wrap gap-3">
                    <div class="min-w-[11rem] flex-1">
                        <label for="tipo" class="campo-label">Tipo</label>
                        <select id="tipo" wire:model.live="tipo" class="campo-select">
                            <option value="">Todos</option>
                            @foreach ($tipos as $t)
                                <option value="{{ $t->value }}">{{ $t->rotulo() }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($familias->isNotEmpty())
                        <div class="min-w-[11rem] flex-1">
                            <label for="familia" class="campo-label">Família</label>
                            <select id="familia" wire:model.live="familia" class="campo-select">
                                <option value="">Todas</option>
                                @foreach ($familias as $f)
                                    <option value="{{ $f }}">{{ $f }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="min-w-[11rem] flex-1">
                        <label for="banco" class="campo-label">Banco de baterias</label>
                        <select id="banco" wire:model.live="banco" class="campo-select">
                            <option value="">Todos</option>
                            <option value="com">Com banco associado</option>
                            <option value="sem">Sem banco associado</option>
                            <option value="banco">Só bancos associados a UPS</option>
                        </select>
                    </div>

                    <div class="min-w-[11rem] flex-1">
                        <label for="ordenar" class="campo-label">Ordenar</label>
                        <select id="ordenar" wire:model.live="ordenar" class="campo-select">
                            @foreach ($ordenacoes as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Só quando há alguma coisa filtrada: repõe a lista como ela abre. --}}
                @if ($this->temFiltros())
                    <div class="mt-3 flex justify-end border-t border-borda pt-3">
                        <button type="button" wire:click="limparFiltros" class="inline-flex items-center gap-1.5 text-sm font-medium text-texto-medio hover:text-texto-forte">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            Limpar filtros
                        </button>
                    </div>
                @endif
            </div>

            {{-- Tabela --}}
            <div class="cartao mt-6 overflow-hidden" wire:loading.class="opacity-60">
                <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Equipamento</th>
                            <th class="px-6 py-3.5 font-semibold">Tipo</th>
                            <th class="px-6 py-3.5 font-semibold">Cliente / Local</th>
                            <th class="px-6 py-3.5 font-semibold">Estado</th>
                            <th class="px-6 py-3.5 font-semibold">Próxima manutenção</th>
                            <th class="px-6 py-3.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($equipamentos as $e)
                            <tr class="border-b border-borda transition last:border-0 hover:bg-fundo">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-texto-forte">{{ $e->numero_serie ?? '—' }}</span>
                                        @if ($e->equipamentos_associados_count > 0)
                                            <span class="etiqueta bg-verde-50 text-verde-700" title="{{ $e->equipamentos_associados_count }} banco(s) de baterias associado(s)">Banco ×{{ $e->equipamentos_associados_count }}</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-texto-fraco">{{ $e->fabricante }} {{ $e->modelo }}</div>
                                </td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $e->tipo->classesEtiqueta() }}">{{ $e->tipo->rotulo() }}</span></td>
                                <td class="px-6 py-4">
                                    {{-- Sem local = veio do PHC sem cliente associado (fatura sem o nº) — fica "por associar". --}}
                                    @if ($e->local)
                                        <div class="text-texto-forte">{{ $e->local->cliente->nome }}</div>
                                        <div class="text-xs text-texto-fraco">{{ $e->local->designacao }}</div>
                                    @else
                                        <span class="etiqueta bg-aviso-100 text-aviso-500">Sem cliente — por associar</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $e->estado->classesEtiqueta() }}">{{ $e->estado->rotulo() }}</span></td>
                                <td class="px-6 py-4 text-texto-medio">{{ $e->proxima_troca_baterias?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('equipamentos.ficha', $e) }}" wire:navigate class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-texto-fraco transition hover:bg-white hover:text-verde-600">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-sm text-texto-medio">Nenhum equipamento encontrado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="mt-4">
                {{ $equipamentos->links() }}
            </div>

        </div>
    </main>
</div>
