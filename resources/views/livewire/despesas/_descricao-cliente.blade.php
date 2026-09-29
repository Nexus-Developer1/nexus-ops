{{-- DESCRIÇÃO de uma linha = o CLIENTE (pedido da equipa, set. 2026): escreve-se e aparecem os
     clientes (nome ou NIF, pesquisa no servidor com 2+ letras). Escolher um põe o nome no campo;
     continua a aceitar texto livre (ex.: «Interno», ou o nome + a terra).
     A lista é `fixed` e posiciona-se pelo campo: a tabela do desktop está numa caixa com
     deslizar horizontal, que cortava uma lista posta por baixo do campo da maneira normal. --}}
<div class="relative"
    x-data="{
        aberto: false, opcoes: [], destaque: 0, pos: {}, espera: null,
        procurar(texto) {
            clearTimeout(this.espera);
            if (texto.trim().length < 2) { this.opcoes = []; this.aberto = false; return; }
            this.espera = setTimeout(async () => {
                this.opcoes = await $wire.procurarClientes(texto);
                this.destaque = 0;
                this.posicionar();
                this.aberto = this.opcoes.length > 0;
            }, 250);
        },
        posicionar() {
            const r = this.$refs.campo.getBoundingClientRect();
            this.pos = { top: (r.bottom + 4) + 'px', left: r.left + 'px', width: Math.max(r.width, 288) + 'px' };
        },
        escolher(nome) {
            this.$refs.campo.value = nome;
            $wire.set(@js('linhas.'.$n.'.descricao'), nome, false);
            this.aberto = false;
        },
    }"
    @click.outside="aberto = false" @scroll.window="aberto && posicionar()" @resize.window="aberto = false">
    <input x-ref="campo" wire:model="linhas.{{ $n }}.descricao" type="text" autocomplete="off"
        @input="procurar($event.target.value)"
        @keydown.arrow-down.prevent="if (aberto && destaque < opcoes.length - 1) destaque++"
        @keydown.arrow-up.prevent="if (aberto && destaque > 0) destaque--"
        @keydown.enter="if (aberto && opcoes[destaque]) { $event.preventDefault(); escolher(opcoes[destaque].nome) }"
        @keydown.escape="aberto = false"
        role="combobox" aria-autocomplete="list" :aria-expanded="aberto"
        class="{{ $classes }}" placeholder="Pesquisar cliente…">
    <ul x-show="aberto" x-cloak :style="pos" role="listbox"
        class="fixed z-50 max-h-60 overflow-auto rounded-lg border border-borda bg-white py-1 text-left shadow-lg">
        <template x-for="(c, i) in opcoes" :key="c.id">
            <li @mousedown.prevent="escolher(c.nome)" @mouseenter="destaque = i" role="option"
                :class="destaque === i ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                class="cursor-pointer px-3 py-2 text-sm">
                <span x-text="c.nome"></span><span x-show="c.nif" class="text-texto-fraco" x-text="' · ' + c.nif"></span>
            </li>
        </template>
    </ul>
</div>
