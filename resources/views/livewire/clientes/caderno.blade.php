<div>
    <x-topbar :breadcrumb="['Início', 'Clientes', $cliente->nome, 'Caderno']">
        <a href="{{ route('clientes.detalhe', $cliente) }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-screen-2xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Caderno</h1>

            {{-- SEPARADORES (como os do OneNote): um por cliente final, cor à escolha; arrastar
                 uma aba para o sítio de outra muda a ordem. --}}
            <div class="mt-6 flex items-end gap-1 overflow-x-auto border-b-2 border-borda"
                data-lista x-data="{ arrastado: null, sobre: null, ids(el) { return [...el.closest('[data-lista]').querySelectorAll(':scope > [data-id]')].map((e) => Number(e.dataset.id)) } }">
                @foreach ($separadores as $s)
                    @php([$fundo, $tinta] = $s->cores())
                    @php($ativo = $s->id === $separadorId)
                    <div wire:key="sep-{{ $s->id }}" data-id="{{ $s->id }}" class="relative flex shrink-0 items-stretch rounded-t-lg"
                        :class="sobre === {{ $s->id }} && arrastado !== {{ $s->id }} && 'ring-2 ring-verde-500'"
                        x-data="{ menu: false, x: 0, y: 0 }"
                        x-on:dragover.prevent="if (arrastado) sobre = {{ $s->id }}"
                        x-on:dragleave="if (sobre === {{ $s->id }}) sobre = null"
                        x-on:drop.prevent="if (arrastado && arrastado !== {{ $s->id }}) { $wire.reordenarSeparadores(window.reordenar(ids($el), arrastado, {{ $s->id }})) } arrastado = null; sobre = null">
                        <button type="button" draggable="true" title="Arraste para mudar a ordem"
                            x-on:dragstart="arrastado = {{ $s->id }}; $event.dataTransfer.effectAllowed = 'move'"
                            x-on:dragend="arrastado = null; sobre = null"
                            @click="window.cadernoMudar(() => $wire.selecionarSeparador({{ $s->id }}))"
                            class="flex items-center gap-2 rounded-t-lg px-4 text-sm font-medium transition {{ $ativo ? 'py-2.5 shadow-sm' : 'py-2 opacity-80 hover:opacity-100' }}"
                            style="background-color: {{ $fundo }}; color: {{ $tinta }};">
                            {{ $s->nome }}
                            <span class="text-xs opacity-60">{{ $s->paginas_count }}</span>
                        </button>
                        @if ($ativo)
                            {{-- O menu é «fixed»: a barra das abas tem scroll horizontal e cortava um menu absoluto. --}}
                            <button type="button" @click="const r = $el.getBoundingClientRect(); x = Math.min(r.left, window.innerWidth - 240); y = r.bottom + 4; menu = !menu" class="rounded-tr-lg px-1.5 text-sm" style="background-color: {{ $fundo }}; color: {{ $tinta }};" aria-label="Opções do separador">⋯</button>
                            <div x-show="menu" x-cloak @click.outside="menu = false" @scroll.window="menu = false" @resize.window="menu = false" @keydown.escape.window="menu = false"
                                :style="`left: ${x}px; top: ${y}px`" class="fixed z-40 w-56 rounded-lg border border-borda bg-white p-3 shadow-lg">
                                <div x-data="{ nome: @js($s->nome) }">
                                    <label class="campo-label" for="ren-{{ $s->id }}">Nome</label>
                                    <input id="ren-{{ $s->id }}" x-model="nome" @keydown.enter.prevent="$wire.renomearSeparador({{ $s->id }}, nome); menu = false" class="campo-input py-1.5 text-sm" maxlength="120">
                                    <button type="button" @click="$wire.renomearSeparador({{ $s->id }}, nome); menu = false" class="mt-2 text-sm font-medium text-verde-600 hover:text-verde-500">Mudar o nome</button>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    @foreach ($cores as $chave => [$f, $t])
                                        <button type="button" wire:click="mudarCor({{ $s->id }}, '{{ $chave }}')" @click="menu = false"
                                            class="h-6 w-6 rounded-full border {{ $s->cor === $chave ? 'ring-2 ring-offset-1' : '' }}"
                                            style="background-color: {{ $f }}; border-color: {{ $t }};" title="{{ ucfirst($chave) }}"></button>
                                    @endforeach
                                </div>
                                <button type="button" wire:click="apagarSeparador({{ $s->id }})"
                                    wire:confirm="Apagar o separador «{{ $s->nome }}» e as {{ $s->paginas_count }} página(s) dele?"
                                    class="mt-3 text-sm font-medium text-perigo-500 hover:text-perigo-600">Apagar separador</button>
                            </div>
                        @endif
                    </div>
                @endforeach

                {{-- wire:key com o nº de separadores: depois de criar um, a caixa volta fechada. --}}
                <div wire:key="novo-sep-{{ $separadores->count() }}" x-data="{ aberto: @js($separadores->isEmpty()) }" class="flex shrink-0 items-center pb-1 pl-1">
                    <button type="button" x-show="!aberto" @click="aberto = true; $nextTick(() => $refs.nome.focus())" class="rounded-lg px-3 py-1.5 text-sm font-medium text-verde-600 hover:bg-verde-50">+ Separador</button>
                    <form x-show="aberto" x-cloak wire:submit="criarSeparador" class="flex items-center gap-2">
                        <input x-ref="nome" wire:model="novoSeparador" type="text" maxlength="120" placeholder="Nome (ex.: o cliente final)" class="campo-input w-56 py-1.5 text-sm">
                        <button type="submit" class="botao-primario px-3 py-1.5 text-sm">Criar</button>
                    </form>
                </div>
            </div>
            @error('novoSeparador') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror

            {{-- Atalhos: os clientes finais dos equipamentos deste cliente que ainda não têm separador. --}}
            @if ($sugestoes !== [])
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-texto-fraco">Clientes finais:</span>
                    @foreach ($sugestoes as $nome)
                        <button type="button" wire:click="criarSeparador(@js($nome))" class="rounded-full border border-borda px-3 py-1 text-xs font-medium text-texto-medio hover:bg-fundo" wire:key="sug-{{ md5($nome) }}">+ {{ $nome }}</button>
                    @endforeach
                </div>
            @endif

            @if ($separador)
                <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr),18rem]">

                    {{-- PÁGINA aberta --}}
                    <section class="cartao order-2 p-5 sm:p-6 lg:order-1">
                        @if ($pagina)
                            <div class="flex items-start gap-3">
                                <input wire:model.blur="titulo" type="text" maxlength="200" aria-label="Título da página"
                                    class="w-full border-0 bg-transparent p-0 text-2xl font-semibold text-texto-forte focus:outline-none focus:ring-0">
                                <button type="button" wire:click="apagarPagina({{ $pagina->id }})" wire:confirm="Apagar a página «{{ $pagina->titulo }}»?"
                                    class="shrink-0 rounded-lg p-2 text-texto-fraco hover:bg-fundo hover:text-perigo-500" title="Apagar página" aria-label="Apagar página">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>

                            {{-- Equipamento de que a página trata (opcional) — a ficha dele mostra esta página. --}}
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                                <label for="pag-equip" class="text-texto-fraco">Equipamento</label>
                                <select id="pag-equip" wire:key="pag-equip-{{ $pagina->id }}"
                                    x-on:change="$wire.ligarEquipamento({{ $pagina->id }}, $event.target.value ? Number($event.target.value) : null)"
                                    class="campo-input w-auto max-w-md py-1 text-sm">
                                    <option value="">—</option>
                                    @foreach ($equipamentos as $e)
                                        <option value="{{ $e->id }}" @selected($pagina->equipamento_id === $e->id)>
                                            {{ trim(($e->cliente_final ? $e->cliente_final.' · ' : '').$e->fabricante.' '.$e->modelo) ?: 'Equipamento' }}{{ $e->numero_serie ? ' · S/N '.$e->numero_serie : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @if ($pagina->equipamento)
                                    <a href="{{ route('equipamentos.ficha', $pagina->equipamento) }}" wire:navigate class="font-medium text-verde-600 hover:text-verde-500">Abrir ficha</a>
                                @endif
                            </div>

                            <div wire:ignore wire:key="editor-{{ $pagina->id }}" x-data="cadernoEditor({{ $pagina->id }}, {{ $pagina->versao }})" class="mt-1">
                                <p class="mb-3 text-xs text-texto-fraco">
                                    Alterada {{ $pagina->updated_at->diffForHumans() }}{{ $pagina->autorAlteracao ? ' por '.$pagina->autorAlteracao->nome : '' }}
                                    · <span x-text="estado" :class="conflito && 'font-medium text-perigo-500'"></span>
                                </p>
                                <input id="caderno-conteudo-{{ $pagina->id }}" type="hidden" value="{{ $pagina->conteudo }}">
                                {{-- O <trix-editor> é criado pelo JS (caderno.js) depois de a barra estar em português. --}}
                                <div x-ref="lugar"></div>
                                <p x-show="erro" x-cloak x-text="erro" class="mt-2 text-sm text-perigo-500"></p>
                                <div x-show="ficheiros.length" x-cloak class="mt-4 border-t border-borda pt-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Ficheiros nesta página</p>
                                    <ul class="mt-2 space-y-1">
                                        <template x-for="f in ficheiros" :key="f.href">
                                            <li>
                                                <a :href="f.href" target="_blank" rel="noopener" class="text-sm font-medium text-verde-600 hover:text-verde-500">
                                                    <span x-text="f.nome"></span>
                                                </a>
                                                <span class="text-xs text-texto-fraco" x-text="f.tamanho"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </div>
                        @else
                            <div class="py-16 text-center">
                                <p class="text-sm text-texto-medio">Este separador ainda não tem páginas.</p>
                                <button type="button" wire:click="criarPagina" class="botao-primario mt-4">+ Página</button>
                            </div>
                        @endif
                    </section>

                    {{-- PÁGINAS do separador + pesquisa no caderno inteiro --}}
                    <aside class="cartao order-1 self-start p-4 lg:order-2">
                        <input wire:model.live.debounce.300ms="pesquisa" type="search" placeholder="Pesquisar no caderno…" class="campo-input py-2 text-sm">

                        @if (mb_strlen(trim($pesquisa)) >= 2)
                            <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-texto-fraco">{{ $resultados->count() }} {{ $resultados->count() === 1 ? 'resultado' : 'resultados' }}</p>
                            <ul class="mt-2 space-y-1">
                                @foreach ($resultados as $r)
                                    <li wire:key="res-{{ $r->id }}">
                                        <button type="button" @click="window.cadernoMudar(() => { $wire.set('pesquisa', ''); $wire.selecionarPagina({{ $r->id }}) })" class="w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-fundo">
                                            <span class="block font-medium text-texto-forte">{{ $r->titulo }}</span>
                                            <span class="block text-xs text-texto-fraco">{{ $r->separador?->nome }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <button type="button" @click="window.cadernoMudar(() => $wire.criarPagina())" class="mt-3 w-full rounded-lg border border-dashed border-borda px-3 py-2 text-sm font-medium text-verde-600 hover:bg-verde-50">+ Página</button>
                            {{-- Arrastar uma página para o sítio de outra muda a ordem. --}}
                            <ul class="mt-2 space-y-0.5" data-lista x-data="{ arrastado: null, sobre: null, ids(el) { return [...el.closest('[data-lista]').querySelectorAll(':scope > [data-id]')].map((e) => Number(e.dataset.id)) } }">
                                @foreach ($paginas as $p)
                                    <li wire:key="pag-{{ $p->id }}" data-id="{{ $p->id }}" draggable="true" class="rounded-lg"
                                        :class="sobre === {{ $p->id }} && arrastado !== {{ $p->id }} && 'ring-2 ring-verde-500'"
                                        x-on:dragstart="arrastado = {{ $p->id }}; $event.dataTransfer.effectAllowed = 'move'"
                                        x-on:dragend="arrastado = null; sobre = null"
                                        x-on:dragover.prevent="if (arrastado) sobre = {{ $p->id }}"
                                        x-on:dragleave="if (sobre === {{ $p->id }}) sobre = null"
                                        x-on:drop.prevent="if (arrastado && arrastado !== {{ $p->id }}) { $wire.reordenarPaginas(window.reordenar(ids($el), arrastado, {{ $p->id }})) } arrastado = null; sobre = null">
                                        <button type="button" @click="window.cadernoMudar(() => $wire.selecionarPagina({{ $p->id }}))"
                                            class="w-full rounded-lg px-3 py-2 text-left text-sm transition {{ $p->id === $paginaId ? 'bg-verde-50 font-semibold text-verde-700' : 'text-texto-forte hover:bg-fundo' }}">
                                            {{ $p->titulo }}
                                            <span class="block text-xs font-normal text-texto-fraco">{{ $p->updated_at->format('d/m/Y H:i') }}{{ $p->equipamento_id ? ' · equipamento' : '' }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </aside>
                </div>
            @elseif ($separadores->isEmpty())
                <div class="cartao mt-5 py-16 text-center">
                    <p class="text-sm text-texto-medio">Ainda não há separadores.</p>
                </div>
            @endif
        </div>
    </main>
</div>
