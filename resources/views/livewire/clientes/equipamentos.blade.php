<div>
    <x-topbar :breadcrumb="['Início', 'Clientes', $cliente->nome, 'Equipamentos']">
        <a href="{{ route('clientes.detalhe', $cliente) }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar ao cliente
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Equipamentos</h1>
            <p class="mt-2 text-sm text-texto-medio">{{ $cliente->nome }} · {{ $equipamentos->total() }} {{ \Illuminate\Support\Str::plural('equipamento', $equipamentos->total()) }}</p>

            {{-- Pesquisa + chips por família PHC na mesma linha (só as famílias que o cliente
                 tem); clicar num chip filtra, reclicar limpa. --}}
            <div class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-3">
                <div class="relative w-full max-w-sm">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por nº de série, modelo ou fabricante...">
                </div>
                @if ($familias->count() > 1)
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="filtrarFamilia('')"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition {{ $familia === '' ? 'border-verde-600 bg-verde-50 text-verde-700' : 'border-borda text-texto-medio hover:bg-fundo' }}">
                            Todas
                        </button>
                        @foreach ($familias as $f)
                            <button type="button" wire:click="filtrarFamilia('{{ $f->familia }}')" wire:key="fam-{{ $f->familia }}"
                                class="rounded-full border px-3 py-1 text-xs font-medium transition {{ $familia === $f->familia ? 'border-verde-600 bg-verde-50 text-verde-700' : 'border-borda text-texto-medio hover:bg-fundo' }}">
                                {{ $f->nome ?: $f->familia }} <span class="text-texto-fraco">({{ $f->n }})</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="cartao mt-6 overflow-hidden" wire:loading.class="opacity-60">
                <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Equipamento</th>
                            <th class="px-6 py-3.5 font-semibold">Nº de série</th>
                            <th class="px-6 py-3.5 font-semibold">Local</th>
                            <th class="px-6 py-3.5 font-semibold">Estado</th>
                            <th class="px-6 py-3.5"><span class="sr-only">Abrir</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($equipamentos as $eq)
                            <tr class="border-b border-borda transition last:border-0 hover:bg-fundo" wire:key="eq-{{ $eq->id }}">
                                <td class="px-6 py-4">
                                    <a href="{{ route('equipamentos.ficha', $eq) }}" wire:navigate class="font-medium text-texto-forte transition hover:text-verde-600">{{ trim($eq->fabricante . ' ' . $eq->modelo) ?: '—' }}</a>
                                    <div class="text-xs text-texto-fraco">{{ $eq->tipo->rotulo() }}{{ $eq->faminome ? ' · '.$eq->faminome : '' }}</div>
                                </td>
                                <td class="px-6 py-4 text-texto-medio">{{ $eq->numero_serie ?? '—' }}</td>
                                <td class="px-6 py-4 text-texto-medio">{{ $eq->local?->designacao ?? '—' }}</td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $eq->estado->classesEtiqueta() }}">{{ $eq->estado->rotulo() }}</span></td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('equipamentos.ficha', $eq) }}" wire:navigate class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-texto-fraco transition hover:bg-fundo hover:text-verde-600" aria-label="Abrir a ficha de {{ $eq->numero_serie ?? 'equipamento' }}">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-texto-medio">Sem equipamentos associados.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="mt-4">{{ $equipamentos->links() }}</div>

        </div>
    </main>
</div>
