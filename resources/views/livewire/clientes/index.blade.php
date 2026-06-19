<div>
    <x-topbar :breadcrumb="['Início', 'Clientes']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Clientes</h1>
            <p class="mt-2 text-sm text-texto-medio">
                {{ $clientes->total() }} {{ \Illuminate\Support\Str::plural('cliente', $clientes->total()) }} ·
                sincronizado{{ $clientes->total() === 1 ? '' : 's' }} do ERP (só consulta).
            </p>

            {{-- Pesquisa + ordenação --}}
            <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full max-w-sm">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por nome, NIF ou email...">
                </div>
                <div class="flex items-center gap-2">
                    <label for="ordenar" class="shrink-0 text-sm text-texto-medio">Ordenar:</label>
                    <select id="ordenar" wire:model.live="ordenar" class="campo-select w-56">
                        @foreach ($ordenacoes as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Tabela --}}
            <div class="cartao mt-6 overflow-hidden" wire:loading.class="opacity-60">
                <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Cliente</th>
                            <th class="px-6 py-3.5 font-semibold">NIF</th>
                            <th class="px-6 py-3.5 font-semibold">Contacto</th>
                            <th class="px-6 py-3.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($clientes as $c)
                            <tr wire:key="cli-{{ $c->id }}" onclick="Livewire.navigate('{{ route('clientes.detalhe', $c) }}')" class="cursor-pointer border-b border-borda transition last:border-0 hover:bg-fundo">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-texto-forte">{{ $c->nome }}</div>
                                    <div class="text-xs text-texto-fraco">Nº ERP: {{ $c->id_erp ?? '—' }}</div>
                                </td>
                                <td class="px-6 py-4 text-texto-medio">{{ $c->nif ?? '—' }}</td>
                                <td class="px-6 py-4">
                                    <div class="text-texto-forte">{{ $c->email ?? '—' }}</div>
                                    <div class="text-xs text-texto-fraco">{{ $c->telefone ?? $c->tlmvl ?? '—' }}</div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('clientes.detalhe', $c) }}" wire:navigate @click.stop class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-texto-fraco transition hover:bg-white hover:text-verde-600" title="Ver cliente">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-sm text-texto-medio">Nenhum cliente encontrado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="mt-4">{{ $clientes->links() }}</div>

        </div>
    </main>
</div>
