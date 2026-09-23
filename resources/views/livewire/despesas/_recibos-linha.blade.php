{{-- Recibos de UMA linha do registo: digitalizar (scanner), câmara ou galeria + miniaturas.
     Usado nos dois layouts do editor (cartões mobile e tabela desktop) — $sufixo distingue
     os wire:key para os dois não colidirem. --}}
@php($gravados = isset($linha['despesa_id']) && $linha['despesa_id'] ? ($recibosPorDespesa[$linha['despesa_id']] ?? collect()) : collect())

<div class="flex items-center gap-1.5">
    <button type="button" @click="$wire.set('linhaDigitalizacao', {{ $n }}, false); abrir()" class="rounded-md border border-borda p-2 text-texto-medio hover:text-verde-700" title="Digitalizar recibo (câmara + filtro de documento)">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0a4 4 0 11-8 0 4 4 0 018 0zM4 16H2m2-5.5L2.5 9M20 10.5L21.5 9M7 4h10l1 3H6l1-3z"/></svg>
    </button>
    <label class="cursor-pointer rounded-md border border-borda p-2 text-texto-medio hover:text-verde-700" title="Tirar foto">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        <input type="file" wire:model="recibosLinhaUpload.{{ $n }}" @change="lerQrDoFicheiro($event, {{ $n }})" accept="image/*" capture="environment" class="hidden">
    </label>
    <label class="cursor-pointer rounded-md border border-borda p-2 text-texto-medio hover:text-verde-700" title="Escolher da galeria">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <input type="file" wire:model="recibosLinhaUpload.{{ $n }}" @change="lerQrDoFicheiro($event, {{ $n }})" accept="image/*" multiple class="hidden">
    </label>
    <span wire:loading wire:target="recibosLinhaUpload.{{ $n }},reciboDigitalizado" class="text-xs text-texto-medio">a carregar…</span>
</div>

{{-- O que o QR code do recibo deu (faturas portuguesas): Dia e Valor já foram preenchidos se
     estavam vazios. Se a linha tiver outra coisa, avisa — a pessoa decide qual está certo. --}}
@php($qr = $qrLido[$n] ?? null)
@if ($qr)
    @if ($qr['estado'] === 'lido')
        @php($difere = ($linha['dia'] ?? '') !== $qr['data'] || (float) ($linha['valor'] ?? 0) != (float) $qr['total'])
        <p class="mt-1.5 text-xs {{ $difere ? 'text-aviso-500' : 'text-verde-700' }}">
            QR do recibo: {{ \Illuminate\Support\Carbon::parse($qr['data'])->format('d/m/Y') }} · {{ number_format((float) $qr['total'], 2, ',', ' ') }} €@if ($difere) — diferente do que está na linha @endif
        </p>
    @else
        <p class="mt-1.5 text-xs text-texto-fraco">
            {{ $qr['estado'] === 'sem_qr' ? 'Sem QR code legível no recibo — preencha à mão.' : 'O QR code não é de uma fatura portuguesa — preencha à mão.' }}
        </p>
    @endif
@endif

@if ($gravados->isNotEmpty() || ($recibosPendentes[$n] ?? []) !== [])
    <div class="mt-1.5 flex flex-wrap gap-1.5">
        @foreach ($gravados as $recibo)
            <span class="group relative" wire:key="rg-{{ $sufixo }}-{{ $recibo->id }}">
                <a href="{{ route('despesas.recibos.ver', $recibo) }}" target="_blank">
                    <img src="{{ route('despesas.recibos.ver', $recibo) }}" alt="{{ $recibo->nome_ficheiro }}" class="h-12 w-12 rounded border border-borda object-cover">
                </a>
                <button type="button" wire:click="removerReciboGravado({{ $recibo->id }})" wire:confirm="Remover este recibo?"
                    class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-perigo-600 text-white sm:hidden sm:group-hover:flex" title="Remover">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </span>
        @endforeach
        @foreach ($recibosPendentes[$n] ?? [] as $i => $pendente)
            <span class="group relative" wire:key="rp-{{ $sufixo }}-{{ $n }}-{{ $i }}">
                {{-- Só imagens têm pré-visualização; outro tipo (nunca devia chegar aqui) mostra o nome
                     em vez de rebentar o render — e é recusado ao guardar. --}}
                @if ($pendente->isPreviewable())
                    <img src="{{ $pendente->temporaryUrl() }}" alt="Recibo pendente" class="h-12 w-12 rounded border border-verde-300 object-cover">
                @else
                    <span class="flex h-12 w-12 items-center justify-center rounded border border-perigo-300 bg-perigo-50 text-[10px] text-perigo-600" title="{{ $pendente->getClientOriginalName() }}">?</span>
                @endif
                <button type="button" wire:click="removerReciboPendente({{ $n }}, {{ $i }})"
                    class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-perigo-600 text-white sm:hidden sm:group-hover:flex" title="Remover">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </span>
        @endforeach
    </div>
@endif
