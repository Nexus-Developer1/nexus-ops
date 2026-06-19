<div>
    <x-topbar :breadcrumb="['Manutenção', 'Contratos']">
        <a href="{{ route('contratos.novo') }}" wire:navigate class="botao-primario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
            Novo Contrato
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

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Contratos</h1>
            <p class="mt-2 text-sm text-texto-medio">Âmbito, periodicidade, SLA e faturação — alimentam a agenda de visitas preventivas.</p>

            {{-- Aviso de renovações --}}
            @if ($aExpirar > 0)
                <div class="mt-6 flex items-center gap-3 rounded-xl border border-aviso-200 bg-aviso-100/60 px-5 py-4">
                    <svg class="h-5 w-5 shrink-0 text-aviso-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <p class="text-sm text-texto-forte">
                        <span class="font-semibold">{{ $aExpirar }} {{ $aExpirar === 1 ? 'contrato' : 'contratos' }}</span>
                        {{ $aExpirar === 1 ? 'está' : 'estão' }} dentro da janela de aviso de renovação.
                        <button wire:click="filtrarEstado('a_expirar')" class="font-medium text-aviso-500 underline">Rever renovações</button>
                    </p>
                </div>
            @endif

            {{-- Pesquisa + filtros --}}
            <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full max-w-sm">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por nº ou cliente...">
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="filtrarEstado('')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $estado === '' ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">Todos</button>
                    <button wire:click="filtrarEstado('a_expirar')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $estado === 'a_expirar' ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">A expirar</button>
                    @foreach ($estados as $e)
                        <button wire:click="filtrarEstado('{{ $e->value }}')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $estado === $e->value ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">{{ $e->rotulo() }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Tabela --}}
            <div class="cartao mt-6 overflow-hidden" wire:loading.class="opacity-60">
                <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Nº</th>
                            <th class="px-6 py-3.5 font-semibold">Cliente</th>
                            <th class="px-6 py-3.5 font-semibold">Tipo</th>
                            <th class="px-6 py-3.5 font-semibold">Vigência</th>
                            <th class="px-6 py-3.5 font-semibold">Faturação</th>
                            <th class="px-6 py-3.5 font-semibold">Estado</th>
                            <th class="px-6 py-3.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($contratos as $c)
                            <tr class="border-b border-borda transition last:border-0 hover:bg-fundo" wire:key="contrato-{{ $c->id }}">
                                <td class="px-6 py-4 font-medium text-texto-forte">{{ $c->numero }}</td>
                                <td class="px-6 py-4">
                                    <div class="text-texto-forte">{{ $c->cliente->nome }}</div>
                                    <div class="text-xs text-texto-fraco">{{ $c->equipamentos_count }} {{ $c->equipamentos_count === 1 ? 'equipamento' : 'equipamentos' }}</div>
                                </td>
                                <td class="px-6 py-4 text-texto-medio">{{ $c->tipo->rotulo() }}</td>
                                <td class="px-6 py-4 text-texto-medio">
                                    {{ $c->data_inicio->translatedFormat('d M Y') }}
                                    <span class="text-texto-fraco">→</span>
                                    {{ $c->data_fim->translatedFormat('d M Y') }}
                                </td>
                                <td class="px-6 py-4 text-texto-medio">{{ $c->modeloFaturacao?->nome ?? '—' }}</td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $c->estado->classesEtiqueta() }}">{{ $c->estado->rotulo() }}</span></td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <button wire:click="eliminar({{ $c->id }})" wire:loading.attr="disabled" wire:target="eliminar({{ $c->id }})"
                                            wire:confirm="{{ $c->equipamentos_count > 0
                                                ? 'Eliminar o contrato ' . $c->numero . '? Tem ' . $c->equipamentos_count . ' ' . ($c->equipamentos_count === 1 ? 'equipamento associado' : 'equipamentos associados') . ' — eliminar o contrato não os remove. Fica recuperável.'
                                                : 'Eliminar o contrato ' . $c->numero . '? Fica recuperável.' }}"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-texto-fraco transition hover:bg-perigo-100 hover:text-perigo-600 disabled:opacity-50" title="Eliminar">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                        <a href="{{ route('contratos.ficha', $c) }}" wire:navigate class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-texto-fraco transition hover:bg-white hover:text-verde-600" title="Ver ficha">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-sm text-texto-medio">Nenhum contrato encontrado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="mt-4">{{ $contratos->links() }}</div>

        </div>
    </main>
</div>
