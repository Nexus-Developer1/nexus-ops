<div>
    <x-topbar :breadcrumb="['Início', 'Ativos']">
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

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Ativos</h1>
            <p class="mt-2 text-sm text-texto-medio">{{ $equipamentos->total() }} {{ \Illuminate\Support\Str::plural('equipamento', $equipamentos->total()) }} registado{{ $equipamentos->total() === 1 ? '' : 's' }}.</p>

            {{-- Pesquisa + filtros --}}
            <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full max-w-sm">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por nº de série, modelo ou cliente...">
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="filtrarTipo('')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $tipo === '' ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">Todos</button>
                    @foreach ($tipos as $t)
                        <button wire:click="filtrarTipo('{{ $t->value }}')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $tipo === $t->value ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">{{ $t->rotulo() }}</button>
                    @endforeach

                    {{-- Filtro por família (nome, vindo do PHC) — só aparece quando há famílias sincronizadas. --}}
                    @if ($familias->isNotEmpty())
                        <select wire:model.live="familia" class="campo-select w-auto min-w-[11rem]">
                            <option value="">Todas as famílias</option>
                            @foreach ($familias as $f)
                                <option value="{{ $f }}">{{ $f }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
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
                                    <div class="font-medium text-texto-forte">{{ $e->numero_serie ?? '—' }}</div>
                                    <div class="text-xs text-texto-fraco">{{ $e->fabricante }} {{ $e->modelo }}</div>
                                </td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $e->tipo->classesEtiqueta() }}">{{ $e->tipo->rotulo() }}</span></td>
                                <td class="px-6 py-4">
                                    <div class="text-texto-forte">{{ $e->local->cliente->nome }}</div>
                                    <div class="text-xs text-texto-fraco">{{ $e->local->designacao }}</div>
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
