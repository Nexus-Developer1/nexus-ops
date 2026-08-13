<div>
    <x-topbar :breadcrumb="['Início', 'Clientes', $cliente->nome, 'Faturação']">
        <a href="{{ route('clientes.detalhe', $cliente) }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar ao cliente
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Faturação</h1>
            <p class="mt-2 text-sm text-texto-medio">{{ $cliente->nome }} · {{ $linhas->total() }} {{ \Illuminate\Support\Str::plural('linha', $linhas->total()) }}</p>

            <div class="mt-8 w-full max-w-sm">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por descrição, referência ou nº de série...">
                </div>
            </div>

            <div class="cartao mt-6 overflow-hidden" wire:loading.class="opacity-60">
                <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Data</th>
                            <th class="px-6 py-3.5 font-semibold">Documento</th>
                            <th class="px-6 py-3.5 font-semibold">Descrição</th>
                            <th class="px-6 py-3.5 font-semibold">Série(s)</th>
                            <th class="px-6 py-3.5 font-semibold text-right">Qtd</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($linhas as $l)
                            @php $nSeries = filled($l->series) ? substr_count($l->series, ',') + 1 : 0; @endphp
                            <tr class="cursor-pointer border-b border-borda transition last:border-0 hover:bg-fundo" wire:key="fat-{{ $l->id }}"
                                x-on:click="Livewire.navigate(@js(route('clientes.fatura', [$cliente, $l])))">
                                <td class="px-6 py-4 text-texto-medio whitespace-nowrap">{{ $l->data?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-6 py-4 text-texto-forte whitespace-nowrap">{{ trim($l->nmdoc . ' ' . $l->fno) ?: '—' }}</td>
                                <td class="px-6 py-4 text-texto-medio">{{ $l->design ?: ($l->ref ?? '—') }}</td>
                                <td class="px-6 py-4">
                                    @if (filled($l->series))
                                        <span title="{{ $l->series }}" class="cursor-help text-texto-medio">{{ \Illuminate\Support\Str::limit($l->series, 40) }}</span>
                                        @if ($nSeries > 1)
                                            <span class="ml-1 whitespace-nowrap text-xs text-texto-fraco">({{ $nSeries }} séries)</span>
                                        @endif
                                    @else
                                        <span class="text-texto-fraco">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-texto-medio whitespace-nowrap">{{ $l->qtt !== null ? rtrim(rtrim((string) $l->qtt, '0'), '.') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-texto-medio">Este cliente não tem linhas de faturação.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="mt-4">{{ $linhas->links() }}</div>

        </div>
    </main>
</div>
