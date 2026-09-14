{{-- Encomendas de peças (dossiês PHC do tipo 1) ligadas a esta intervenção — pedido
     da equipa, set. 2026. Sem texto sugere as do cliente do relatório; com texto
     procura em todas pelo nº ou pelo cliente. Clicar num chip abre a encomenda
     noutro separador (para não perder o que se está a escrever aqui). --}}
<div class="sm:col-span-2">
    <div class="mb-2 flex items-center justify-between">
        <label class="campo-label mb-0" for="encomenda-combo">Encomendas de peças</label>
        <label class="inline-flex cursor-pointer items-center gap-2 text-xs text-texto-medio">
            <input type="checkbox" wire:model.live="encomendasSoAbertas" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500">
            Só encomendas abertas
        </label>
    </div>
    @if ($encomendasEscolhidas->isNotEmpty() || $encomendasManuais !== [])
        <div class="mb-2 flex flex-wrap gap-2">
            @foreach ($encomendasEscolhidas as $d)
                <span wire:key="enc-{{ $d->id }}" class="inline-flex items-center gap-1.5 rounded-full border border-verde-200 bg-verde-50 py-1 pl-3 pr-1 text-xs font-medium text-verde-700">
                    <a href="{{ route('encomendas.ficha', $d) }}" target="_blank" rel="noopener" class="hover:underline">
                        Nº {{ $d->obrano }}<span class="font-normal text-verde-600"> · {{ \Illuminate\Support\Str::limit($d->nome, 30) }} · {{ $d->data?->format('d/m/Y') }}</span>
                    </a>
                    <button type="button" wire:click="removerEncomenda({{ $d->id }})" @click="marcarSuja()" title="Desligar esta encomenda"
                        class="flex h-5 w-5 items-center justify-center rounded-full text-verde-600 transition hover:bg-verde-100 hover:text-verde-800">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </span>
            @endforeach
            {{-- Escritas à mão, ainda por chegar do PHC: passam a chip verde sozinhas no sync. --}}
            @foreach ($encomendasManuais as $k => $m)
                <span wire:key="encm-{{ $m['obrano'] }}-{{ $m['ano'] }}" class="inline-flex items-center gap-1.5 rounded-full border border-aviso-200 bg-aviso-100/60 py-1 pl-3 pr-1 text-xs font-medium text-aviso-500"
                    title="Ainda não chegou do PHC — fica ligada sozinha na próxima sincronização">
                    Nº {{ $m['obrano'] }}/{{ $m['ano'] }}<span class="font-normal"> · por sincronizar</span>
                    <button type="button" wire:click="removerEncomendaManual({{ $k }})" @click="marcarSuja()" title="Retirar"
                        class="flex h-5 w-5 items-center justify-center rounded-full transition hover:bg-aviso-200">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </span>
            @endforeach
        </div>
    @endif
    <div wire:key="combo-encomendas" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
        <input id="encomenda-combo" type="text" wire:model.live.debounce.300ms="encomendaBusca"
            @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
            @keydown.arrow-down.prevent="aberto = true; if ($refs['enc' + (destaque + 1)]) destaque++"
            @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
            @keydown.enter.prevent="$refs['enc' + destaque]?.click()"
            class="campo-input pr-10" placeholder="Pesquisar encomenda de peças por nº ou cliente..." autocomplete="off"
            role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
        <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
            @forelse ($encomendasFiltradas as $idx => $d)
                <li x-ref="enc{{ $idx }}" wire:key="encl-{{ $d->id }}"
                    wire:click="adicionarEncomenda({{ $d->id }})" @click="aberto = false; marcarSuja()"
                    @mouseenter="destaque = {{ $idx }}"
                    :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                    class="cursor-pointer px-4 py-2 text-sm" role="option">
                    <span class="font-medium">Encomenda Peças nº {{ $d->obrano }}</span>
                    <span class="text-xs text-texto-fraco"> · {{ $d->data?->format('d/m/Y') }} · {{ $d->fechada ? 'fechada' : 'aberta' }}</span>
                    <span class="block truncate text-xs text-texto-medio">{{ $d->nome }}</span>
                </li>
            @empty
                <li class="px-4 py-2 text-sm text-texto-medio">{{ trim($encomendaBusca) === '' ? 'Escreva o nº da encomenda ou o nome do cliente…' : 'Nenhuma encomenda de peças encontrada.' }}</li>
            @endforelse
        </ul>
    </div>
    {{-- Escrever o nº à mão: para a encomenda criada agora no PHC que o sync ainda não trouxe. --}}
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <input type="number" inputmode="numeric" min="1" wire:model="encomendaManualNumero" wire:keydown.enter.prevent="adicionarEncomendaManual"
            aria-label="Nº da encomenda (à mão)" placeholder="Nº à mão" class="campo-input w-32 py-2">
        <input type="number" inputmode="numeric" min="2000" wire:model="encomendaManualAno"
            aria-label="Ano da encomenda" placeholder="Ano" class="campo-input w-24 py-2">
        <button type="button" wire:click="adicionarEncomendaManual" @click="marcarSuja()" class="botao-secundario py-2">Adicionar</button>
    </div>
    @error('encomendaManualNumero') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('encomendaManualAno') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('encomendasManuais.*.obrano') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('encomendasManuais.*.ano') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('encomendaIds') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('encomendaIds.*') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror

    {{-- Detalhe de cada encomenda ligada: cabeçalho + linhas ao vivo do PHC, para se
         confirmar que é a certa (pedido da equipa, set. 2026). --}}
    @foreach ($encomendasDetalhe as $det)
        @php($d = $det['dossier'])
        <div wire:key="enc-det-{{ $d->id }}" class="mt-3 overflow-hidden rounded-lg border border-borda">
            <div class="flex flex-wrap items-center justify-between gap-2 bg-fundo px-4 py-2.5 text-sm">
                <div class="min-w-0">
                    <span class="font-semibold text-texto-forte">Encomenda Peças nº {{ $d->obrano }}/{{ $d->ano }}</span>
                    <span class="text-texto-medio"> · {{ $d->nome }} · {{ $d->data?->format('d/m/Y') }}</span>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <span class="etiqueta {{ $d->fechada ? 'bg-slate-100 text-texto-medio' : 'bg-verde-50 text-verde-700' }}">{{ $d->fechada ? 'Fechada' : 'Aberta' }}</span>
                    @if ($d->total_debito !== null)<span class="text-texto-medio">{{ number_format((float) $d->total_debito, 2, ',', ' ') }} €</span>@endif
                    <a href="{{ route('encomendas.ficha', $d) }}" target="_blank" rel="noopener" class="font-medium text-verde-700 hover:underline">Abrir</a>
                </div>
            </div>
            @if ($det['erro'])
                <p class="px-4 py-3 text-xs text-aviso-500">Não foi possível ler as linhas no PHC agora.</p>
            @elseif ($det['linhas'] === [])
                <p class="px-4 py-3 text-xs text-texto-fraco">Sem linhas.</p>
            @else
                <div class="overflow-x-auto"><table class="w-full text-left text-xs">
                    <thead><tr class="border-b border-borda text-[11px] uppercase tracking-wide text-texto-fraco">
                        <th class="px-4 py-2 font-semibold">Ref.</th><th class="px-4 py-2 font-semibold">Descrição</th>
                        <th class="px-4 py-2 text-right font-semibold">Qtd</th><th class="px-4 py-2 font-semibold">Série(s)</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($det['linhas'] as $l)
                            <tr class="border-b border-borda last:border-0">
                                <td class="whitespace-nowrap px-4 py-1.5 font-medium text-texto-forte">{{ $l->ref ?: '—' }}</td>
                                <td class="px-4 py-1.5 text-texto-medio">{{ $l->descricao ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-1.5 text-right text-texto-medio">{{ $l->qtt !== null ? rtrim(rtrim(number_format($l->qtt, 2, ',', ''), '0'), ',') : '—' }}</td>
                                <td class="px-4 py-1.5 text-texto-medio">{{ $l->series ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
    @endforeach
</div>
