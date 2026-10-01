<div>
    <x-topbar :breadcrumb="['Despesas', 'Despesa nº ' . $registo->id]">
        @if ($podeEditar)
            <a href="{{ route('despesas.registo.editar', $registo) }}" wire:navigate class="botao-secundario">Editar</a>
        @endif
        <a href="{{ route('despesas.registo.pdf', $registo) }}" class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17v3a2 2 0 002 2h14a2 2 0 002-2v-3"/></svg>
            PDF completo
        </a>
        {{-- Só as digitalizações, uma por página: é o que quem trata da faturação costuma
             pedir, sem a folha atrás. --}}
        @if ($registo->temRecibos())
            <a href="{{ route('despesas.registo.pdf.recibos', $registo) }}" class="botao-secundario">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                PDF do recibo
            </a>
        @endif
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-screen-2xl">

            <x-toast-sucesso />
            @if (session('erro'))
                <div class="mb-6 flex items-center gap-2 rounded-lg border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm font-medium text-perigo-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ session('erro') }}
                </div>
            @endif

            {{-- Cabeçalho --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Despesa nº {{ $registo->id }}</h1>
                        <span class="etiqueta {{ $registo->estado->classesEtiqueta() }}">{{ $registo->estado->rotulo() }}</span>
                    </div>
                    <p class="mt-2 text-sm text-texto-medio">
                        {{ $registo->colaborador?->nome ?? '—' }}
                        · submetida {{ ($registo->submetido_em ?? $registo->created_at)?->translatedFormat('d M Y, H:i') ?? '—' }}
                        · {{ $linhas->count() }} {{ \Illuminate\Support\Str::plural('lançamento', $linhas->count()) }}
                    </p>
                </div>
                <div class="text-left sm:text-right">
                    @if ($registo->estado === \App\Enums\EstadoDespesa::AprovadaParcialmente)
                        {{-- Aprovada parcialmente: em destaque o APROVADO, por baixo o total pedido. --}}
                        <div class="text-xs uppercase tracking-wide text-verde-700">Aprovado</div>
                        <div class="text-2xl font-semibold text-verde-700">{{ number_format($totalAprovado, 2, ',', ' ') }} €</div>
                        <div class="text-xs text-texto-fraco">de {{ number_format($total, 2, ',', ' ') }} € pedidos</div>
                    @else
                        <div class="text-xs uppercase tracking-wide text-texto-fraco">Total</div>
                        <div class="text-2xl font-semibold text-texto-forte">{{ number_format($total, 2, ',', ' ') }} €</div>
                    @endif
                </div>
            </div>

            {{-- Decisão (quando já houve) --}}
            @if ($registo->estado !== \App\Enums\EstadoDespesa::Pendente)
                <div class="mt-6 rounded-lg border px-4 py-3 text-sm {{ $registo->estado->aprovada() ? 'border-verde-200 bg-verde-50 text-verde-700' : 'border-perigo-200 bg-perigo-100 text-perigo-600' }}">
                    <span class="font-semibold">{{ $registo->estado->rotulo() }}</span>
                    @if ($registo->estado === \App\Enums\EstadoDespesa::AprovadaParcialmente)
                        — aprovado <span class="font-semibold">{{ number_format($totalAprovado, 2, ',', ' ') }} €</span> de {{ number_format($total, 2, ',', ' ') }} €
                    @endif
                    por {{ $registo->decisor?->nome ?? '—' }}
                    @if ($registo->decidido_em) em {{ $registo->decidido_em->translatedFormat('d M Y, H:i') }}@endif
                    @if ($registo->estado === \App\Enums\EstadoDespesa::Rejeitada)
                        <div class="mt-1 whitespace-pre-line">Motivo: {{ $registo->motivo_rejeicao ?: '—' }}</div>
                        <div class="mt-1 text-xs">Quem registou a despesa pode corrigi-la em "Editar" — ao guardar volta a ficar pendente de aprovação.</div>
                    @endif
                </div>
            @endif

            {{-- Aprovação (só o aprovador, só pendentes) --}}
            @if ($podeAprovar && $registo->estado === \App\Enums\EstadoDespesa::Pendente)
                <section class="cartao mt-6 p-5" x-data="{ rejeitar: false }">
                    <h2 class="text-lg font-semibold text-texto-forte">Aprovação</h2>
                    <p class="mt-1 text-sm text-texto-medio">Confira as linhas e os recibos abaixo. Para aprovar só uma parte, desmarque as linhas a recusar (cada uma com o motivo). A decisão é enviada por email ao colaborador e ao financeiro.</p>
                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                        @if ($nRecusar === 0)
                            <button type="button" wire:click="aprovar" wire:confirm="Aprovar esta despesa ({{ number_format($total, 2, ',', ' ') }} €)?" class="botao-primario justify-center">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                Aprovar
                            </button>
                        @else
                            {{-- Aprovação PARCIAL (out. 2026): aprova as linhas marcadas, recusa as outras. --}}
                            <button type="button" wire:click="aprovarParcialmente" wire:confirm="Aprovar {{ number_format($totalAAprovar, 2, ',', ' ') }} € de {{ number_format($total, 2, ',', ' ') }} € e recusar {{ $nRecusar }} {{ $nRecusar === 1 ? 'linha' : 'linhas' }}?" class="botao-primario justify-center">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                Aprovar parcialmente ({{ number_format($totalAAprovar, 2, ',', ' ') }} € de {{ number_format($total, 2, ',', ' ') }} €)
                            </button>
                        @endif
                        <button type="button" @click="rejeitar = !rejeitar" class="botao-secundario justify-center text-perigo-600">Rejeitar…</button>
                    </div>
                    @error('aprovarLinha') <p class="mt-2 text-sm text-perigo-500">{{ $message }}</p> @enderror
                    <div x-show="rejeitar" x-cloak class="mt-4 rounded-lg border border-perigo-200 bg-fundo p-4">
                        <label class="campo-label">Motivo da rejeição <span class="text-perigo-500">*</span></label>
                        <textarea wire:model="motivo" rows="3" class="campo-input" placeholder="Ex.: falta o recibo do almoço de dia 5; valor do combustível não corresponde ao talão."></textarea>
                        @error('motivo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        <div class="mt-3">
                            <button type="button" wire:click="rejeitar" class="botao-secundario text-perigo-600">Confirmar rejeição</button>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Dados do cabeçalho --}}
            <section class="cartao mt-6">
                <dl class="grid grid-cols-2 gap-x-8 gap-y-5 px-6 py-6 sm:grid-cols-4">
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Colaborador</dt><dd class="mt-1 text-sm text-texto-forte">{{ $registo->colaborador?->nome ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Matrícula</dt><dd class="mt-1 text-sm text-texto-forte">{{ $registo->matricula ?: '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Departamento</dt><dd class="mt-1 text-sm text-texto-forte">{{ $registo->departamento ?: '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Estado</dt><dd class="mt-1 text-sm text-texto-forte">{{ $registo->estado->rotulo() }}</dd></div>
                </dl>
            </section>

            {{-- Linhas + recibos --}}
            <section class="cartao mt-6 overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead>
                        <tr class="border-b border-borda text-left text-xs uppercase tracking-wide text-texto-fraco">
                            @if ($podeAprovar && $registo->estado === \App\Enums\EstadoDespesa::Pendente)
                                <th class="w-20 py-3 pl-6 font-semibold">Aprovar</th>
                            @endif
                            <th class="px-6 py-3 font-semibold">Dia</th>
                            <th class="px-6 py-3 font-semibold">Descrição</th>
                            <th class="px-6 py-3 font-semibold">Tipo</th>
                            <th class="px-6 py-3 text-right font-semibold">Valor</th>
                            <th class="px-6 py-3 font-semibold">Pago por</th>
                            <th class="px-6 py-3 font-semibold">Recibos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($linhas as $d)
                            @php($aRecusar = ($podeAprovar && $registo->estado === \App\Enums\EstadoDespesa::Pendente) && ! ($aprovarLinha[$d->id] ?? true))
                            <tr class="border-b border-borda last:border-0 {{ $d->recusada || $aRecusar ? 'bg-perigo-100/40' : '' }}" wire:key="linha-{{ $d->id }}">
                                @if ($podeAprovar && $registo->estado === \App\Enums\EstadoDespesa::Pendente)
                                    <td class="py-3.5 pl-6 align-top">
                                        <input type="checkbox" wire:model.live="aprovarLinha.{{ $d->id }}" class="mt-0.5 h-5 w-5 rounded border-borda text-verde-600" title="Aprovar esta linha">
                                    </td>
                                @endif
                                <td class="whitespace-nowrap px-6 py-3.5 text-texto-medio">{{ $d->data->translatedFormat('d M Y') }}</td>
                                <td class="px-6 py-3.5">
                                    <div class="font-medium text-texto-forte">{{ $d->descricao }}</div>
                                    @if ($d->detalhe)<div class="text-xs text-texto-medio">{{ $d->detalhe }}</div>@endif
                                    @if ($d->recusada)
                                        <div class="mt-1 text-xs font-medium text-perigo-600">Recusada: {{ $d->motivo_recusa ?: '—' }}</div>
                                    @elseif ($aRecusar)
                                        <input type="text" wire:model="motivosRecusa.{{ $d->id }}" class="campo-input mt-2 py-1.5 text-sm" placeholder="Motivo da recusa desta linha…" maxlength="500">
                                        @error('motivosRecusa.'.$d->id) <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-texto-medio">{{ $d->categoria }}@if ($d->refeicao_tipo) ({{ $d->refeicao_tipo }})@endif</td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-medium {{ $d->recusada || $aRecusar ? 'text-texto-fraco line-through' : 'text-texto-forte' }}">{{ number_format((float) $d->valor, 2, ',', ' ') }} €</td>
                                <td class="whitespace-nowrap px-6 py-3.5 font-bold text-texto-forte">{{ $d->pagoPorRotulo() ?? '—' }}</td>
                                <td class="px-6 py-3.5">
                                    <div class="flex flex-wrap gap-2">
                                        @forelse ($d->anexos as $a)
                                            <a href="{{ route('despesas.recibos.ver', $a) }}" target="_blank" class="block overflow-hidden rounded border border-borda" title="{{ $a->nome_ficheiro }}">
                                                <img src="{{ route('despesas.recibos.ver', $a) }}" alt="Recibo" class="h-16 w-16 object-cover">
                                            </a>
                                        @empty
                                            <span class="text-xs text-perigo-500">Sem recibo</span>
                                        @endforelse
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        @php($colunasAntes = ($podeAprovar && $registo->estado === \App\Enums\EstadoDespesa::Pendente) ? 4 : 3)
                        <tr class="border-t border-borda">
                            <td colspan="{{ $colunasAntes }}" class="px-6 py-3 text-right text-xs uppercase tracking-wide text-texto-fraco">Total</td>
                            <td class="whitespace-nowrap px-6 py-3 text-right font-semibold text-texto-forte">{{ number_format($total, 2, ',', ' ') }} €</td>
                            <td colspan="2"></td>
                        </tr>
                        @if ($registo->estado === \App\Enums\EstadoDespesa::AprovadaParcialmente)
                            <tr>
                                <td colspan="{{ $colunasAntes }}" class="px-6 pb-3 text-right text-xs uppercase tracking-wide text-verde-700">Aprovado</td>
                                <td class="whitespace-nowrap px-6 pb-3 text-right font-semibold text-verde-700">{{ number_format($totalAprovado, 2, ',', ' ') }} €</td>
                                <td colspan="2"></td>
                            </tr>
                        @endif
                    </tfoot>
                </table>
            </section>

            {{-- Dinheiro levantado do cartão: os levantamentos (com o talão) e as contas. --}}
            @if ($levantamentos->isNotEmpty() || $contasDinheiro)
                <section class="cartao mt-6 p-6">
                    <h2 class="text-sm font-semibold text-texto-forte">Levantamentos do cartão</h2>
                    @if ($levantamentos->isNotEmpty())
                        <ul class="mt-3 divide-y divide-borda">
                            @foreach ($levantamentos as $lev)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="lev-{{ $lev->id }}">
                                    <div class="text-sm">
                                        <span class="text-texto-medio">{{ $lev->data->translatedFormat('d M Y') }}</span>
                                        <span class="ml-3 font-semibold text-texto-forte">{{ number_format((float) $lev->valor, 2, ',', ' ') }} €</span>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @forelse ($lev->anexos as $a)
                                            <a href="{{ route('despesas.recibos.ver', $a) }}" target="_blank" class="block overflow-hidden rounded border border-borda" title="Talão do multibanco">
                                                <img src="{{ route('despesas.recibos.ver', $a) }}" alt="Talão" class="h-16 w-16 object-cover">
                                            </a>
                                        @empty
                                            <span class="text-xs text-perigo-500">Sem talão</span>
                                        @endforelse
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @include('livewire.despesas._contas-dinheiro', ['contas' => $contasDinheiro])
                </section>
            @endif

            <div class="mt-6">
                <a href="{{ route('despesas') }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">← Voltar às despesas</a>
            </div>
        </div>
    </main>
</div>
