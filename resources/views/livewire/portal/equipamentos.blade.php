<div>
    <x-topbar :breadcrumb="['Portal', 'Equipamentos']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Os meus equipamentos</h1>
            <p class="mt-2 text-sm text-texto-medio">Equipamentos cobertos sob a sua gestão.</p>

            <div class="mt-8 relative w-full max-w-sm">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por nº de série ou modelo...">
            </div>

            <div class="cartao mt-6 overflow-hidden" wire:loading.class="opacity-60">
                <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Equipamento</th>
                            <th class="px-6 py-3.5 font-semibold">Tipo</th>
                            <th class="px-6 py-3.5 font-semibold">Local</th>
                            <th class="px-6 py-3.5 font-semibold">Estado</th>
                            <th class="px-6 py-3.5 font-semibold">Próx. baterias</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($equipamentos as $e)
                            <tr class="border-b border-borda last:border-0" wire:key="eq-{{ $e->id }}">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-texto-forte">{{ trim($e->fabricante . ' ' . $e->modelo) ?: '—' }}</div>
                                    <div class="text-xs text-texto-fraco">{{ $e->numero_serie ?? '—' }}</div>
                                </td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $e->tipo->classesEtiqueta() }}">{{ $e->tipo->rotulo() }}</span></td>
                                <td class="px-6 py-4 text-texto-medio">{{ $e->local->designacao }}</td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $e->estado->classesEtiqueta() }}">{{ $e->estado->rotulo() }}</span></td>
                                <td class="px-6 py-4 text-texto-medio">{{ $e->proxima_troca_baterias?->translatedFormat('d M Y') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-texto-medio">Nenhum equipamento encontrado.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="mt-4">{{ $equipamentos->links() }}</div>
        </div>
    </main>
</div>
