{{-- Ampliação de fotografias (pedido da equipa, set. 2026): clicar numa miniatura abre-a
     grande por cima da página, sem sair do sítio onde se estava — no editor de relatórios
     as fotos são recortadas em quadrado e não se via o que lá estava.

     Vive no layout: qualquer página dispara `ver-foto` com { src, legenda } e esta camada
     trata do resto. Fecha com Escape, com o × ou clicando fora da imagem. --}}
<div x-data="{ aberta: false, src: '', legenda: '' }"
     x-on:ver-foto.window="src = $event.detail.src; legenda = $event.detail.legenda || ''; aberta = true"
     x-on:keydown.escape.window="aberta = false"
     x-show="aberta" x-cloak x-transition.opacity
     @click.self="aberta = false"
     class="fixed inset-0 z-[70] flex flex-col items-center justify-center gap-3 bg-black/80 p-4"
     role="dialog" aria-modal="true">

    <button type="button" @click="aberta = false" aria-label="Fechar"
            class="absolute right-4 top-4 flex h-11 w-11 items-center justify-center rounded-lg bg-black/60 text-white transition hover:bg-black/80">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>

    {{-- object-contain: a foto aparece inteira, seja qual for a orientação. --}}
    <img :src="src" :alt="legenda" @click.stop
         class="max-h-[85vh] max-w-full rounded-lg object-contain shadow-2xl">

    <p x-show="legenda" x-text="legenda" class="max-w-full truncate text-sm text-white/80"></p>
</div>
