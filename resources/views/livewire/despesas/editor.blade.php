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
            <p class="mt-2 text-sm text-texto-medio">Uma linha por despesa: dia, onde (cliente - localidade), o que é, tipo, valor — e os recibos anexados à própria linha.</p>

            <form wire:submit="guardar" class="cartao mt-8 p-6 sm:p-8" x-data="scannerRecibo">
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

                {{-- ===== Linhas: dia (à mão) · descrição · o que é · tipo · valor · recibos ===== --}}
                <div class="mt-5 overflow-x-auto rounded-lg border border-borda">
                    <table class="w-full min-w-[1080px] text-sm">
                        <thead>
                            <tr class="bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                                <th class="w-28 border-b border-r border-borda px-3 py-2 text-left font-semibold">Dia</th>
                                <th class="border-b border-r border-borda px-3 py-2 text-left font-semibold">Descrição<br><span class="font-normal normal-case text-texto-fraco">(cliente - localidade)</span></th>
                                <th class="border-b border-r border-borda px-3 py-2 text-left font-semibold">O que é</th>
                                <th class="w-44 border-b border-r border-borda px-3 py-2 text-left font-semibold">Tipo</th>
                                <th class="w-28 border-b border-r border-borda px-3 py-2 text-right font-semibold">Valor (€)</th>
                                <th class="w-56 border-b border-borda px-3 py-2 text-left font-semibold">Recibos</th>
                                <th class="w-10 border-b border-borda"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($linhas as $n => $linha)
                                <tr wire:key="linha-{{ $n }}" class="border-b border-borda/60 align-top">
                                    <td class="border-r border-borda px-1.5 py-2">
                                        {{-- Dia escrito à mão: "5", "04/08" ou "04/08/2026". --}}
                                        <input wire:model="linhas.{{ $n }}.dia" type="text" inputmode="numeric" class="campo-input w-full px-2 py-1.5 text-sm" placeholder="Ex: 5">
                                    </td>
                                    <td class="border-r border-borda px-1.5 py-2">
                                        <input wire:model="linhas.{{ $n }}.descricao" type="text" class="campo-input w-full min-w-[11rem] px-2 py-1.5 text-sm" placeholder="Ex: ACME - Porto">
                                    </td>
                                    <td class="border-r border-borda px-1.5 py-2">
                                        <input wire:model="linhas.{{ $n }}.detalhe" type="text" class="campo-input w-full min-w-[11rem] px-2 py-1.5 text-sm" placeholder="Ex: Portagem A1, almoço com cliente…">
                                    </td>
                                    <td class="border-r border-borda px-1.5 py-2">
                                        <select wire:model.live="linhas.{{ $n }}.categoria" class="campo-select w-full px-2 py-1.5 text-sm">
                                            <option value="">— Tipo —</option>
                                            @foreach (\App\Models\Despesa::CATEGORIAS as $c)
                                                <option value="{{ $c }}">{{ $c }}</option>
                                            @endforeach
                                        </select>
                                        @if (($linha['categoria'] ?? '') === 'Refeições')
                                            {{-- Nota a) da folha: A (almoço) / J (jantar), obrigatório. --}}
                                            <select wire:model="linhas.{{ $n }}.refeicao_tipo" class="campo-select mt-1 w-full px-2 py-1 text-xs">
                                                <option value="">A / J?</option>
                                                <option value="A">A — almoço</option>
                                                <option value="J">J — jantar</option>
                                            </select>
                                        @endif
                                    </td>
                                    <td class="border-r border-borda px-1.5 py-2">
                                        <input wire:model.live.debounce.500ms="linhas.{{ $n }}.valor" type="number" step="0.01" min="0" inputmode="decimal" class="campo-input w-full px-2 py-1.5 text-right text-sm" placeholder="0,00">
                                    </td>
                                    <td class="px-1.5 py-2">
                                        {{-- Recibos DA LINHA: digitalizar (scanner), câmara ou galeria + miniaturas. --}}
                                        <div class="flex items-center gap-1">
                                            <button type="button" @click="$wire.set('linhaDigitalizacao', {{ $n }}, false); abrir()" class="rounded-md border border-borda p-1.5 text-texto-medio hover:text-verde-700" title="Digitalizar recibo (câmara + filtro de documento)">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0a4 4 0 11-8 0 4 4 0 018 0zM4 16H2m2-5.5L2.5 9M20 10.5L21.5 9M7 4h10l1 3H6l1-3z"/></svg>
                                            </button>
                                            <label class="cursor-pointer rounded-md border border-borda p-1.5 text-texto-medio hover:text-verde-700" title="Tirar foto">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                <input type="file" wire:model="recibosLinhaUpload.{{ $n }}" accept="image/*" capture="environment" class="hidden">
                                            </label>
                                            <label class="cursor-pointer rounded-md border border-borda p-1.5 text-texto-medio hover:text-verde-700" title="Escolher da galeria">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                <input type="file" wire:model="recibosLinhaUpload.{{ $n }}" accept="image/*" multiple class="hidden">
                                            </label>
                                            <span wire:loading wire:target="recibosLinhaUpload.{{ $n }},reciboDigitalizado" class="text-xs text-texto-medio">…</span>
                                        </div>
                                        @php($gravados = isset($linha['despesa_id']) && $linha['despesa_id'] ? ($recibosPorDespesa[$linha['despesa_id']] ?? collect()) : collect())
                                        @if ($gravados->isNotEmpty() || ($recibosPendentes[$n] ?? []) !== [])
                                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                                @foreach ($gravados as $recibo)
                                                    <span class="group relative" wire:key="rg-{{ $recibo->id }}">
                                                        <a href="{{ route('anexos.ver', $recibo) }}" target="_blank">
                                                            <img src="{{ route('anexos.ver', $recibo) }}" alt="{{ $recibo->nome_ficheiro }}" class="h-10 w-10 rounded border border-borda object-cover">
                                                        </a>
                                                        <button type="button" wire:click="removerReciboGravado({{ $recibo->id }})" wire:confirm="Remover este recibo?"
                                                            class="absolute -right-1.5 -top-1.5 hidden h-4 w-4 items-center justify-center rounded-full bg-perigo-600 text-white group-hover:flex" title="Remover">
                                                            <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        </button>
                                                    </span>
                                                @endforeach
                                                @foreach ($recibosPendentes[$n] ?? [] as $i => $pendente)
                                                    <span class="group relative" wire:key="rp-{{ $n }}-{{ $i }}">
                                                        <img src="{{ $pendente->temporaryUrl() }}" alt="Recibo pendente" class="h-10 w-10 rounded border border-verde-300 object-cover">
                                                        <button type="button" wire:click="removerReciboPendente({{ $n }}, {{ $i }})"
                                                            class="absolute -right-1.5 -top-1.5 hidden h-4 w-4 items-center justify-center rounded-full bg-perigo-600 text-white group-hover:flex" title="Remover">
                                                            <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        </button>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-1 py-2 text-center">
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
                            <tr class="border-t border-borda bg-fundo">
                                <td colspan="4" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-texto-medio">Total despesas</td>
                                <td class="px-3 py-2 text-right text-sm font-semibold text-texto-forte">{{ number_format($total, 2, ',', ' ') }} €</td>
                                <td colspan="2"></td>
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
                        Dia à mão: "5", "04/08" ou "04/08/2026" · a) Refeições: A - almoço / J - jantar (com colegas, indicar o nome em "O que é") · b) Outras: especificar em "O que é".
                    </p>
                </div>

                @error('linhas') <p class="mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror
                @foreach ($linhas as $n => $linha)
                    @foreach (['dia', 'descricao', 'detalhe', 'categoria', 'refeicao_tipo', 'valor'] as $campo)
                        @error("linhas.$n.$campo") <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    @endforeach
                @endforeach
                @error('recibosLinhaUpload.*') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                @error('reciboDigitalizado') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror

                {{-- Modal do scanner: câmara em direto → capturar → filtro de documento → usar/repetir.
                     O recibo digitalizado cai na LINHA do botão que o abriu (linhaDigitalizacao). --}}
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

                <div class="mt-8 flex items-center justify-end gap-3 border-t border-borda pt-6">
                    <a href="{{ route('despesas') }}" wire:navigate class="botao-secundario">Cancelar</a>
                    <button type="submit" class="botao-primario">{{ $registoId ? 'Guardar alterações' : 'Guardar registo' }}</button>
                </div>
            </form>
        </div>
    </main>
</div>
