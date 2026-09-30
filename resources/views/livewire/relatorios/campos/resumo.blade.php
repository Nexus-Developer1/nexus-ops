{{-- Resumo da intervenção (as constatações técnicas). Era um cartão à parte («Constatações
     Técnicas»); passou a ser um dos campos reordenáveis do cartão «Equipamento e Intervenção»
     (pedido da equipa, set. 2026). Cresce com o texto (auto-resize), sem scroll interno. --}}
<label class="campo-label" for="resumo-intervencao">Resumo da intervenção</label>
<textarea id="resumo-intervencao" wire:model="resumo" rows="3"
    x-data="{ ajustar() { if (! this.$el.scrollHeight) return; this.$el.style.height = 'auto'; this.$el.style.height = this.$el.scrollHeight + 'px'; } }"
    x-init="ajustar()" @input="ajustar()"
    class="campo-input resize-none overflow-hidden" placeholder="Descreva as constatações técnicas observadas durante a intervenção…"></textarea>
