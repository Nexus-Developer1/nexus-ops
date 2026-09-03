<div>
    <x-topbar :breadcrumb="['Início', 'Dossiers PHC']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Dossiers PHC</h1>

            {{-- Filtros --}}
            <div class="mt-8 grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:items-center">
                <select wire:model.live="tipo" class="campo-select w-full sm:w-48">
                    <option value="">Todos os tipos</option>
                    @foreach ($tipos as $n => $rotulo)
                        <option value="{{ $n }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
                <select wire:model.live="estado" class="campo-select w-full sm:w-36">
                    <option value="">Todos</option>
                    <option value="aberta">Em aberto</option>
                    <option value="fechada">Fechadas</option>
                </select>
                {{-- Resultado da conferência com o PHC feita a cada sync. --}}
                <select wire:model.live="phc" class="campo-select w-full sm:w-52">
                    <option value="">Tudo o que veio do PHC</option>
                    <option value="ausente">Já não existe no PHC</option>
                    <option value="alterado">Alterado no PHC (7 dias)</option>
                </select>
                <select wire:model.live="ano" class="campo-select w-full sm:w-32">
                    <option value="">Todos os anos</option>
                    @foreach ($anos as $a)
                        <option value="{{ $a }}">{{ $a }}</option>
                    @endforeach
                </select>
                <div class="relative col-span-2 sm:col-span-1 sm:min-w-56 sm:flex-1">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 17a6 6 0 100-12 6 6 0 000 12z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por cliente, nº ou nº de cliente...">
                </div>
            </div>

            {{-- MOBILE (< md): um cartão por dossiê. --}}
            <div class="mt-5 space-y-3 md:hidden" wire:loading.class="opacity-60">
                @forelse ($dossiers as $d)
                    <div class="cartao cursor-pointer p-4" wire:key="dos-m-{{ $d->id }}"
                        x-on:click="Livewire.navigate(@js(route('encomendas.ficha', $d)))">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-texto-forte">{{ $d->nome ?: '—' }}</p>
                                <p class="mt-0.5 text-xs text-texto-medio">{{ $d->tipoRotulo() }} · {{ $d->obrano }}/{{ $d->ano }} · {{ $d->data?->translatedFormat('d M Y') ?? '—' }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-base font-semibold text-texto-forte">{{ $d->total_debito !== null ? number_format((float) $d->total_debito, 2, ',', ' ').' €' : '—' }}</div>
                                <span class="etiqueta mt-1 {{ $d->fechada ? 'bg-fundo text-texto-medio' : 'bg-verde-50 text-verde-700' }}">{{ $d->fechada ? 'Fechada' : 'Em aberto' }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="cartao p-8 text-center">
                        <p class="text-sm text-texto-medio">Sem dossiês nos filtros selecionados.</p>
                    </div>
                @endforelse
            </div>

            {{-- DESKTOP (md+): tabela. --}}
            <div class="cartao mt-5 hidden overflow-x-auto md:block" wire:loading.class="opacity-60">
                <table class="w-full min-w-[820px] text-sm">
                    <thead>
                        <tr class="border-b border-borda text-left text-xs uppercase tracking-wide text-texto-fraco">
                            <th class="px-6 py-3 font-semibold">Tipo</th>
                            <th class="px-6 py-3 font-semibold">Nº</th>
                            <th class="px-6 py-3 font-semibold">Cliente</th>
                            <th class="px-6 py-3 font-semibold">Data</th>
                            <th class="px-6 py-3 text-right font-semibold">Total</th>
                            <th class="px-6 py-3 font-semibold">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dossiers as $d)
                            <tr class="cursor-pointer border-b border-borda last:border-0 hover:bg-fundo" wire:key="dos-{{ $d->id }}"
                                x-on:click="Livewire.navigate(@js(route('encomendas.ficha', $d)))">
                                <td class="whitespace-nowrap px-6 py-3.5 text-texto-medio">{{ $d->tipoRotulo() }}</td>
                                <td class="whitespace-nowrap px-6 py-3.5 font-medium text-texto-forte">{{ $d->obrano }}/{{ $d->ano }}</td>
                                <td class="px-6 py-3.5 text-texto-forte">{{ $d->nome ?: '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-texto-medio">{{ $d->data?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-medium text-texto-forte">{{ $d->total_debito !== null ? number_format((float) $d->total_debito, 2, ',', ' ').' €' : '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-3.5">
                                    <span class="etiqueta {{ $d->fechada ? 'bg-fundo text-texto-medio' : 'bg-verde-50 text-verde-700' }}">{{ $d->fechada ? 'Fechada' : 'Em aberto' }}</span>
                                    {{-- Apurado no sync: o dossiê já não vem do PHC, ou mudou lá. --}}
                                    @if ($d->ausenteDoErp())
                                        <span class="etiqueta bg-perigo-100 text-perigo-600" title="Já não existe no PHC desde {{ $d->ausente_do_erp_em->translatedFormat('d M Y H:i') }} — continua aqui para consulta">Fora do PHC</span>
                                    @elseif ($d->alterado_erp_em && $d->alterado_erp_em->gte(now()->subDays(7)))
                                        <span class="etiqueta bg-aviso-100 text-aviso-500" title="Alterado no PHC a {{ $d->alterado_erp_em->translatedFormat('d M Y H:i') }}: {{ implode(' · ', $d->alteracoesLegiveis()) }}">Alterado</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-sm text-texto-medio">Sem dossiês nos filtros selecionados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-5">{{ $dossiers->links() }}</div>
        </div>
    </main>
</div>
