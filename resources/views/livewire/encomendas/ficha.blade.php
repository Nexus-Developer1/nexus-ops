<div>
    <x-topbar :breadcrumb="['Início', 'Encomendas', $dossier->tipoRotulo().' '.$dossier->obrano.'/'.$dossier->ano]">
        <a href="{{ route('encomendas') }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <h1 class="flex flex-wrap items-center gap-3 text-3xl font-semibold tracking-tight text-texto-forte">
                {{ $dossier->tipoRotulo() }} {{ $dossier->obrano }}/{{ $dossier->ano }}
                <span class="etiqueta {{ $dossier->fechada ? 'bg-fundo text-texto-medio' : 'bg-verde-50 text-verde-700' }}">{{ $dossier->fechada ? 'Fechada' : 'Em aberto' }}</span>
            </h1>
            <p class="mt-2 text-sm text-texto-medio">{{ $dossier->nome ?: '—' }}</p>

            {{-- Cabeçalho do dossiê (da nossa BD). --}}
            <section class="cartao mt-8 p-6">
                <dl class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-4">
                    <div><dt class="text-xs text-texto-fraco">Tipo</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $dossier->tipoRotulo() }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Nº · Ano</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $dossier->obrano }} · {{ $dossier->ano }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Data</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $dossier->data?->translatedFormat('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Total (débito)</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $dossier->total_debito !== null ? number_format((float) $dossier->total_debito, 2, ',', ' ').' €' : '—' }}</dd></div>
                    @if ($cliente = $dossier->cliente)
                        <div class="sm:col-span-2"><dt class="text-xs text-texto-fraco">Cliente</dt><dd class="mt-0.5 text-sm font-medium"><a href="{{ route('clientes.detalhe', $cliente) }}" wire:navigate class="text-verde-600 hover:underline">{{ $dossier->nome }}</a></dd></div>
                    @else
                        <div class="sm:col-span-2"><dt class="text-xs text-texto-fraco">Cliente</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $dossier->nome ?: '—' }} <span class="text-xs text-texto-fraco">(nº {{ $dossier->cliente_no ?? '—' }})</span></dd></div>
                    @endif
                    @if ($dossier->u_relat)
                        <div class="sm:col-span-2"><dt class="text-xs text-texto-fraco">Relatório</dt><dd class="mt-0.5 text-sm text-texto-medio">{{ $dossier->u_relat }}</dd></div>
                    @endif
                </dl>
            </section>

            {{-- Linhas do dossiê — LIDAS AO VIVO do PHC (não sincronizadas). --}}
            <div class="mt-6 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-texto-forte">Linhas</h2>
                <span class="text-xs text-texto-fraco">em direto do PHC</span>
            </div>

            @if ($erroLinhas)
                <div class="cartao mt-3 flex items-center gap-2 border border-aviso-200 bg-aviso-100 p-4 text-sm text-aviso-500">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    Não foi possível obter as linhas do PHC neste momento. Tente novamente daqui a pouco.
                </div>
            @else
                <div class="cartao mt-3 overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead>
                            <tr class="border-b border-borda text-left text-xs uppercase tracking-wide text-texto-fraco">
                                <th class="px-4 py-3 font-semibold">Referência</th>
                                <th class="px-4 py-3 font-semibold">PN</th>
                                <th class="px-4 py-3 font-semibold">Marca</th>
                                <th class="px-4 py-3 font-semibold">Descrição</th>
                                <th class="px-4 py-3 text-right font-semibold">Faltas</th>
                                <th class="px-4 py-3 text-right font-semibold">Qtd</th>
                                <th class="px-4 py-3 text-right font-semibold">Movim.</th>
                                <th class="px-4 py-3 font-semibold">Série(s)</th>
                                <th class="px-4 py-3 text-right font-semibold">Unitário</th>
                                <th class="px-4 py-3 text-right font-semibold">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($linhas as $l)
                                @php($num = fn ($v) => $v !== null ? rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',') : '—')
                                <tr class="border-b border-borda align-top last:border-0" wire:key="linha-{{ $loop->index }}">
                                    <td class="px-4 py-3 font-medium text-texto-forte whitespace-nowrap">{{ $l->ref ?: '—' }}</td>
                                    <td class="px-4 py-3 text-texto-medio whitespace-nowrap">{{ $l->pn ?: '—' }}</td>
                                    <td class="px-4 py-3 text-texto-medio whitespace-nowrap">{{ $l->marca ?: '—' }}</td>
                                    <td class="px-4 py-3 text-texto-forte">{{ $l->descricao ?: '—' }}</td>
                                    <td class="px-4 py-3 text-right text-texto-medio">{{ $num($l->faltas) }}</td>
                                    <td class="px-4 py-3 text-right text-texto-medio">{{ $num($l->qtt) }}</td>
                                    <td class="px-4 py-3 text-right text-texto-medio">{{ $num($l->movimentado) }}</td>
                                    <td class="px-4 py-3 text-texto-medio">{{ $l->series ?: '—' }}</td>
                                    <td class="px-4 py-3 text-right text-texto-medio whitespace-nowrap">{{ $l->valorUnitario !== null ? number_format((float) $l->valorUnitario, 2, ',', ' ').' €' : '—' }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-texto-forte whitespace-nowrap">{{ $l->total !== null ? number_format((float) $l->total, 2, ',', ' ').' €' : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="px-4 py-10 text-center text-sm text-texto-medio">Este dossiê não tem linhas no PHC.</td></tr>
                            @endforelse
                        </tbody>
                        @if (! empty($linhas))
                            <tfoot>
                                <tr class="border-t border-borda bg-fundo">
                                    <td colspan="9" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-texto-medio">Total das linhas</td>
                                    <td class="px-4 py-3 text-right font-semibold text-texto-forte whitespace-nowrap">{{ number_format((float) $totalLinhas, 2, ',', ' ') }} €</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            @endif
        </div>
    </main>
</div>
