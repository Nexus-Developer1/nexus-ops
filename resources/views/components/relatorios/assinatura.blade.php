@props([
    'prefixo',      // caminho Livewire da ficha, ex.: "fichas.123"
    'quem',         // 'cliente' | 'tecnico'
    'rotulo',       // "Assinatura do cliente"
    'guardada',     // URL da assinatura já gravada (ou null)
])

{{-- Assinatura desenhada no ecrã (iPad + Apple Pencil, rato ou dedo). O traço é capturado
     num <canvas> por pointer events e enviado ao Livewire como PNG (data URI) só quando se
     levanta a caneta — o servidor valida e guarda no object storage. --}}
<div wire:key="assinatura-{{ $quem }}-{{ $prefixo }}"
    x-data="assinaturaPad(@js($prefixo . '.assinatura_' . $quem), @js((bool) $guardada))"
    class="rounded-lg border border-borda p-3">

    <div class="mb-2 flex items-center justify-between">
        <span class="campo-label mb-0">{{ $rotulo }}</span>
        <div class="flex items-center gap-3">
            <span x-show="temTraco && !bloqueada" x-cloak class="text-xs text-verde-600">assinado</span>
            <span x-show="bloqueada" x-cloak class="text-xs text-verde-600">🔒 bloqueada</span>
            {{-- Bloqueio pós-assinatura (só aparece quando há algo a proteger): com o cadeado
                 fechado, nem o traço nem o Limpar funcionam — anti-riscos acidentais. Bloquear
                 um traço novo também GRAVA o rascunho (a assinatura sobrevive a um refresh). --}}
            <button type="button" x-show="temTraco || @js((bool) $guardada)" x-cloak @click="alternarBloqueio()"
                class="text-xs font-medium text-texto-medio hover:text-texto"
                x-text="bloqueada ? 'Desbloquear' : 'Bloquear'"></button>
            <button type="button" x-show="!bloqueada" @click="limpar()" class="text-xs font-medium text-texto-medio hover:text-perigo-600">Limpar</button>
        </div>
    </div>

    {{-- Assinatura já gravada: mostra-a até se desenhar por cima. --}}
    @if ($guardada)
        <img x-show="!temTraco" src="{{ $guardada }}" alt="{{ $rotulo }}" class="mb-2 h-28 w-full rounded border border-borda bg-white object-contain">
    @endif

    <canvas x-ref="pad" x-show="temTraco || !@js((bool) $guardada)"
        :class="bloqueada ? 'border-solid' : 'border-dashed'"
        class="h-28 w-full touch-none rounded border border-borda bg-white"
        aria-label="{{ $rotulo }} — desenhe aqui"></canvas>

    <input type="text" wire:model="{{ $prefixo }}.assinatura_{{ $quem }}_nome" class="campo-input mt-2 text-sm" placeholder="Nome de quem assina">
</div>
