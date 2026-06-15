<div>
    <x-topbar :breadcrumb="['Portal', 'Relatórios']" />

    <main class="flex-1 px-10 py-9">
        <div class="mx-auto max-w-5xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Os meus relatórios</h1>
            <p class="mt-2 text-sm text-texto-medio">Folhas de obra das intervenções aos seus equipamentos.</p>

            <div class="cartao mt-8 overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Nº</th>
                            <th class="px-6 py-3.5 font-semibold">Equipamento</th>
                            <th class="px-6 py-3.5 font-semibold">Data</th>
                            <th class="px-6 py-3.5 font-semibold">Estado</th>
                            <th class="px-6 py-3.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($relatorios as $r)
                            <tr class="border-b border-borda last:border-0" wire:key="rel-{{ $r->id }}">
                                <td class="px-6 py-4 font-medium text-texto-forte">{{ $r->numero }}</td>
                                <td class="px-6 py-4">
                                    <div class="text-texto-forte">{{ trim($r->intervencao->equipamento->fabricante . ' ' . $r->intervencao->equipamento->modelo) ?: '—' }}</div>
                                    <div class="text-xs text-texto-fraco">{{ $r->intervencao->equipamento->local->designacao }}</div>
                                </td>
                                <td class="px-6 py-4 text-texto-medio">{{ $r->data->translatedFormat('d M Y') }}</td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $r->estado->classesEtiqueta() }}">{{ $r->estado->rotulo() }}</span></td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('portal.relatorios.pdf', $r) }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-verde-600 transition hover:bg-verde-50">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        PDF
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-texto-medio">Ainda não há relatórios.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $relatorios->links() }}</div>
        </div>
    </main>
</div>
