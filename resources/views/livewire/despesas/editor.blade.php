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

            <form wire:submit="guardar" class="cartao mt-6 p-4 sm:mt-8 sm:p-8" x-data="scannerRecibo">
                {{-- ===== Cabeçalho da folha — empilha no telemóvel ===== --}}
                <div class="grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg border border-borda bg-fundo/40 p-4 sm:grid-cols-3">
                    <div>
                        <label class="campo-label">Nome colaborador</label>
                        <p class="py-1.5 text-sm font-medium text-texto-forte">{{ auth()->user()->nome }}</p>
                    </div>
                    <div>
                        <label class="campo-label">Matrícula</label>
                        <input wire:model="matricula" type="text" class="campo-input" placeholder="Ex: BD-71-VI">
                    </div>
                    <div>
                        <label class="campo-label">Departamento</label>
                        <input wire:model="departamento" type="text" class="campo-input" placeholder="Ex: Infraestruturas">
                    </div>
                </div>

                {{-- ===== MOBILE (< lg): um CARTÃO por linha, campos empilhados ===== --}}
                <div class="mt-5 space-y-4 lg:hidden">
                    @foreach ($linhas as $n => $linha)
                        <div wire:key="linha-m-{{ $n }}" class="rounded-lg border border-borda p-4">
                            <div class="mb-3 flex items-center justify-between">
                                <span class="text-sm font-semibold text-texto-medio">Despesa {{ $n + 1 }}</span>
                                @if (count($linhas) > 1)
                                    <button type="button" wire:click="removerLinha({{ $n }})" class="text-xs font-medium text-texto-fraco hover:text-perigo-600">Remover</button>
                                @endif
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="campo-label">Dia <span class="text-perigo-500">*</span></label>
                                    {{-- Calendário SEM dia pré-selecionado (nasce vazio). --}}
                                    <input wire:model="linhas.{{ $n }}.dia" type="date" class="campo-input">
                                </div>
                                <div>
                                    <label class="campo-label">Valor (€) <span class="text-perigo-500">*</span></label>
                                    <input wire:model.live.debounce.500ms="linhas.{{ $n }}.valor" type="number" step="0.01" min="0" inputmode="decimal" class="campo-input text-right" placeholder="0,00">
                                </div>
                                <div class="col-span-2">
                                    <label class="campo-label">Descrição <span class="text-perigo-500">*</span> <span class="text-xs font-normal normal-case text-texto-fraco">(local · serviço)</span></label>
                                    <input wire:model="linhas.{{ $n }}.descricao" type="text" class="campo-input" placeholder="Ex: ACME - Porto">
                                </div>
                                <div class="col-span-2 grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="campo-label">Tipo <span class="text-perigo-500">*</span></label>
                                        <select wire:model.live="linhas.{{ $n }}.categoria" class="campo-select">
                                            <option value="">— Tipo —</option>
                                            @foreach (\App\Models\Despesa::CATEGORIAS as $c)
                                                <option value="{{ $c }}">{{ $c }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @if (($linha['categoria'] ?? '') === 'Refeições')
                                        <div>
                                            <label class="campo-label">Almoço / Jantar <span class="text-perigo-500">*</span></label>
                                            <select wire:model="linhas.{{ $n }}.refeicao_tipo" class="campo-select">
                                                <option value="">A / J?</option>
                                                <option value="A">A — almoço</option>
                                                <option value="J">J — jantar</option>
                                            </select>
                                        </div>
                                    @endif
                                </div>
                                <div class="col-span-2">
                                    <label class="campo-label">O que é</label>
                                    <input wire:model="linhas.{{ $n }}.detalhe" type="text" class="campo-input" placeholder="Ex: Portagem A1, almoço com cliente…">
                                </div>
                                <div class="col-span-2">
                                    <label class="campo-label">Recibos <span class="text-perigo-500">*</span></label>
                                    @include('livewire.despesas._recibos-linha', ['n' => $n, 'linha' => $linha, 'sufixo' => 'm'])
                                </div>
                            </div>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between rounded-lg border border-borda bg-fundo px-4 py-3">
                        <span class="text-xs font-semibold uppercase tracking-wide text-texto-medio">Total despesas</span>
                        <span class="text-base font-semibold text-texto-forte">{{ number_format($total, 2, ',', ' ') }} €</span>
                    </div>
                </div>

                {{-- ===== DESKTOP (lg+): tabela no formato da folha ===== --}}
                <div class="mt-5 hidden overflow-x-auto rounded-lg border border-borda lg:block">
                    <table class="w-full min-w-[1080px] text-sm">
                        <thead>
                            <tr class="bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                                <th class="w-36 border-b border-r border-borda px-3 py-2 text-left font-semibold">Dia <span class="text-perigo-500">*</span></th>
                                <th class="border-b border-r border-borda px-3 py-2 text-left font-semibold">Descrição <span class="text-perigo-500">*</span><br><span class="font-normal normal-case text-texto-fraco">(local · serviço)</span></th>
                                <th class="w-44 border-b border-r border-borda px-3 py-2 text-left font-semibold">Tipo <span class="text-perigo-500">*</span></th>
                                <th class="border-b border-r border-borda px-3 py-2 text-left font-semibold">O que é<br><span class="font-normal normal-case text-texto-fraco">(opcional)</span></th>
                                <th class="w-28 border-b border-r border-borda px-3 py-2 text-right font-semibold">Valor (€) <span class="text-perigo-500">*</span></th>
                                <th class="w-56 border-b border-borda px-3 py-2 text-left font-semibold">Recibos <span class="text-perigo-500">*</span></th>
                                <th class="w-10 border-b border-borda"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($linhas as $n => $linha)
                                <tr wire:key="linha-d-{{ $n }}" class="border-b border-borda/60 align-top">
                                    <td class="border-r border-borda px-1.5 py-2">
                                        {{-- Calendário SEM dia pré-selecionado (nasce vazio). --}}
                                        <input wire:model="linhas.{{ $n }}.dia" type="date" class="campo-input w-full px-2 py-1.5 text-sm">
                                    </td>
                                    <td class="border-r border-borda px-1.5 py-2">
                                        <input wire:model="linhas.{{ $n }}.descricao" type="text" class="campo-input w-full min-w-[11rem] px-2 py-1.5 text-sm" placeholder="Ex: ACME - Porto">
                                    </td>
                                    <td class="border-r border-borda px-1.5 py-2">
                                        <select wire:model.live="linhas.{{ $n }}.categoria" class="campo-select w-full px-2 py-1.5 text-sm">
                                            <option value="">— Tipo —</option>
                                            @foreach (\App\Models\Despesa::CATEGORIAS as $c)
                                                <option value="{{ $c }}">{{ $c }}</option>
                                            @endforeach
                                        </select>
                                        @if (($linha['categoria'] ?? '') === 'Refeições')
                                            <select wire:model="linhas.{{ $n }}.refeicao_tipo" class="campo-select mt-1 w-full px-2 py-1 text-xs">
                                                <option value="">A / J?</option>
                                                <option value="A">A — almoço</option>
                                                <option value="J">J — jantar</option>
                                            </select>
                                        @endif
                                    </td>
                                    <td class="border-r border-borda px-1.5 py-2">
                                        <input wire:model="linhas.{{ $n }}.detalhe" type="text" class="campo-input w-full min-w-[11rem] px-2 py-1.5 text-sm" placeholder="Ex: Portagem A1, almoço com cliente…">
                                    </td>
                                    <td class="border-r border-borda px-1.5 py-2">
                                        <input wire:model.live.debounce.500ms="linhas.{{ $n }}.valor" type="number" step="0.01" min="0" inputmode="decimal" class="campo-input w-full px-2 py-1.5 text-right text-sm" placeholder="0,00">
                                    </td>
                                    <td class="px-1.5 py-2">
                                        @include('livewire.despesas._recibos-linha', ['n' => $n, 'linha' => $linha, 'sufixo' => 'd'])
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

                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <button type="button" wire:click="adicionarLinha" class="botao-secundario w-full justify-center sm:w-auto">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                        Linha
                    </button>
                </div>

                @error('linhas') <p class="mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror
                @foreach ($linhas as $n => $linha)
                    @foreach (['dia', 'descricao', 'detalhe', 'categoria', 'refeicao_tipo', 'valor', 'recibos'] as $campo)
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

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-borda pt-6 sm:mt-8 sm:flex-row sm:items-center sm:justify-end">
                    <a href="{{ route('despesas') }}" wire:navigate class="botao-secundario w-full justify-center sm:w-auto">Cancelar</a>
                    <button type="submit" class="botao-primario w-full justify-center sm:w-auto">{{ $registoId ? 'Guardar alterações' : 'Guardar registo' }}</button>
                </div>
            </form>
        </div>
    </main>
</div>
