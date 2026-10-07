<div>
    <x-topbar :breadcrumb="['Início', 'Clientes', $cliente->nome, 'Caderno']">
        <a href="{{ route('clientes.detalhe', $cliente) }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-screen-2xl">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Caderno</h1>
                    <p class="mt-1 text-sm text-texto-medio">{{ $cliente->nome }}</p>
                </div>

                {{-- Pesquisa no caderno inteiro (título e texto de todas as páginas). --}}
                @if ($separadores->isNotEmpty())
                <div class="relative w-full sm:w-80" x-data="{ aberta: false }" @click.outside="aberta = false">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/></svg>
                    <input wire:model.live.debounce.300ms="pesquisa" type="search" placeholder="Pesquisar no caderno…" @focus="aberta = true" @input="aberta = true"
                        class="campo-input py-2 pl-9 text-sm">
                    @if (mb_strlen(trim($pesquisa)) >= 2)
                        <div x-show="aberta" x-cloak class="absolute right-0 top-full z-30 mt-1 max-h-96 w-full overflow-y-auto rounded-lg border border-borda bg-white p-1.5 shadow-lg">
                            <p class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-texto-fraco">{{ $resultados->count() }} {{ $resultados->count() === 1 ? 'resultado' : 'resultados' }}</p>
                            @foreach ($resultados as $r)
                                <button type="button" wire:key="res-{{ $r->id }}" @click="aberta = false; window.cadernoMudar(() => { $wire.set('pesquisa', ''); $wire.selecionarPagina({{ $r->id }}) })"
                                    class="flex w-full items-start gap-2 rounded-md px-2.5 py-2 text-left text-sm hover:bg-fundo">
                                    @if ($r->separador)
                                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $r->separador->cores()[1] }};"></span>
                                    @endif
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-texto-forte">{{ $r->titulo }}</span>
                                        <span class="block truncate text-xs text-texto-fraco">{{ $r->separador?->nome }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                @endif
            </div>

            @if ($separadores->isNotEmpty())
            {{-- SEPARADORES (como os do OneNote): um por cliente final, cor à escolha; arrastar
                 uma aba para o sítio de outra muda a ordem (no telemóvel: menu ⋯ → mover). --}}
            <div class="mt-6 flex items-end gap-1 overflow-x-auto" data-lista
                x-data="{ arrastado: null, sobre: null, ids(el) { return [...el.closest('[data-lista]').querySelectorAll(':scope > [data-id]')].map((e) => Number(e.dataset.id)) } }">
                @foreach ($separadores as $s)
                    @php([$fundo, $tinta] = $s->cores())
                    @php($ativo = $s->id === $separadorId)
                    {{-- Uma aba só (nome + ⋯): a cor, os cantos e a barra de cima são do conjunto. --}}
                    <div wire:key="sep-{{ $s->id }}" data-id="{{ $s->id }}"
                        class="relative flex shrink-0 items-stretch rounded-t-lg border-t-[3px] transition {{ $ativo ? '' : 'opacity-75 hover:opacity-100' }}"
                        style="background-color: {{ $fundo }}; color: {{ $tinta }}; border-top-color: {{ $ativo ? $tinta : $fundo }};"
                        :class="sobre === {{ $s->id }} && arrastado !== {{ $s->id }} && 'caderno-alvo'"
                        x-data="{ menu: false, x: 0, y: 0 }"
                        x-on:dragover.prevent="if (arrastado) sobre = {{ $s->id }}"
                        x-on:dragleave="if (sobre === {{ $s->id }}) sobre = null"
                        x-on:drop.prevent="if (arrastado && arrastado !== {{ $s->id }}) { $wire.reordenarSeparadores(window.reordenar(ids($el), arrastado, {{ $s->id }})) } arrastado = null; sobre = null">
                        <button type="button" draggable="true" title="Arraste para mudar a ordem"
                            x-on:dragstart="arrastado = {{ $s->id }}; $event.dataTransfer.effectAllowed = 'move'"
                            x-on:dragend="arrastado = null; sobre = null"
                            @click="window.cadernoMudar(() => $wire.selecionarSeparador({{ $s->id }}))"
                            class="flex items-center gap-2 text-sm font-semibold {{ $ativo ? 'pb-2.5 pl-4 pr-2 pt-2' : 'px-4 py-1.5' }}">
                            {{ $s->nome }}
                            <span class="rounded-full bg-white/70 px-1.5 text-[11px] font-medium">{{ $s->paginas_count }}</span>
                        </button>
                        @if ($ativo)
                            {{-- O menu é «fixed»: a barra das abas tem scroll horizontal e cortava um menu absoluto. --}}
                            <button type="button" @click="const r = $el.getBoundingClientRect(); x = Math.min(r.left, window.innerWidth - 240); y = r.bottom + 4; menu = !menu"
                                class="my-1 mr-1 rounded-md px-1.5 text-sm hover:bg-white/60" aria-label="Opções do separador">⋯</button>
                            <div x-show="menu" x-cloak @click.outside="menu = false" @scroll.window="menu = false" @resize.window="menu = false" @keydown.escape.window="menu = false"
                                :style="`left: ${x}px; top: ${y}px`" class="fixed z-40 w-60 rounded-lg border border-borda bg-white p-3 shadow-lg">
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
                                <div class="mt-3 flex gap-2 border-t border-borda pt-3 text-sm">
                                    <button type="button" wire:click="moverSeparador({{ $s->id }}, -1)" @click="menu = false" class="flex-1 rounded-md px-2 py-1 font-medium text-texto-medio hover:bg-fundo" @disabled($loop->first)>← Esquerda</button>
                                    <button type="button" wire:click="moverSeparador({{ $s->id }}, 1)" @click="menu = false" class="flex-1 rounded-md px-2 py-1 font-medium text-texto-medio hover:bg-fundo" @disabled($loop->last)>Direita →</button>
                                </div>
                                <button type="button" wire:click="apagarSeparador({{ $s->id }})"
                                    wire:confirm="Apagar o separador «{{ $s->nome }}» e as {{ $s->paginas_count }} página(s) dele?"
                                    class="mt-2 text-sm font-medium text-perigo-500 hover:text-perigo-600">Apagar separador</button>
                            </div>
                        @endif
                    </div>
                @endforeach

                {{-- wire:key com o nº de separadores: depois de criar um, a caixa volta fechada. --}}
                <div wire:key="novo-sep-{{ $separadores->count() }}" x-data="{ aberto: false }" class="flex shrink-0 items-center pb-1 pl-1">
                    <button type="button" x-show="!aberto" @click="aberto = true; $nextTick(() => $refs.nome.focus())" class="rounded-lg px-3 py-1.5 text-sm font-medium text-verde-600 hover:bg-verde-50">+ Separador</button>
                    <form x-show="aberto" x-cloak wire:submit="criarSeparador" class="flex items-center gap-2">
                        <input x-ref="nome" wire:model="novoSeparador" type="text" maxlength="120" placeholder="Nome (ex.: o cliente final)" class="campo-input w-56 py-1.5 text-sm">
                        <button type="submit" class="botao-primario px-3 py-1.5 text-sm">Criar</button>
                    </form>
                </div>
            </div>
            @error('novoSeparador') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
            @endif

            @if ($separador)
                @php([$fundoAtivo, $tintaAtiva] = $separador->cores())
                {{-- O separador «continua» na página, como no OneNote: a lista de páginas com a cor
                     dele e uma barra da mesma cor por cima da folha. --}}
                <div class="grid grid-cols-1 overflow-hidden rounded-b-xl rounded-tr-xl border-t-[3px] shadow-sm lg:grid-cols-[18rem,minmax(0,1fr)]"
                    style="border-top-color: {{ $tintaAtiva }};">

                    {{-- PÁGINAS (e subpáginas) do separador --}}
                    <aside class="p-3 lg:min-h-[70vh]" style="background-color: {{ $fundoAtivo }};">
                        <div class="mb-2 flex items-center justify-between gap-2 px-1">
                            <span class="truncate text-xs font-semibold uppercase tracking-wide" style="color: {{ $tintaAtiva }};">{{ $separador->nome }}</span>
                            <button type="button" @click="window.cadernoMudar(() => $wire.criarPagina())"
                                class="shrink-0 rounded-md bg-white/80 px-2.5 py-1 text-xs font-semibold shadow-sm hover:bg-white" style="color: {{ $tintaAtiva }};">+ Página</button>
                        </div>
                        @if ($arvore === [])
                            <p class="px-2 py-6 text-center text-sm text-texto-medio">Ainda não há páginas.</p>
                        @endif
                        <ul class="space-y-0.5" data-lista
                            x-data="{ arrastado: null, sobre: null, ids(el) { return [...el.closest('[data-lista]').querySelectorAll(':scope > [data-id]')].map((e) => Number(e.dataset.id)) } }">
                            @foreach ($arvore as $no)
                                @include('livewire.clientes.caderno-pagina-item', ['p' => $no['pagina'], 'filhas' => $no['filhas'], 'sub' => false, 'ativa' => $paginaId, 'tinta' => $tintaAtiva])
                            @endforeach
                        </ul>
                        <p class="mt-3 hidden px-2 text-[11px] text-texto-fraco lg:block">Arraste para mudar a ordem.</p>
                    </aside>

                    {{-- A FOLHA: a página aberta --}}
                    <section class="min-w-0 bg-white px-4 py-5 sm:px-8 sm:py-7">
                        @if ($pagina)
                            <div class="flex items-start gap-2">
                                <input wire:model.blur="titulo" type="text" maxlength="200" aria-label="Título da página"
                                    class="w-full border-0 border-b border-transparent bg-transparent px-0 pb-1 text-3xl font-semibold tracking-tight text-texto-forte focus:border-borda focus:outline-none focus:ring-0">

                                {{-- Menu da página: ligação, ordem, subpágina, mover, apagar. --}}
                                <div class="shrink-0" x-data="{ menu: false, x: 0, y: 0, copiada: false }">
                                    <button type="button" @click="const r = $el.getBoundingClientRect(); x = Math.max(8, r.right - 256); y = r.bottom + 4; menu = !menu"
                                        class="rounded-lg p-2 text-texto-fraco hover:bg-fundo hover:text-texto-forte" aria-label="Opções da página" title="Opções da página">
                                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                                    </button>
                                    <div x-show="menu" x-cloak @click.outside="menu = false" @scroll.window="menu = false" @resize.window="menu = false" @keydown.escape.window="menu = false"
                                        :style="`left: ${x}px; top: ${y}px`" class="fixed z-40 w-64 rounded-lg border border-borda bg-white p-1.5 text-sm shadow-lg">
                                        <button type="button" class="caderno-menu-item"
                                            @click="navigator.clipboard.writeText(@js(route('clientes.caderno', ['cliente' => $cliente, 's' => $pagina->separador_id, 'p' => $pagina->id]))).then(() => { copiada = true; setTimeout(() => { copiada = false; menu = false }, 900) })">
                                            <span x-text="copiada ? 'Ligação copiada ✓' : 'Copiar ligação da página'"></span>
                                        </button>
                                        <div class="my-1 border-t border-borda"></div>
                                        <button type="button" wire:click="moverPagina({{ $pagina->id }}, -1)" @click="menu = false" class="caderno-menu-item" @disabled($posicao['primeira'])>↑ Subir</button>
                                        <button type="button" wire:click="moverPagina({{ $pagina->id }}, 1)" @click="menu = false" class="caderno-menu-item" @disabled($posicao['ultima'])>↓ Descer</button>
                                        @if ($posicao['subpagina'])
                                            <button type="button" wire:click="promoverPagina({{ $pagina->id }})" @click="menu = false" class="caderno-menu-item">← Passar a página</button>
                                        @else
                                            <button type="button" @click="menu = false; window.cadernoMudar(() => $wire.criarPagina({{ $pagina->id }}))" class="caderno-menu-item">+ Subpágina</button>
                                            <button type="button" wire:click="tornarSubpagina({{ $pagina->id }})" @click="menu = false" class="caderno-menu-item"
                                                @disabled($posicao['primeira'] || $posicao['temFilhas'])>→ Tornar subpágina da de cima</button>
                                        @endif
                                        @if ($separadores->count() > 1)
                                            <div class="my-1 border-t border-borda"></div>
                                            <p class="px-2.5 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-texto-fraco">Mover para</p>
                                            @foreach ($separadores as $destino)
                                                @if ($destino->id !== $pagina->separador_id)
                                                    <button type="button" wire:key="mover-{{ $destino->id }}" @click="menu = false; window.cadernoMudar(() => $wire.moverParaSeparador({{ $pagina->id }}, {{ $destino->id }}))" class="caderno-menu-item">
                                                        <span class="mr-2 inline-block h-2.5 w-2.5 rounded-full" style="background-color: {{ $destino->cores()[1] }};"></span>{{ $destino->nome }}
                                                    </button>
                                                @endif
                                            @endforeach
                                        @endif
                                        <div class="my-1 border-t border-borda"></div>
                                        <button type="button" wire:click="apagarPagina({{ $pagina->id }})" @click="menu = false"
                                            wire:confirm="Apagar a página «{{ $pagina->titulo }}»?{{ $posicao['temFilhas'] ? ' As subpáginas ficam, como páginas.' : '' }}"
                                            class="caderno-menu-item !text-perigo-500">Apagar página</button>
                                    </div>
                                </div>
                            </div>

                            {{-- Equipamento de que a página trata (opcional) — a ficha dele mostra esta página. --}}
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-texto-fraco">
                                <span>Alterada {{ $pagina->updated_at->diffForHumans() }}{{ $pagina->autorAlteracao ? ' por '.$pagina->autorAlteracao->nome : '' }}</span>
                                <span class="flex min-w-0 max-w-full items-center gap-1.5">
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>
                                    <label for="pag-equip" class="sr-only">Equipamento</label>
                                    <select id="pag-equip" wire:key="pag-equip-{{ $pagina->id }}"
                                        x-on:change="$wire.ligarEquipamento({{ $pagina->id }}, $event.target.value ? Number($event.target.value) : null)"
                                        class="min-w-0 max-w-full rounded-md border border-borda bg-white py-1 pl-2 pr-7 text-xs text-texto-medio focus:border-verde-500 focus:ring-0 sm:max-w-xs">
                                        <option value="">Sem equipamento</option>
                                        @foreach ($equipamentos as $e)
                                            <option value="{{ $e->id }}" @selected($pagina->equipamento_id === $e->id)>
                                                {{ ($e->cliente_final ? $e->cliente_final.' · ' : '').$e->rotuloCaderno() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @if ($pagina->equipamento)
                                        <a href="{{ route('equipamentos.ficha', $pagina->equipamento) }}" wire:navigate class="shrink-0 font-medium text-verde-600 hover:text-verde-500">Abrir ficha</a>
                                    @endif
                                </span>
                            </div>

                            <div wire:ignore wire:key="editor-{{ $pagina->id }}" x-data="cadernoEditor({{ $pagina->id }}, {{ $pagina->versao }})" class="mt-4">
                                <input id="caderno-conteudo-{{ $pagina->id }}" type="hidden" value="{{ $pagina->conteudo }}">

                                {{-- BARRA do editor (fica presa no topo ao descer na página). --}}
                                <div class="caderno-barra" role="toolbar" aria-label="Formatação">
                                    <button type="button" class="caderno-botao" @click="acao('undo')" :disabled="!ativo.desfazer" title="Desfazer (Ctrl+Z)">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                                    </button>
                                    <button type="button" class="caderno-botao" @click="acao('redo')" :disabled="!ativo.refazer" title="Refazer (Ctrl+Y)">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l6-6m0 0l-6-6m6 6H9a6 6 0 000 12h3"/></svg>
                                    </button>
                                    <span class="caderno-divisor"></span>
                                    <select class="caderno-estilo" x-model="bloco" @change="mudarBloco($event.target.value)" aria-label="Estilo do texto">
                                        <option value="p">Texto</option>
                                        <option value="1">Título 1</option>
                                        <option value="2">Título 2</option>
                                        <option value="3">Título 3</option>
                                    </select>
                                    <span class="caderno-divisor"></span>
                                    <button type="button" class="caderno-botao font-bold" :class="ativo.bold && 'is-ativo'" @click="acao('toggleBold')" title="Negrito (Ctrl+B)">B</button>
                                    <button type="button" class="caderno-botao italic" :class="ativo.italic && 'is-ativo'" @click="acao('toggleItalic')" title="Itálico (Ctrl+I)">I</button>
                                    <button type="button" class="caderno-botao underline" :class="ativo.underline && 'is-ativo'" @click="acao('toggleUnderline')" title="Sublinhado (Ctrl+U)">S</button>
                                    <button type="button" class="caderno-botao line-through" :class="ativo.strike && 'is-ativo'" @click="acao('toggleStrike')" title="Riscado">R</button>

                                    {{-- Cor do texto e realce --}}
                                    <div class="relative">
                                        <button type="button" class="caderno-botao" @click="painel = painel === 'cor' ? null : 'cor'" title="Cor do texto">
                                            <span class="flex flex-col items-center leading-none"><span class="text-sm font-semibold">A</span><span class="mt-0.5 h-1 w-4 rounded-sm" :style="`background-color: ${ativo.cor || '#111827'}`"></span></span>
                                        </button>
                                        <div x-show="painel === 'cor'" x-cloak @click.outside="painel = null" class="caderno-paleta">
                                            <template x-for="[nome, cor] in coresTexto" :key="nome">
                                                <button type="button" @click="corTexto(cor)" :title="nome" class="h-6 w-6 rounded-full border border-borda" :style="`background-color: ${cor || '#111827'}`"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="relative">
                                        <button type="button" class="caderno-botao" :class="ativo.highlight && 'is-ativo'" @click="painel = painel === 'realce' ? null : 'realce'" title="Realçar">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536M9 11l6.232-6.232a2.5 2.5 0 113.536 3.536L12.536 14.5H9V11zM4 20h16"/></svg>
                                        </button>
                                        <div x-show="painel === 'realce'" x-cloak @click.outside="painel = null" class="caderno-paleta">
                                            <template x-for="[nome, cor] in coresRealce" :key="nome">
                                                <button type="button" @click="realce(cor)" :title="nome" class="h-6 w-6 rounded-full border border-borda" :style="`background-color: ${cor}`"></button>
                                            </template>
                                            <button type="button" @click="realce(null)" title="Sem realce" class="flex h-6 w-6 items-center justify-center rounded-full border border-borda text-xs text-texto-fraco">✕</button>
                                        </div>
                                    </div>
                                    <span class="caderno-divisor"></span>

                                    <button type="button" class="caderno-botao" :class="ativo.bulletList && 'is-ativo'" @click="acao('toggleBulletList')" title="Lista">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1" fill="currentColor"/><circle cx="4.5" cy="12" r="1" fill="currentColor"/><circle cx="4.5" cy="18" r="1" fill="currentColor"/></svg>
                                    </button>
                                    <button type="button" class="caderno-botao" :class="ativo.orderedList && 'is-ativo'" @click="acao('toggleOrderedList')" title="Lista numerada">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M10 6h10M10 12h10M10 18h10"/><text x="2" y="8" font-size="7" fill="currentColor" stroke="none">1</text><text x="2" y="14" font-size="7" fill="currentColor" stroke="none">2</text><text x="2" y="20" font-size="7" fill="currentColor" stroke="none">3</text></svg>
                                    </button>
                                    <button type="button" class="caderno-botao" :class="ativo.taskList && 'is-ativo'" @click="acao('toggleTaskList')" title="Lista de tarefas">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="6" height="6" rx="1"/><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 7l1 1 2-2M13 7h8M3 15h6v6H3zM13 18h8"/></svg>
                                    </button>
                                    <button type="button" class="caderno-botao" :class="ativo.tabela && 'is-ativo'" @click="inserirTabela()" title="Inserir tabela">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="1.5"/><path d="M3 10h18M3 15h18M9 4v16M15 4v16"/></svg>
                                    </button>
                                    <span class="caderno-divisor"></span>
                                    <button type="button" class="caderno-botao" :class="ativo.blockquote && 'is-ativo'" @click="acao('toggleBlockquote')" title="Citação">
                                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M7.5 6C5 6 3 8 3 10.5S5 15 7.5 15c.3 0 .5 0 .8-.1-.6 1.4-2 2.6-3.8 3.1l.5 1.5C8 18.6 10.5 15.8 10.5 12V10.5C10.5 8 9.5 6 7.5 6zm10 0C15 6 13 8 13 10.5s2 4.5 4.5 4.5c.3 0 .5 0 .8-.1-.6 1.4-2 2.6-3.8 3.1l.5 1.5c3-.4 5.5-3.2 5.5-7V10.5C20.5 8 19.5 6 17.5 6z"/></svg>
                                    </button>
                                    <button type="button" class="caderno-botao" :class="ativo.codeBlock && 'is-ativo'" @click="acao('toggleCodeBlock')" title="Bloco de código (configurações, comandos)">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                                    </button>
                                    <button type="button" class="caderno-botao" @click="acao('setHorizontalRule')" title="Linha separadora">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M3 12h18"/></svg>
                                    </button>
                                    <div class="relative">
                                        <button type="button" class="caderno-botao" :class="ativo.link && 'is-ativo'" @click="abrirLigacao()" title="Ligação">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                        </button>
                                        <div x-show="painel === 'ligacao'" x-cloak @click.outside="painel = null" class="caderno-paleta w-72">
                                            <input x-ref="campoLigacao" x-model="ligacao" @keydown.enter.prevent="aplicarLigacao()" @keydown.escape="painel = null" type="text" placeholder="https://… ou email" class="campo-input py-1.5 text-sm">
                                            <div class="mt-2 flex justify-end gap-3 text-sm">
                                                <button type="button" @click="ligacao = ''; aplicarLigacao()" class="text-texto-medio hover:text-perigo-500">Tirar</button>
                                                <button type="button" @click="aplicarLigacao()" class="font-medium text-verde-600 hover:text-verde-500">Aplicar</button>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="caderno-botao" @click="escolherFicheiros()" title="Anexar imagem ou ficheiro (PDF, manual…)">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    </button>
                                    <input x-ref="escolher" type="file" multiple class="hidden" @change="ficheirosEscolhidos($event)"
                                        accept="{{ collect(explode(',', $tiposAnexo))->map(fn ($t) => '.'.$t)->implode(',') }}">
                                    <button type="button" class="caderno-botao" @click="acao('unsetAllMarks'); acao('clearNodes')" title="Limpar formatação">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h12M10 4l-3 16M14 4l-1.5 7M4 20l16-16"/></svg>
                                    </button>

                                    {{-- Ferramentas da tabela: só com o cursor dentro de uma. --}}
                                    <template x-if="ativo.tabela">
                                        <div class="flex w-full flex-wrap items-center gap-1 border-t border-borda pt-1.5 text-xs">
                                            <span class="mr-1 font-semibold text-texto-fraco">Tabela:</span>
                                            <button type="button" class="caderno-botao-texto" @click="acao('addRowAfter')">+ Linha</button>
                                            <button type="button" class="caderno-botao-texto" @click="acao('addColumnAfter')">+ Coluna</button>
                                            <button type="button" class="caderno-botao-texto" @click="acao('deleteRow')">− Linha</button>
                                            <button type="button" class="caderno-botao-texto" @click="acao('deleteColumn')">− Coluna</button>
                                            <button type="button" class="caderno-botao-texto" @click="acao('toggleHeaderRow')">Cabeçalho</button>
                                            <button type="button" class="caderno-botao-texto" @click="acao('mergeOrSplit')">Juntar/separar células</button>
                                            <button type="button" class="caderno-botao-texto !text-perigo-500" @click="acao('deleteTable')">Apagar tabela</button>
                                        </div>
                                    </template>
                                </div>

                                <div x-ref="lugar" class="mt-3"></div>

                                {{-- Estado: guardado / a guardar / a enviar ficheiros / erro / conflito. --}}
                                <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-borda pt-3 text-xs text-texto-fraco">
                                    <span x-text="estado" :class="conflito && 'font-medium text-perigo-500'"></span>
                                    <span x-show="aEnviar > 0" x-cloak x-text="`A enviar ${aEnviar} ${aEnviar === 1 ? 'ficheiro' : 'ficheiros'}… ${progresso}%`" class="font-medium text-verde-700"></span>
                                </div>
                                <p x-show="erro" x-cloak x-text="erro" class="mt-2 text-sm text-perigo-500"></p>
                                <div x-show="conflito" x-cloak class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" @click="copiarTexto()" class="botao-secundario px-3 py-1.5 text-sm">Copiar o que escrevi</button>
                                    <button type="button" @click="window.location.reload()" class="botao-primario px-3 py-1.5 text-sm">Recarregar a página</button>
                                </div>
                            </div>
                        @else
                            <div class="flex h-full min-h-[40vh] flex-col items-center justify-center text-center">
                                <svg class="h-10 w-10 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <p class="mt-3 text-sm text-texto-medio">Este separador ainda não tem páginas.</p>
                                <button type="button" wire:click="criarPagina" class="botao-primario mt-4">+ Página</button>
                            </div>
                        @endif
                    </section>
                </div>
            @elseif ($separadores->isEmpty())
                {{-- CADERNO VAZIO: o primeiro separador — escrito, ou com um clique num cliente final
                     dos equipamentos (ou num dos habituais, se não houver clientes finais). --}}
                <div class="cartao mt-6 overflow-hidden">
                    {{-- Abas de enfeite: dá logo a ideia de caderno com separadores. --}}
                    <div class="flex items-end gap-1 border-b-[3px] border-verde-600 bg-fundo/60 px-6 pt-4" aria-hidden="true">
                        @foreach (array_slice($cores, 0, 4) as [$f, $t])
                            <span class="h-6 rounded-t-md {{ $loop->first ? 'w-24' : 'w-16 opacity-70' }}" style="background-color: {{ $loop->first ? $t : $f }};"></span>
                        @endforeach
                    </div>
                    <div class="mx-auto flex max-w-xl flex-col items-center px-6 py-12 text-center">
                        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-verde-50 text-verde-600">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        </span>
                        <h2 class="mt-4 text-xl font-semibold text-texto-forte">Ainda não há separadores</h2>
                        <p class="mt-2 text-sm leading-relaxed text-texto-medio">
                            Um separador por cliente final ou por tema; dentro de cada um, páginas com notas, tabelas, fotos e manuais deste cliente.
                        </p>

                        <form wire:submit="criarSeparador" class="mt-6 flex w-full flex-col gap-2 sm:flex-row">
                            <input wire:model="novoSeparador" type="text" maxlength="120" placeholder="Nome do primeiro separador" aria-label="Nome do separador"
                                class="campo-input flex-1 py-2.5 text-sm" autofocus>
                            <button type="submit" class="botao-primario justify-center whitespace-nowrap px-5 py-2.5 text-sm">Criar separador</button>
                        </form>
                        @error('novoSeparador') <p class="mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror

                        <div class="mt-6 w-full border-t border-borda pt-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">{{ $sugestoes !== [] ? 'Clientes finais deste cliente' : 'Ou comece por' }}</p>
                            <div class="mt-3 flex flex-wrap justify-center gap-2">
                                @foreach ($sugestoes !== [] ? $sugestoes : ['Geral', 'Acessos e contactos', 'Equipamentos', 'Rede / CCTV'] as $nome)
                                    @php($corChip = array_values($cores)[$loop->index % count($cores)])
                                    <button type="button" wire:click="criarSeparador(@js($nome))" wire:key="sug-{{ md5($nome) }}"
                                        class="rounded-full border px-3.5 py-1.5 text-sm font-medium transition hover:shadow-sm"
                                        style="background-color: {{ $corChip[0] }}; color: {{ $corChip[1] }}; border-color: {{ $corChip[0] }};">+ {{ $nome }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Atalhos: os clientes finais dos equipamentos deste cliente que ainda não têm separador. --}}
            @if ($sugestoes !== [] && $separadores->isNotEmpty())
                <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-texto-fraco">Criar separador para:</span>
                    @foreach ($sugestoes as $nome)
                        <button type="button" wire:click="criarSeparador(@js($nome))" class="rounded-full border border-borda bg-white px-3 py-1 text-xs font-medium text-texto-medio hover:bg-fundo" wire:key="sug-{{ md5($nome) }}">+ {{ $nome }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </main>
</div>
