<div>
    <x-topbar :breadcrumb="['Início', 'Dossiers PHC', $dossier->tipoRotulo().' '.$dossier->obrano.'/'.$dossier->ano]">
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
            <div class="mt-6 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-texto-forte">Linhas</h2>
                <span class="text-xs text-texto-fraco">em direto do PHC</span>
            </div>

            {{-- Escolher COLUNAS: um botão por coluna (ligar/desligar). Arrastar os títulos
                 troca a ordem; o "Repor" volta à ordem de fábrica com todas visíveis. --}}
            @unless ($erroLinhas)
                <div class="mt-3 hidden flex-wrap items-center gap-2 md:flex">
                    <span class="mr-1 text-xs font-medium uppercase tracking-wide text-texto-fraco">Colunas</span>
                    @foreach ($colunas as $chave => $rotulo)
                        @php($visivel = in_array($chave, $visiveis, true))
                        <button type="button" wire:click="alternarColuna('{{ $chave }}')"
                            title="{{ $visivel ? 'Ocultar a coluna' : 'Mostrar a coluna' }} {{ $rotulo }}"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition {{ $visivel
                                ? 'border-verde-200 bg-verde-50 text-verde-700 hover:bg-verde-100'
                                : 'border-borda bg-white text-texto-fraco line-through hover:text-texto-medio' }}">
                            {{ $rotulo }}
                        </button>
                    @endforeach
                    <span class="ml-1 text-xs text-texto-fraco">· arraste os títulos para trocar a ordem</span>
                    <button type="button" wire:click="reporColunas" class="text-xs font-medium text-texto-medio hover:text-verde-700">Repor</button>
                </div>
            @endunless

            @if ($erroLinhas)
                <div class="cartao mt-3 flex items-center gap-2 border border-aviso-200 bg-aviso-100 p-4 text-sm text-aviso-500">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    Não foi possível obter as linhas do PHC neste momento. Tente novamente daqui a pouco.
                </div>
            @else
                {{-- Colunas REORDENÁVEIS: arrastar o título troca a ordem (guardada por
                     utilizador na sessão; a whitelist é revalidada no servidor). --}}
                <div class="cartao mt-3 overflow-x-auto" x-data="{ arrastado: null }">
                    {{-- A largura mínima só se impõe com muitas colunas: ocultar colunas
                         passa a livrar mesmo do scroll horizontal. --}}
                    <table class="w-full text-sm {{ count($visiveis) >= 7 ? 'min-w-[900px]' : '' }}">
                        <thead>
                            <tr class="border-b border-borda text-left text-xs uppercase tracking-wide text-texto-fraco">
                                @foreach ($visiveis as $col)
                                    <th draggable="true"
                                        x-on:dragstart="arrastado = '{{ $col }}'"
                                        x-on:dragover.prevent
                                        x-on:drop.prevent="if (arrastado && arrastado !== '{{ $col }}') { $wire.reordenarColunas(window.reordenar($wire.ordemColunas, arrastado, '{{ $col }}')) } arrastado = null"
                                        class="cursor-move select-none px-4 py-3 font-semibold hover:text-texto-forte {{ in_array($col, $numericas, true) ? 'text-right' : '' }}">
                                        {{ $colunas[$col] }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($linhas as $l)
                                @php($num = fn ($v) => $v !== null ? rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',') : '—')
                                @php($eur = fn ($v) => $v !== null ? number_format((float) $v, 2, ',', ' ').' €' : '—')
                                <tr class="border-b border-borda align-top last:border-0" wire:key="linha-{{ $loop->index }}">
                                    @foreach ($visiveis as $col)
                                        @switch($col)
                                            @case('ref')
                                                <td class="whitespace-nowrap px-4 py-3 font-medium text-texto-forte">{{ $l->ref ?: '—' }}</td>
                                                @break
                                            @case('pn')
                                                <td class="whitespace-nowrap px-4 py-3 text-texto-medio">{{ $l->pn ?: '—' }}</td>
                                                @break
                                            @case('marca')
                                                <td class="whitespace-nowrap px-4 py-3 text-texto-medio">{{ $l->marca ?: '—' }}</td>
                                                @break
                                            @case('descricao')
                                                <td class="px-4 py-3 text-texto-forte">{{ $l->descricao ?: '—' }}</td>
                                                @break
                                            @case('faltas')
                                                <td class="px-4 py-3 text-right text-texto-medio">{{ $num($l->faltas) }}</td>
                                                @break
                                            @case('qtt')
                                                <td class="px-4 py-3 text-right text-texto-medio">{{ $num($l->qtt) }}</td>
                                                @break
                                            @case('movimentado')
                                                <td class="px-4 py-3 text-right text-texto-medio">{{ $num($l->movimentado) }}</td>
                                                @break
                                            @case('series')
                                                <td class="px-4 py-3 text-texto-medio">{{ $l->series ?: '—' }}</td>
                                                @break
                                            @case('unitario')
                                                <td class="whitespace-nowrap px-4 py-3 text-right text-texto-medio">{{ $eur($l->valorUnitario) }}</td>
                                                @break
                                            @case('total')
                                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium text-texto-forte">{{ $eur($l->total) }}</td>
                                                @break
                                        @endswitch
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($visiveis) }}" class="px-4 py-10 text-center text-sm text-texto-medio">Este dossiê não tem linhas no PHC.</td></tr>
                            @endforelse
                        </tbody>
                        @if (! empty($linhas))
                            <tfoot>
                                <tr class="border-t border-borda bg-fundo">
                                    <td colspan="{{ count($visiveis) }}" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-texto-medio">
                                        Total das linhas <span class="ml-3 text-sm font-semibold normal-case text-texto-forte">{{ number_format((float) $totalLinhas, 2, ',', ' ') }} €</span>
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            @endif
        </div>
    </main>
</div>
