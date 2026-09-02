{{-- UI de escolha de equipamentos por faixa (auto/lista/pesquisa), partilhada pelos modos
     individual (fonte = cliente) e contrato (fonte = contrato). A fonte é decidida no componente
     (equipamentosCandidatos()); aqui só muda o texto conforme o modo. --}}
@php($ehContrato = $modo === 'contrato')
@php($rotuloFonte = $ehContrato ? 'contrato' : 'cliente')
@php($fonteEscolhida = $ehContrato ? $contrato_id : $cliente_id)

{{-- Faixas ≤10 ('auto') e 11-50 ('lista'): lista de checkboxes. Marcar/desmarcar anexa/remove a
     aba em tempo real. Na 'auto' vem tudo marcado à partida, mas a lista fica — um relatório nascido
     da agenda com UM equipamento (ou de que se tirou um) precisa de poder acrescentar os outros do
     mesmo cliente. São no máx. 50 linhas; as fichas só montam para os marcados (wire:key estáveis). --}}
@if (in_array($faixaEquipamentos, ['auto', 'lista'], true))
    <div class="sm:col-span-2">
        @php($todosMarcados = $equipamentosLista->isNotEmpty() && $equipamentosLista->every(fn ($e) => in_array($e->id, $anexadosIds, true)))
        <div class="mb-2 flex items-center justify-between">
            <label class="campo-label mb-0">Equipamentos do {{ $rotuloFonte }}</label>
            <button type="button" wire:click="{{ $todosMarcados ? 'limparEquipamentos' : 'selecionarTodosEquipamentos' }}" class="text-xs font-medium text-verde-700 hover:text-verde-800">
                {{ $todosMarcados ? 'Limpar seleção' : 'Selecionar todos' }}
            </button>
        </div>
        <div class="max-h-72 space-y-0.5 overflow-auto rounded-lg border border-borda p-2">
            @foreach ($equipamentosLista as $e)
                <label wire:key="chk-{{ $e->id }}" class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 hover:bg-fundo">
                    <input type="checkbox" wire:click="alternarEquipamento({{ $e->id }})" @checked(in_array($e->id, $anexadosIds, true)) class="h-4 w-4 shrink-0 rounded border-borda text-verde-600 focus:ring-verde-500">
                    <span class="min-w-0 text-sm">
                        <span class="block truncate">
                            <span class="font-medium text-texto-forte">{{ $e->numero_serie ?? '—' }}</span>
                            <span class="text-texto-fraco"> · {{ trim($e->fabricante . ' ' . $e->modelo) ?: '—' }}</span>
                        </span>
                        {{-- ONDE está instalado (morada real — distingue equipamentos iguais em sítios diferentes). --}}
                        <span class="flex items-center gap-1 text-xs text-texto-medio">
                            <svg class="h-3 w-3 shrink-0 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span class="truncate">{{ $e->localInstalacao() }}</span>
                        </span>
                    </span>
                </label>
            @endforeach
        </div>
        <p class="mt-1.5 text-xs text-texto-fraco">
            @if ($faixaEquipamentos === 'auto')
                Marca ou desmarca os equipamentos deste {{ $rotuloFonte }} que entram no relatório — cada um vira uma aba com ficha em cima.
            @else
                Marca os equipamentos a intervencionar — cada um vira uma aba com ficha em cima.
            @endif
        </p>
        @error('equipamento_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
    </div>

{{-- Faixa >50: pesquisa server-side (o mecanismo que corrigiu o 500). Uma lista de centenas/milhares
     não se renderiza; anexa-se um a um. Filtrada à fonte (cliente ou contrato). --}}
@elseif ($faixaEquipamentos === 'pesquisa')
    <div class="sm:col-span-2">
        <label class="campo-label" for="equip-selecao-combo">Adicionar equipamento</label>
        <div wire:key="combo-equip-selecao" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
            <input id="equip-selecao-combo" type="text"
                wire:model.live.debounce.300ms="equipamentoBusca"
                @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                @keydown.arrow-down.prevent="aberto = true; if ($refs['ec' + (destaque + 1)]) destaque++"
                @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                @keydown.enter.prevent="$refs['ec' + destaque]?.click()"
                class="campo-input pr-10" placeholder="Pesquisar por nº de série ou modelo..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
            <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                @forelse ($equipamentosFiltrados as $idx => $e)
                    <li x-ref="ec{{ $idx }}" wire:key="ecl-{{ $e->id }}"
                        wire:click="adicionarEquipamento({{ $e->id }})" @click="aberto = false"
                        @mouseenter="destaque = {{ $idx }}"
                        :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                        class="cursor-pointer px-4 py-2 text-sm" role="option">
                        <span class="font-medium">{{ $e->numero_serie ?? '—' }}</span>
                        <span class="text-xs text-texto-fraco"> · {{ trim($e->fabricante . ' ' . $e->modelo) ?: '—' }}</span>
                        <span class="block truncate text-xs text-texto-medio">{{ $e->localInstalacao() }}</span>
                    </li>
                @empty
                    <li class="px-4 py-2 text-sm text-texto-medio">{{ $equipamentoBusca === '' ? 'Escreva o nº de série para pesquisar…' : 'Nenhum equipamento encontrado.' }}</li>
                @endforelse
            </ul>
        </div>
        <p class="mt-1.5 text-xs text-texto-fraco">Este {{ $rotuloFonte }} tem muitos equipamentos — pesquisa e adiciona só os que vais medir nesta visita (não são anexados todos automaticamente).</p>
    </div>
@endif

{{-- Equipamentos do relatório: separadores no topo (ao lado de "Dados Gerais"); clicar num abre a
     ficha. Na faixa 'lista' a seleção é pelos checkboxes acima, por isso este bloco não se mostra aí. --}}
@if ($faixaEquipamentos !== 'lista')
    <div class="sm:col-span-2">
        <label class="campo-label">Equipamentos do relatório</label>
        @if ($equipamentoPrincipal || $cobertosSelecionados->isNotEmpty())
            <div class="flex flex-wrap gap-2">
                @if ($equipamentoPrincipal)
                    <button type="button" wire:key="chip-{{ $equipamentoPrincipal->id }}" @click="tab='equip-{{ $equipamentoPrincipal->id }}'" title="{{ $equipamentoPrincipal->localInstalacao() }}" class="inline-flex items-center gap-1.5 rounded-full border border-verde-200 bg-verde-50 px-3 py-1 text-xs font-medium text-verde-700 hover:bg-verde-100 transition">
                        {{ $equipamentoPrincipal->numero_serie ?? '—' }}
                        @if ($local = $equipamentoPrincipal->localInstalacao())
                            <span class="font-normal text-verde-600">· {{ \Illuminate\Support\Str::limit($local, 45) }}</span>
                        @endif
                    </button>
                @endif
                @foreach ($cobertosSelecionados as $e)
                    <button type="button" wire:key="chip-{{ $e->id }}" @click="tab='equip-{{ $e->id }}'" title="{{ $e->localInstalacao() }}" class="inline-flex items-center gap-1.5 rounded-full border border-borda bg-fundo px-3 py-1 text-xs text-texto-forte hover:border-verde-300 hover:text-verde-700 transition">
                        {{ $e->numero_serie ?? '—' }}
                        @if ($local = $e->localInstalacao())
                            <span class="font-normal text-texto-fraco">· {{ \Illuminate\Support\Str::limit($local, 45) }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
            <p class="mt-1.5 text-xs text-texto-fraco">Clica num equipamento (aqui ou no separador em cima) para preencher a sua ficha. Podes remover os que não interessam dentro de cada ficha.</p>
        @elseif ($faixaEquipamentos === 'pesquisa')
            <p class="text-sm text-texto-medio">Pesquisa em cima e adiciona os equipamentos que vais medir nesta visita.</p>
        @elseif ($fonteEscolhida)
            <p class="text-sm text-texto-medio">Este {{ $rotuloFonte }} não tem equipamentos {{ $ehContrato ? 'cobertos' : 'associados' }}.</p>
        @endif
        @error('equipamento_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
    </div>
@endif
