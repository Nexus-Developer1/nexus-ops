<div>
    <x-topbar :breadcrumb="['Despesas', $despesaId ? 'Editar' : 'Nova']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $despesaId ? 'Editar despesa' : 'Registo de despesas' }}</h1>
            <p class="mt-2 text-sm text-texto-medio">No formato da folha da empresa — preenche as colunas que se aplicam; cada coluna com valor regista uma despesa dessa categoria.</p>

            <form wire:submit="guardar" class="cartao mt-8 p-6 sm:p-8">
                {{-- ===== Cabeçalho da folha (como na folha impressa) ===== --}}
                <div class="overflow-hidden rounded-lg border border-borda">
                    <table class="w-full text-sm">
                        <tr class="border-b border-borda">
                            <td class="w-40 bg-fundo px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-texto-medio">Nome colaborador</td>
                            <td class="px-4 py-2.5 font-medium text-texto-forte">{{ auth()->user()->nome }}</td>
                            <td class="w-32 border-l border-borda bg-fundo px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-texto-medio">Matrícula</td>
                            <td class="px-2 py-1.5"><input wire:model="matricula" type="text" class="campo-input px-2 py-1.5 text-sm" placeholder="Ex: BD-71-VI"></td>
                        </tr>
                        <tr>
                            <td class="bg-fundo px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-texto-medio">Departamento</td>
                            <td class="px-2 py-1.5"><input wire:model="departamento" type="text" class="campo-input px-2 py-1.5 text-sm" placeholder="Ex: Infraestruturas"></td>
                            <td class="border-l border-borda bg-fundo px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-texto-medio">Data <span class="text-perigo-500">*</span></td>
                            <td class="px-2 py-1.5"><input wire:model="data" type="date" class="campo-input px-2 py-1.5 text-sm"></td>
                        </tr>
                    </table>
                </div>
                @error('data') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror

                {{-- ===== Grelha da folha: descrição + colunas de valores (uma linha) ===== --}}
                <div class="mt-5 overflow-x-auto rounded-lg border border-borda">
                    <table class="w-full min-w-[880px] text-sm">
                        <thead>
                            <tr class="bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                                <th rowspan="2" class="border-b border-r border-borda px-3 py-2 text-left font-semibold">Descrição<br><span class="font-normal normal-case text-texto-fraco">(cliente - localidade)</span></th>
                                <th colspan="2" class="border-b border-r border-borda px-3 py-2 text-center font-semibold">Veículos da empresa</th>
                                <th rowspan="2" class="border-b border-r border-borda px-3 py-2 text-center font-semibold">Hotel</th>
                                <th rowspan="2" class="border-b border-r border-borda px-3 py-2 text-center font-semibold">Refeições <span class="normal-case">a)</span></th>
                                <th rowspan="2" class="border-b border-r border-borda px-3 py-2 text-center font-semibold">Táxi · Comboio<br>Avião, etc</th>
                                <th rowspan="2" class="border-b border-borda px-3 py-2 text-center font-semibold">Outras <span class="normal-case">b)</span></th>
                            </tr>
                            <tr class="bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                                <th class="border-b border-r border-borda px-3 py-1.5 text-center font-semibold">Combustíveis</th>
                                <th class="border-b border-r border-borda px-3 py-1.5 text-center font-semibold">Outros</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="border-r border-borda px-2 py-2">
                                    <input wire:model="descricao" type="text" class="campo-input w-full min-w-[14rem] px-2 py-1.5 text-sm" placeholder="Ex: ACME - Porto">
                                </td>
                                @foreach (\App\Models\Despesa::CATEGORIAS as $i => $c)
                                    <td class="px-1.5 py-2 {{ $i < count(\App\Models\Despesa::CATEGORIAS) - 1 ? 'border-r border-borda' : '' }}">
                                        <input wire:model.live.debounce.500ms="valores.{{ $i }}" type="number" step="0.01" min="0" inputmode="decimal" class="campo-input w-24 px-2 py-1.5 text-right text-sm" placeholder="0,00">
                                    </td>
                                @endforeach
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-borda bg-fundo text-sm font-semibold text-texto-forte">
                                <td class="border-r border-borda px-3 py-2 text-right uppercase tracking-wide">Euros</td>
                                @foreach (\App\Models\Despesa::CATEGORIAS as $i => $c)
                                    <td class="px-3 py-2 text-right {{ $i < count(\App\Models\Despesa::CATEGORIAS) - 1 ? 'border-r border-borda' : '' }}">
                                        {{ is_numeric($valores[$i] ?? '') ? number_format((float) $valores[$i], 2, ',', ' ') . ' €' : '' }}
                                    </td>
                                @endforeach
                            </tr>
                            <tr class="border-t border-borda">
                                <td colspan="6" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-texto-medio">Total despesas</td>
                                <td class="px-3 py-2 text-right text-sm font-semibold text-texto-forte">{{ number_format($total, 2, ',', ' ') }} €</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @error('descricao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                @error('valores') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                @foreach (\App\Models\Despesa::CATEGORIAS as $i => $c)
                    @error("valores.$i") <p class="mt-1.5 text-xs text-perigo-500">{{ $c }}: {{ $message }}</p> @enderror
                @endforeach
                <p class="mt-2 text-xs text-texto-fraco">
                    a) Indicar: A - almoço · J - jantar (sempre que incluir refeições com outros colaboradores, indicar na descrição o respetivo nome.)<br>
                    b) Especificar na descrição.
                </p>

                <div class="mt-6 grid grid-cols-1 gap-x-8 gap-y-6 sm:grid-cols-2">
                    {{-- Intervenção (opcional): pesquisa GLOBAL. Ao associar, herda cliente/equipamento/contrato. --}}
                    <div class="sm:col-span-2">
                        <label class="campo-label" for="intervencao-combo">Intervenção (opcional)</label>
                        @if ($intervencao_id)
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-verde-300 bg-verde-50 px-4 py-3">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-verde-800">{{ $intervencaoRotulo }}</span>
                                    <span class="block text-xs text-verde-600">Cliente, equipamento e contrato herdados desta intervenção.</span>
                                </span>
                                <button type="button" wire:click="limparIntervencao" class="shrink-0 text-xs font-medium text-texto-fraco hover:text-perigo-600">Remover</button>
                            </div>
                        @else
                            <div x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                <input id="intervencao-combo" type="text"
                                    wire:model.live.debounce.300ms="intervencaoBusca"
                                    @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                    @keydown.arrow-down.prevent="aberto = true; if ($refs['iv' + (destaque + 1)]) destaque++"
                                    @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                    @keydown.enter.prevent="$refs['iv' + destaque]?.click()"
                                    class="campo-input pr-10" placeholder="Pesquisar por nº de relatório, nº de série ou cliente..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    @forelse ($intervencoesFiltradas as $idx => $iv)
                                        <li x-ref="iv{{ $idx }}" wire:key="iv-{{ $iv->id }}"
                                            wire:click="selecionarIntervencao({{ $iv->id }})" @click="aberto = false"
                                            @mouseenter="destaque = {{ $idx }}"
                                            :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                            class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            <span class="font-medium">{{ $iv->relatorio?->numero ?? ('Intervenção #' . $iv->id) }}</span>
                                            <span class="text-xs text-texto-fraco"> · {{ $iv->equipamento?->numero_serie ?? '—' }} · {{ $iv->equipamento?->local?->cliente?->nome ?? '—' }} · {{ $iv->data_inicio?->format('d/m/Y') ?? '—' }}</span>
                                        </li>
                                    @empty
                                        <li class="px-4 py-2 text-sm text-texto-medio">{{ trim($intervencaoBusca) === '' ? 'Escreva para pesquisar…' : 'Nenhuma intervenção encontrada.' }}</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endif
                        <p class="mt-1.5 text-xs text-texto-fraco">Liga a despesa a uma intervenção — cliente, equipamento e contrato são preenchidos automaticamente.</p>
                        @error('intervencao_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Cliente: pesquisa server-side (opcional). Bloqueado quando herdado da intervenção. --}}
                    <div>
                        <label class="campo-label" for="cliente-combo">Cliente (opcional)</label>
                        @if ($intervencao_id)
                            <input type="text" value="{{ $clienteBusca ?: '—' }}" disabled class="campo-input bg-fundo text-texto-medio">
                            <p class="mt-1.5 text-xs text-texto-fraco">Herdado da intervenção.</p>
                        @else
                            <div x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                <input id="cliente-combo" type="text"
                                    wire:model.live.debounce.300ms="clienteBusca"
                                    @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                    @keydown.arrow-down.prevent="aberto = true; if ($refs['o' + (destaque + 1)]) destaque++"
                                    @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                    @keydown.enter.prevent="$refs['o' + destaque]?.click()"
                                    class="campo-input pr-10" placeholder="Pesquisar por nome ou NIF..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    @forelse ($clientesFiltrados as $idx => $cl)
                                        <li x-ref="o{{ $idx }}" wire:key="cl-{{ $cl->id }}"
                                            wire:click="selecionarCliente({{ $cl->id }})" @click="aberto = false"
                                            @mouseenter="destaque = {{ $idx }}"
                                            :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                            class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            <span class="font-medium">{{ $cl->nome }}</span>
                                            <span class="text-xs text-texto-fraco"> · NIF {{ $cl->nif ?? '—' }}</span>
                                        </li>
                                    @empty
                                        <li class="px-4 py-2 text-sm text-texto-medio">{{ $clienteBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum cliente encontrado.' }}</li>
                                    @endforelse
                                </ul>
                            </div>
                            @if ($cliente_id)
                                <button type="button" wire:click="limparCliente" class="mt-1.5 text-xs font-medium text-texto-fraco hover:text-perigo-600">Remover cliente</button>
                            @endif
                        @endif
                        @error('cliente_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Recibos: digitalizar com a câmara (filtro de documento), tirar foto ou galeria.
                         Pendentes gravam-se COM a despesa (funciona na criação); na edição os
                         gravados aparecem com remover. --}}
                    <div class="sm:col-span-2" x-data="scannerRecibo">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label class="campo-label mb-0">Recibos</label>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="abrir()" class="botao-secundario">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0a4 4 0 11-8 0 4 4 0 018 0zM4 16H2m2-5.5L2.5 9M20 10.5L21.5 9M7 4h10l1 3H6l1-3z"/></svg>
                                    Digitalizar
                                </button>
                                <label class="botao-secundario cursor-pointer">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    Tirar foto
                                    <input type="file" wire:model="recibosUpload" accept="image/*" capture="environment" class="hidden">
                                </label>
                                <label class="botao-secundario cursor-pointer">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    Galeria
                                    <input type="file" wire:model="recibosUpload" accept="image/*" multiple class="hidden">
                                </label>
                            </div>
                        </div>
                        <div wire:loading wire:target="recibosUpload,reciboDigitalizado" class="mt-2 text-sm text-texto-medio">A carregar recibo…</div>
                        @error('recibosUpload.*') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        @error('reciboDigitalizado') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror

                        @if (count($recibos) || $recibosGravados->isNotEmpty())
                            <div class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-6">
                                @foreach ($recibosGravados as $recibo)
                                    <div class="group relative" wire:key="recibo-g-{{ $recibo->id }}">
                                        <a href="{{ route('anexos.ver', $recibo) }}" target="_blank">
                                            <img src="{{ route('anexos.ver', $recibo) }}" alt="{{ $recibo->nome_ficheiro }}" class="h-24 w-full rounded-lg border border-borda object-cover">
                                        </a>
                                        <button type="button" wire:click="removerReciboGravado({{ $recibo->id }})" wire:confirm="Remover este recibo?"
                                            class="absolute -right-2 -top-2 hidden h-6 w-6 items-center justify-center rounded-full bg-perigo-600 text-white shadow group-hover:flex" title="Remover">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                @endforeach
                                @foreach ($recibos as $i => $pendente)
                                    <div class="group relative" wire:key="recibo-p-{{ $i }}">
                                        <img src="{{ $pendente->temporaryUrl() }}" alt="Recibo pendente" class="h-24 w-full rounded-lg border border-verde-300 object-cover">
                                        <button type="button" wire:click="removerReciboPendente({{ $i }})"
                                            class="absolute -right-2 -top-2 hidden h-6 w-6 items-center justify-center rounded-full bg-perigo-600 text-white shadow group-hover:flex" title="Remover">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                            <p class="mt-1.5 text-xs text-texto-fraco">Os recibos com contorno verde são gravados ao registar a despesa.</p>
                        @endif

                        {{-- Modal do scanner: câmara em direto → capturar → filtro de documento → usar/repetir. --}}
                        <div x-show="aberto" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                            <div class="w-full max-w-lg rounded-xl bg-white p-4 shadow-xl">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-base font-semibold text-texto-forte">Digitalizar recibo</h3>
                                    <button type="button" @click="fechar()" class="text-texto-fraco hover:text-texto-forte">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                <p x-show="erro" x-text="erro" class="mt-2 text-sm text-perigo-500"></p>
                                <div class="mt-3 overflow-hidden rounded-lg bg-black">
                                    <video x-ref="video" x-show="!capturado" playsinline muted class="max-h-[60vh] w-full object-contain"></video>
                                    <canvas x-ref="tela" x-show="capturado" class="max-h-[60vh] w-full object-contain"></canvas>
                                </div>
                                <div class="mt-4 flex items-center justify-end gap-2">
                                    <button type="button" x-show="!capturado" @click="capturar()" class="botao-primario">Capturar</button>
                                    <button type="button" x-show="capturado" @click="repetir()" class="botao-secundario">Repetir</button>
                                    <button type="button" x-show="capturado" @click="usar()" class="botao-primario">Usar digitalização</button>
                                </div>
                                <p class="mt-2 text-xs text-texto-fraco">Enquadra o recibo e captura — é aplicado um filtro de documento (preto e branco, alto contraste).</p>
                            </div>
                        </div>
                    </div>

                    {{-- Faturável vs incluído no contrato. --}}
                    <div class="sm:col-span-2">
                        <label class="flex items-start gap-3 rounded-lg border border-borda px-4 py-3">
                            <input wire:model="faturavel" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                            <span>
                                <span class="block text-sm font-medium text-texto-forte">Faturável à parte</span>
                                <span class="block text-xs text-texto-fraco">Se desligado, conta como incluído no contrato (não faturado ao cliente).</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="mt-8 flex items-center justify-end gap-3 border-t border-borda pt-6">
                    <a href="{{ route('despesas') }}" wire:navigate class="botao-secundario">Cancelar</a>
                    <button type="submit" class="botao-primario">{{ $despesaId ? 'Guardar alterações' : 'Registar despesa' }}</button>
                </div>
            </form>
        </div>
    </main>
</div>
