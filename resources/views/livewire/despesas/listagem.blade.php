<div>
    <x-topbar :breadcrumb="['Início', 'Despesas']">
        <a href="{{ route('despesas.nova') }}" wire:navigate class="botao-primario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
            Nova despesa
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            @if (session('sucesso'))
                <div class="mb-6 flex items-center gap-2 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm font-medium text-verde-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    {{ session('sucesso') }}
                </div>
            @endif

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Despesas</h1>
            <p class="mt-2 text-sm text-texto-medio">Custos da operação · {{ $periodo === 'mes' ? 'mês atual' : 'todo o período' }}.</p>

            {{-- KPIs --}}
            <div class="mt-8 grid grid-cols-2 gap-5 lg:grid-cols-4">
                <div class="cartao p-6">
                    <div class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Total</div>
                    <div class="mt-2 text-2xl font-semibold text-texto-forte">{{ number_format($kpis['total'], 2, ',', '.') }} €</div>
                </div>
                <div class="cartao p-6">
                    <div class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Faturável à parte</div>
                    <div class="mt-2 text-2xl font-semibold text-info-600">{{ number_format($kpis['faturavel'], 2, ',', '.') }} €</div>
                </div>
                <div class="cartao p-6">
                    <div class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Incluído no contrato</div>
                    <div class="mt-2 text-2xl font-semibold text-texto-forte">{{ number_format($kpis['incluido'], 2, ',', '.') }} €</div>
                </div>
                <div class="cartao p-6">
                    <div class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Nº de despesas</div>
                    <div class="mt-2 text-2xl font-semibold text-texto-forte">{{ $kpis['numero'] }}</div>
                </div>
            </div>

            {{-- Filtros --}}
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <select wire:model.live="periodo" class="campo-select w-40">
                    <option value="mes">Este mês</option>
                    <option value="tudo">Todo o período</option>
                </select>
                <select wire:model.live="categoria" class="campo-select w-44">
                    <option value="">Todas as categorias</option>
                    @foreach ($categorias as $c)
                        <option value="{{ $c->nome }}">{{ $c->nome }}</option>
                    @endforeach
                </select>
                <select wire:model.live="faturavel" class="campo-select w-44">
                    <option value="">Faturável: todas</option>
                    <option value="sim">Faturável à parte</option>
                    <option value="nao">Incluído no contrato</option>
                </select>
                <div class="relative min-w-56 flex-1">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 17a6 6 0 100-12 6 6 0 000 12z"/></svg>
                    <input wire:model.live.debounce.300ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por descrição ou cliente...">
                </div>
            </div>

            {{-- Tabela --}}
            <div class="cartao mt-5 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-borda text-left text-xs uppercase tracking-wide text-texto-fraco">
                            <th class="px-6 py-3 font-semibold">Data</th>
                            <th class="px-6 py-3 font-semibold">Categoria</th>
                            <th class="px-6 py-3 font-semibold">Descrição</th>
                            <th class="px-6 py-3 font-semibold">Cliente</th>
                            <th class="px-6 py-3 text-right font-semibold">Valor</th>
                            <th class="px-6 py-3 font-semibold">Faturável</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($despesas as $d)
                            <tr class="border-b border-borda last:border-0 hover:bg-fundo">
                                <td class="whitespace-nowrap px-6 py-3.5 text-texto-medio">{{ $d->data->translatedFormat('d M Y') }}</td>
                                <td class="px-6 py-3.5"><span class="etiqueta bg-slate-100 text-texto-medio">{{ $d->categoria }}</span></td>
                                <td class="px-6 py-3.5 font-medium text-texto-forte">{{ $d->descricao }}</td>
                                <td class="px-6 py-3.5 text-texto-medio">{{ $d->cliente?->nome ?? '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-medium text-texto-forte">{{ number_format($d->valor, 2, ',', '.') }} €</td>
                                <td class="px-6 py-3.5">
                                    @if ($d->faturavel)
                                        <span class="inline-flex items-center gap-1.5 text-info-600"><span class="h-2 w-2 rounded-full bg-info-600"></span> À parte</span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 text-texto-fraco"><span class="h-2 w-2 rounded-full bg-slate-300"></span> Contrato</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right">
                                    <a href="{{ route('despesas.editar', $d) }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Editar</a>
                                    <button wire:click="eliminar({{ $d->id }})" wire:confirm="Eliminar esta despesa? Fica recuperável." class="ml-3 text-texto-fraco hover:text-perigo-600" title="Eliminar">
                                        <svg class="inline h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <p class="text-sm text-texto-medio">Sem despesas no período/filtros selecionados.</p>
                                    <p class="mt-1 text-xs text-texto-fraco">Use "Nova despesa" para registar a primeira.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-5">{{ $despesas->links() }}</div>
        </div>
    </main>
</div>
