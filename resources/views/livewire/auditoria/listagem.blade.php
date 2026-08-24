<div>
    <x-topbar :breadcrumb="['Início', 'Auditoria']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Auditoria</h1>
            <p class="mt-2 text-sm text-texto-medio">Registo das ações sensíveis da operação — só de leitura (append-only). {{ $registos->total() }} {{ \Illuminate\Support\Str::plural('registo', $registos->total()) }}.</p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="relative w-full max-w-sm">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.400ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por utilizador, ação ou entidade...">
                </div>
                <select wire:model.live="acao" class="campo-select w-full sm:w-auto sm:min-w-[13rem]">
                    <option value="">Todas as ações</option>
                    @foreach ($acoes as $a)
                        <option value="{{ $a }}">{{ str_replace('_', ' ', $a) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="cartao mt-6 overflow-hidden" wire:loading.class="opacity-60">
                <div class="overflow-x-auto"><table class="w-full min-w-[860px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Quando</th>
                            <th class="px-6 py-3.5 font-semibold">Quem</th>
                            <th class="px-6 py-3.5 font-semibold">Ação</th>
                            <th class="px-6 py-3.5 font-semibold">Entidade</th>
                            <th class="px-6 py-3.5 font-semibold">Detalhe</th>
                            <th class="px-6 py-3.5 font-semibold">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($registos as $r)
                            <tr class="border-b border-borda align-top last:border-0" wire:key="aud-{{ $r->id }}">
                                <td class="px-6 py-4 text-texto-medio whitespace-nowrap">{{ $r->criado_em->translatedFormat('d M Y H:i:s') }}</td>
                                <td class="px-6 py-4 text-texto-forte">{{ $r->email ?? 'sistema' }}</td>
                                <td class="px-6 py-4"><span class="etiqueta bg-fundo text-texto-medio">{{ str_replace('_', ' ', $r->acao) }}</span></td>
                                <td class="px-6 py-4 text-texto-medio whitespace-nowrap">{{ $r->entidade_tipo ? $r->entidade_tipo . ' #' . $r->entidade_id : '—' }}</td>
                                <td class="px-6 py-4 text-texto-medio">
                                    @if ($r->detalhe)
                                        <div class="max-w-md break-words text-xs">
                                            @foreach ($r->detalhe as $chave => $valor)
                                                <div><span class="text-texto-fraco">{{ $chave }}:</span> {{ \App\Support\ValorLegivel::texto($valor) }}</div>
                                            @endforeach
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-texto-fraco whitespace-nowrap">{{ $r->ip ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-texto-medio">Ainda não há registos de auditoria.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="mt-4">{{ $registos->links() }}</div>

        </div>
    </main>
</div>
