<div>
    <x-topbar :breadcrumb="['Início', 'Relatórios']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <x-toast-sucesso />

            @if (session('erro'))
                <div class="mb-6 flex items-center gap-2 rounded-lg border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm font-medium text-perigo-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ session('erro') }}
                </div>
            @endif

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Relatórios</h1>
            <p class="mt-2 text-sm text-texto-medio">Folhas de obra das intervenções, prontas a enviar ao cliente.</p>

            {{-- Pesquisa + filtros --}}
            <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full max-w-sm">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por nº, cliente ou técnico...">
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="filtrarEstado('')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $estado === '' ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">Todos</button>
                    @foreach ($estados as $e)
                        <button wire:click="filtrarEstado('{{ $e->value }}')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $estado === $e->value ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">{{ $e->rotulo() }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Filtro por tipo (combina com o de estado) + ordenação (padrão da lista de clientes). --}}
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="mr-1 text-xs font-semibold uppercase tracking-wide text-texto-fraco">Tipo</span>
                <button wire:click="filtrarTipo('')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $tipo === '' ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">Todos</button>
                <button wire:click="filtrarTipo('contrato')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $tipo === 'contrato' ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">De contrato</button>
                <button wire:click="filtrarTipo('individual')" class="rounded-lg px-3.5 py-2 text-sm font-medium {{ $tipo === 'individual' ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">Individual</button>
                <div class="ml-auto flex items-center gap-2">
                    <label for="ordenar" class="shrink-0 text-sm text-texto-medio">Ordenar:</label>
                    <select id="ordenar" wire:model.live="ordenar" class="campo-select w-full sm:w-56">
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
                            <th class="px-6 py-3.5 font-semibold">Nº</th>
                            <th class="px-6 py-3.5 font-semibold">Cliente / Equipamento</th>
                            <th class="px-6 py-3.5 font-semibold">Tipo</th>
                            <th class="px-6 py-3.5 font-semibold">Técnico</th>
                            <th class="px-6 py-3.5 font-semibold">Data</th>
                            <th class="px-6 py-3.5 font-semibold">Estado</th>
                            <th class="px-6 py-3.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($relatorios as $r)
                            <tr class="border-b border-borda transition last:border-0 hover:bg-fundo" wire:key="rel-{{ $r->id }}">
                                <td class="px-6 py-4 font-medium text-texto-forte">{{ $r->numero ?? '—' }}</td>
                                <td class="px-6 py-4">
                                    <div class="text-texto-forte">{{ $r->intervencao->equipamento->local?->cliente?->nome ?? '—' }}</div>
                                    <div class="text-xs text-texto-fraco">{{ $r->intervencao->equipamento->numero_serie }}</div>
                                </td>
                                <td class="px-6 py-4 text-texto-medio">{{ $r->intervencao->tipo->rotulo() }}</td>
                                <td class="px-6 py-4 text-texto-medio">{{ $r->intervencao->tecnico?->nome ?? '—' }}</td>
                                <td class="px-6 py-4 text-texto-medio">{{ $r->data->translatedFormat('d M Y') }}</td>
                                <td class="px-6 py-4"><span class="etiqueta {{ $r->estado->classesEtiqueta() }}">{{ $r->estado->rotulo() }}</span></td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($r->estado === \App\Enums\EstadoRelatorio::Rascunho)
                                            <a href="{{ route('relatorios.editar', $r) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-verde-600 transition hover:bg-verde-50">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                Retomar
                                            </a>
                                        @else
                                            {{-- Finalizado E Enviado editam-se (num enviado, o editor avisa que é preciso reenviar). --}}
                                            <a href="{{ route('relatorios.editar', $r) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-texto-medio transition hover:bg-fundo">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                Editar
                                            </a>
                                            <a href="{{ route('relatorios.pdf', $r) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-verde-600 transition hover:bg-verde-50">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                PDF
                                            </a>
                                            <a href="{{ route('relatorios.enviar', $r) }}" wire:navigate
                                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-texto-medio transition hover:bg-fundo">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                                {{ $r->estado === \App\Enums\EstadoRelatorio::Enviado ? 'Reenviar' : 'Enviar' }}
                                            </a>
                                        @endif

                                        {{-- Enviado = documento entregue ao cliente → não se elimina (botão escondido; guarda no método). --}}
                                        @if ($r->estado !== \App\Enums\EstadoRelatorio::Enviado)
                                            <button wire:click="eliminar({{ $r->id }})" wire:loading.attr="disabled" wire:target="eliminar({{ $r->id }})"
                                                wire:confirm="{{ $r->estado === \App\Enums\EstadoRelatorio::Rascunho ? 'Eliminar este rascunho? Fica recuperável.' : 'Eliminar o relatório ' . $r->numero . '? É um documento oficial (com número e PDF). Fica recuperável.' }}"
                                                class="inline-flex items-center justify-center rounded-lg p-1.5 text-texto-fraco transition hover:bg-perigo-100 hover:text-perigo-600 disabled:opacity-50" title="Eliminar">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-sm text-texto-medio">Nenhum relatório encontrado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="mt-4">{{ $relatorios->links() }}</div>

        </div>
    </main>
</div>
