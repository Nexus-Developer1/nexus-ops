<div>
    <x-topbar :breadcrumb="['Despesas', $registoId ? 'Editar registo' : 'Novo registo']">
        @if ($registoId)
            <a href="{{ route('despesas.registo.pdf', $registoId) }}" class="botao-secundario">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17v3a2 2 0 002 2h14a2 2 0 002-2v-3"/></svg>
                Transferir PDF
            </a>
        @endif
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $registoId ? 'Editar registo de despesas' : 'Registo de despesas' }}</h1>
            <p class="mt-2 text-sm text-texto-medio">No formato da folha da empresa — uma linha por dia/deslocação; cada coluna com valor conta nessa categoria. O registo aparece na listagem como uma só entrada.</p>

            <form wire:submit="guardar" class="cartao mt-8 p-6 sm:p-8">
                {{-- ===== Cabeçalho da folha (como na folha impressa) ===== --}}
                <div class="overflow-hidden rounded-lg border border-borda">
                    <table class="w-full text-sm">
                        <tr class="border-b border-borda">
                            <td class="w-44 bg-fundo px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-texto-medio">Nome colaborador</td>
                            <td class="px-4 py-2.5 font-medium text-texto-forte">{{ auth()->user()->nome }}</td>
                            <td class="w-36 border-l border-borda bg-fundo px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-texto-medio">Matrícula</td>
                            <td class="px-2 py-1.5"><input wire:model="matricula" type="text" class="campo-input w-full px-2 py-1.5 text-sm" placeholder="Ex: BD-71-VI"></td>
                        </tr>
                        <tr>
                            <td class="bg-fundo px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-texto-medio">Departamento</td>
                            <td class="px-2 py-1.5" colspan="3"><input wire:model="departamento" type="text" class="campo-input w-full px-2 py-1.5 text-sm sm:max-w-sm" placeholder="Ex: Infraestruturas"></td>
                        </tr>
                    </table>
                </div>

                {{-- ===== Grelha da folha: linhas de despesa (data + descrição + colunas) ===== --}}
                <div class="mt-5 overflow-x-auto rounded-lg border border-borda">
                    <table class="w-full min-w-[1080px] text-sm">
                        <thead>
                            <tr class="bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                                <th rowspan="2" class="w-36 border-b border-r border-borda px-3 py-2 text-left font-semibold">Dia</th>
                                <th rowspan="2" class="border-b border-r border-borda px-3 py-2 text-left font-semibold">Descrição<br><span class="font-normal normal-case text-texto-fraco">(cliente - localidade)</span></th>
                                <th colspan="2" class="border-b border-r border-borda px-3 py-2 text-center font-semibold">Veículos da empresa</th>
                                <th rowspan="2" class="border-b border-r border-borda px-3 py-2 text-center font-semibold">Hotel</th>
                                <th rowspan="2" class="border-b border-r border-borda px-3 py-2 text-center font-semibold">Refeições <span class="normal-case">a)</span></th>
                                <th rowspan="2" class="border-b border-r border-borda px-3 py-2 text-center font-semibold">Táxi · Comboio<br>Avião, etc</th>
                                <th rowspan="2" class="border-b border-r border-borda px-3 py-2 text-center font-semibold">Outras <span class="normal-case">b)</span></th>
                                <th rowspan="2" class="w-10 border-b border-borda"></th>
                            </tr>
                            <tr class="bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                                <th class="border-b border-r border-borda px-3 py-1.5 text-center font-semibold">Combustíveis</th>
                                <th class="border-b border-r border-borda px-3 py-1.5 text-center font-semibold">Outros</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($linhas as $n => $linha)
                                <tr wire:key="linha-{{ $n }}" class="border-b border-borda/60">
                                    <td class="border-r border-borda px-1.5 py-2 align-top">
                                        <input wire:model="linhas.{{ $n }}.data" type="date" class="campo-input w-full px-2 py-1.5 text-sm">
                                    </td>
                                    <td class="border-r border-borda px-1.5 py-2 align-top">
                                        <input wire:model="linhas.{{ $n }}.descricao" type="text" class="campo-input w-full min-w-[12rem] px-2 py-1.5 text-sm" placeholder="Ex: ACME - Porto">
                                    </td>
                                    @foreach (\App\Models\Despesa::CATEGORIAS as $i => $c)
                                        <td class="px-1.5 py-2 align-top {{ $i < count(\App\Models\Despesa::CATEGORIAS) - 1 ? 'border-r border-borda' : '' }}">
                                            <input wire:model.live.debounce.500ms="linhas.{{ $n }}.valores.{{ $i }}" type="number" step="0.01" min="0" inputmode="decimal" class="campo-input w-full min-w-[5.5rem] px-2 py-1.5 text-right text-sm" placeholder="0,00">
                                            @if ($c === 'Refeições')
                                                {{-- Nota a) em funcionamento: A (almoço) / J (jantar), obrigatório com valor. --}}
                                                <select wire:model="linhas.{{ $n }}.refeicao_tipo" class="campo-select mt-1 w-full px-2 py-1 text-xs" title="A — almoço · J — jantar">
                                                    <option value="">A / J?</option>
                                                    <option value="A">A — almoço</option>
                                                    <option value="J">J — jantar</option>
                                                </select>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-1 py-2 text-center align-top">
                                        @if (count($linhas) > 1)
                                            <button type="button" wire:click="removerLinha({{ $n }})" class="mt-1.5 text-texto-fraco hover:text-perigo-600" title="Remover linha">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-borda bg-fundo text-sm font-semibold text-texto-forte">
                                <td colspan="2" class="border-r border-borda px-3 py-2 text-right uppercase tracking-wide">Euros</td>
                                @foreach (\App\Models\Despesa::CATEGORIAS as $i => $c)
                                    <td class="px-3 py-2 text-right {{ $i < count(\App\Models\Despesa::CATEGORIAS) - 1 ? 'border-r border-borda' : '' }}">
                                        {{ $totais[$i] > 0 ? number_format($totais[$i], 2, ',', ' ') . ' €' : '' }}
                                    </td>
                                @endforeach
                                <td></td>
                            </tr>
                            <tr class="border-t border-borda">
                                <td colspan="7" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-texto-medio">Total despesas</td>
                                <td class="px-3 py-2 text-right text-sm font-semibold text-texto-forte">{{ number_format($total, 2, ',', ' ') }} €</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-3 flex items-center justify-between">
                    <button type="button" wire:click="adicionarLinha" class="botao-secundario">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                        Linha
                    </button>
                    <p class="text-xs text-texto-fraco">
                        a) Indicar: A - almoço · J - jantar (com outros colaboradores, indicar na descrição o respetivo nome.)<br>
                        b) Especificar na descrição.
                    </p>
                </div>

                @error('linhas') <p class="mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror
                @foreach ($linhas as $n => $linha)
                    @error("linhas.$n.data") <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    @error("linhas.$n.descricao") <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    @error("linhas.$n.refeicao_tipo") <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    @foreach (\App\Models\Despesa::CATEGORIAS as $i => $c)
                        @error("linhas.$n.valores.$i") <p class="mt-1.5 text-xs text-perigo-500">Linha {{ $n + 1 }}, {{ $c }}: {{ $message }}</p> @enderror
                    @endforeach
                @endforeach

                {{-- Recibos: digitalizar com a câmara (filtro de documento), tirar foto ou galeria.
                     Pendentes gravam-se COM o registo (funciona na criação); na edição os
                     gravados aparecem com remover. --}}
                <div class="mt-6" x-data="scannerRecibo">
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
                        <p class="mt-1.5 text-xs text-texto-fraco">Os recibos com contorno verde são gravados ao guardar o registo.</p>
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

                <div class="mt-8 flex items-center justify-end gap-3 border-t border-borda pt-6">
                    <a href="{{ route('despesas') }}" wire:navigate class="botao-secundario">Cancelar</a>
                    <button type="submit" class="botao-primario">{{ $registoId ? 'Guardar alterações' : 'Guardar registo' }}</button>
                </div>
            </form>
        </div>
    </main>
</div>
