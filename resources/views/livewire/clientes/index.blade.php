<div>
    <x-topbar :breadcrumb="['Início', 'Clientes']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Clientes</h1>
            <p class="mt-2 text-sm text-texto-medio">
                {{ $clientes->total() }} {{ \Illuminate\Support\Str::plural('cliente', $clientes->total()) }} ·
                sincronizado{{ $clientes->total() === 1 ? '' : 's' }} do ERP (só consulta).
            </p>

            {{-- Pesquisa --}}
            <div class="mt-8 w-full max-w-sm">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por nome, NIF ou email...">
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
                            <tr wire:key="cli-{{ $c->id }}" wire:click="selecionar({{ $c->id }})" class="cursor-pointer border-b border-borda transition last:border-0 hover:bg-fundo">
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
                                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-texto-fraco transition hover:bg-white hover:text-verde-600">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </span>
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

    {{-- Ficha do cliente (só leitura) --}}
    @if ($cliente)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fechar">
            <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl border border-borda bg-white shadow-xl">
                <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-texto-forte">{{ $cliente->nome }}</h2>
                        <p class="mt-1 text-sm text-texto-medio">Nº de cliente ERP: {{ $cliente->id_erp ?? '—' }}</p>
                    </div>
                    <button wire:click="fechar" class="text-texto-fraco hover:text-texto-forte">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="space-y-6 px-6 py-6">
                    {{-- Dados gerais --}}
                    <section>
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-texto-fraco">Dados gerais</h3>
                        <dl class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
                            <div><dt class="text-xs text-texto-fraco">Nº de cliente (ERP)</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->id_erp ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-texto-fraco">Nome</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->nome }}</dd></div>
                            <div><dt class="text-xs text-texto-fraco">NIF</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->nif ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-texto-fraco">Código postal</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->codpost ?? '—' }}</dd></div>
                            <div class="sm:col-span-2"><dt class="text-xs text-texto-fraco">Morada</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->morada ?? '—' }}</dd></div>
                        </dl>
                    </section>

                    {{-- Contactos --}}
                    <section class="border-t border-borda pt-5">
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-texto-fraco">Contactos</h3>
                        <dl class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
                            <div class="sm:col-span-2"><dt class="text-xs text-texto-fraco">Email</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->email ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-texto-fraco">Telefone</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->telefone ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-texto-fraco">Telemóvel</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->tlmvl ?? '—' }}</dd></div>
                        </dl>
                    </section>

                    {{-- Comercial --}}
                    <section class="border-t border-borda pt-5">
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-texto-fraco">Comercial</h3>
                        <dl class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
                            <div><dt class="text-xs text-texto-fraco">Vendedor (código)</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->vendedor ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-texto-fraco">Vendedor (nome)</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->vendnm ?? '—' }}</dd></div>
                        </dl>
                    </section>
                </div>

                <div class="flex justify-end border-t border-borda px-6 py-4">
                    <button wire:click="fechar" class="botao-secundario">Fechar</button>
                </div>
            </div>
        </div>
    @endif
</div>
