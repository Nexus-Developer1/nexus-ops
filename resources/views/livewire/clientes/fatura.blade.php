<div>
    <x-topbar :breadcrumb="['Início', 'Clientes', $cliente->nome, 'Faturação', trim($linha->nmdoc . ' ' . $linha->fno)]">
        <a href="{{ route('clientes.faturacao', $cliente) }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar à faturação
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">

            @php
                $totalSeries = $linhas->sum(fn ($l) => filled($l->series) ? substr_count($l->series, ',') + 1 : 0);
            @endphp

            <h1 class="flex flex-wrap items-center gap-3 text-3xl font-semibold tracking-tight text-texto-forte">
                {{ trim($linha->nmdoc . ' ' . $linha->fno) ?: 'Fatura' }}
                @if ($linha->anulada)
                    <span class="etiqueta bg-perigo-100 text-perigo-600">Anulada no PHC</span>
                @endif
            </h1>
            <p class="mt-2 text-sm text-texto-medio">{{ $cliente->nome }} · {{ $linhas->count() }} {{ \Illuminate\Support\Str::plural('artigo', $linhas->count()) }} · {{ $totalSeries }} {{ \Illuminate\Support\Str::plural('série', $totalSeries) }}</p>

            {{-- Cabeçalho do documento --}}
            <section class="cartao mt-8 p-6">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Documento</h2>
                <dl class="mt-4 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-4">
                    <div><dt class="text-xs text-texto-fraco">Tipo</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $linha->nmdoc ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Nº</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $linha->fno ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Data</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $linha->data?->translatedFormat('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Cliente (ERP)</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->id_erp ?? '—' }}</dd></div>
                </dl>
            </section>

            {{-- Linhas da fatura --}}
            <div class="cartao mt-5 overflow-hidden">
                <div class="overflow-x-auto"><table class="w-full min-w-[720px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3.5 font-semibold">Descrição</th>
                            <th class="px-6 py-3.5 font-semibold">Referência</th>
                            <th class="px-6 py-3.5 font-semibold">Série(s)</th>
                            <th class="px-6 py-3.5 font-semibold text-right">Qtd</th>
                            @if ($ehAdmin)
                                <th class="px-6 py-3.5 font-semibold text-right">Unit.</th>
                                <th class="px-6 py-3.5 font-semibold text-right">Desc.</th>
                                <th class="px-6 py-3.5 font-semibold text-right">Total (s/ IVA)</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($linhas as $l)
                            @php $nSeries = filled($l->series) ? substr_count($l->series, ',') + 1 : 0; @endphp
                            <tr class="border-b border-borda last:border-0 align-top" wire:key="linha-{{ $l->id }}">
                                <td class="px-6 py-4 font-medium text-texto-forte">{{ $l->design ?: '—' }}</td>
                                <td class="px-6 py-4 text-texto-medio whitespace-nowrap">{{ $l->ref ?: '—' }}</td>
                                <td class="px-6 py-4">
                                    @if (filled($l->series))
                                        <div class="break-words text-texto-medio">{{ $l->series }}</div>
                                        @if ($nSeries > 1)
                                            <div class="mt-1 text-xs text-texto-fraco">{{ $nSeries }} séries</div>
                                        @endif
                                    @else
                                        <span class="text-texto-fraco">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-texto-medio whitespace-nowrap">{{ $l->qtt !== null ? rtrim(rtrim((string) $l->qtt, '0'), '.') : '—' }}</td>
                                @if ($ehAdmin)
                                    <td class="px-6 py-4 text-right text-texto-medio whitespace-nowrap">{{ $l->preco_unitario !== null ? number_format((float) $l->preco_unitario, 2, ',', ' ') . ' €' : '—' }}</td>
                                    <td class="px-6 py-4 text-right text-texto-medio whitespace-nowrap">{{ $l->desconto > 0 ? rtrim(rtrim(number_format((float) $l->desconto, 2, ',', ''), '0'), ',') . '%' : '—' }}</td>
                                    <td class="px-6 py-4 text-right font-medium text-texto-forte whitespace-nowrap">{{ $l->total_linha !== null ? number_format((float) $l->total_linha, 2, ',', ' ') . ' €' : '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>

                {{-- Totais do DOCUMENTO (do PHC, não somados aqui) — SÓ ADMIN. --}}
                @if ($ehAdmin && ($linha->total_documento !== null || $linha->total_documento_iva !== null))
                    <div class="flex flex-wrap justify-end gap-x-10 gap-y-2 border-t border-borda bg-fundo px-6 py-4 text-sm">
                        <div class="text-texto-medio">Total s/ IVA <span class="ml-2 font-semibold text-texto-forte">{{ $linha->total_documento !== null ? number_format((float) $linha->total_documento, 2, ',', ' ') . ' €' : '—' }}</span></div>
                        <div class="text-texto-medio">Total c/ IVA <span class="ml-2 font-semibold text-texto-forte">{{ $linha->total_documento_iva !== null ? number_format((float) $linha->total_documento_iva, 2, ',', ' ') . ' €' : '—' }}</span></div>
                    </div>
                @endif
            </div>

            @if ($ehAdmin)
                <p class="mt-3 text-xs text-texto-fraco">Nota: o PHC só nos dá as linhas com nº de série — serviços (mão de obra, deslocações) contam para o total do documento mas não aparecem aqui.</p>
            @endif

        </div>
    </main>
</div>
