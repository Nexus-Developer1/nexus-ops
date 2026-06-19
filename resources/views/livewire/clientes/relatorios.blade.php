<div>
    <x-topbar :breadcrumb="['Início', 'Clientes', $cliente->nome, 'Relatórios']">
        <a href="{{ route('clientes.detalhe', $cliente) }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar ao cliente
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Relatórios</h1>
            <p class="mt-2 text-sm text-texto-medio">{{ $cliente->nome }} · {{ $relatorios->total() }} {{ \Illuminate\Support\Str::plural('relatório', $relatorios->total()) }}</p>

            <div class="mt-8 w-full max-w-sm">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por número...">
                </div>
            </div>

            <div class="cartao mt-6 overflow-hidden" wire:loading.class="opacity-60">
                <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Número</th>
                            <th class="px-6 py-3.5 font-semibold">Data</th>
                            <th class="px-6 py-3.5 font-semibold">Equipamento</th>
                            <th class="px-6 py-3.5 font-semibold">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($relatorios as $rl)
                            <tr class="border-b border-borda transition last:border-0 hover:bg-fundo" wire:key="rl-{{ $rl->id }}">
                                <td class="px-6 py-4 font-medium text-texto-forte">{{ $rl->numero }}</td>
                                <td class="px-6 py-4 text-texto-medio">{{ $rl->data?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-6 py-4 text-texto-medio">{{ $rl->intervencao?->equipamento?->numero_serie ?? '—' }}</td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $rl->estado->classesEtiqueta() }}">{{ $rl->estado->rotulo() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-12 text-center text-sm text-texto-medio">Sem relatórios.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="mt-4">{{ $relatorios->links() }}</div>

        </div>
    </main>
</div>
