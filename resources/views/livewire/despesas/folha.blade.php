<div>
    <x-topbar :breadcrumb="['Despesas', 'Folha de ' . $folha->rotuloMes()]">
        <a href="{{ route('despesas') }}" wire:navigate class="botao-secundario">Voltar</a>
        <a href="{{ route('despesas.folha.pdf', $folha) }}" target="_blank" class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17v3a2 2 0 002 2h14a2 2 0 002-2v-3"/></svg>
            PDF
        </a>
        <button wire:click="guardar" wire:loading.attr="disabled" wire:target="guardar" class="botao-primario">
            <span wire:loading.remove wire:target="guardar">Guardar folha</span>
            <span wire:loading wire:target="guardar">A guardar…</span>
        </button>
    </x-topbar>

    {{-- Toast de gravação (fica na página — save de prevenção, como no relatório). --}}
    <div x-data="{ visivel: false }"
        x-on:folha-guardada.window="visivel = true; setTimeout(() => visivel = false, 2500)"
        x-show="visivel" x-cloak x-transition.opacity
        class="fixed bottom-6 right-6 z-50 flex items-center gap-2 rounded-lg bg-verde-600 px-4 py-3 text-sm font-medium text-white shadow-lg">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        Folha guardada
    </div>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Registo de despesas</h1>
                    <p class="mt-2 text-sm text-texto-medio">{{ $folha->colaborador?->nome ?? '—' }} · {{ ucfirst($folha->rotuloMes()) }}</p>
                </div>
            </div>

            @if ($errors->any())
                <div class="mt-6 rounded-lg border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600">
                    <ul class="list-inside list-disc space-y-0.5">
                        @foreach ($errors->all() as $erro)
                            <li>{{ $erro }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Cabeçalho da folha (como na folha impressa). --}}
            <section class="cartao mt-6">
                <div class="grid grid-cols-1 gap-x-8 gap-y-4 px-6 py-5 sm:grid-cols-3">
                    <div>
                        <label class="campo-label">Matrícula</label>
                        <input wire:model="matricula" type="text" class="campo-input" placeholder="Ex: BD-71-VI">
                    </div>
                    <div>
                        <label class="campo-label">Departamento</label>
                        <input wire:model="departamento" type="text" class="campo-input" placeholder="Ex: Infraestruturas">
                    </div>
                    <div>
                        <label class="campo-label">Adiantado (€)</label>
                        <input wire:model.live.debounce.400ms="adiantado" type="number" step="0.01" min="0" class="campo-input" placeholder="0,00">
                    </div>
                </div>
            </section>

            {{-- Grelha: uma linha por dia, colunas fixas da folha. --}}
            <section class="cartao mt-6 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[960px] text-sm">
                        <thead>
                            <tr class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                                <th class="px-3 py-3 text-left font-semibold">Dia</th>
                                <th class="px-3 py-3 text-left font-semibold">Descrição <span class="normal-case">(cliente - localidade)</span></th>
                                @foreach ($colunas as $coluna)
                                    <th class="px-2 py-3 text-right font-semibold">{{ $coluna }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($linhas as $dia => $linha)
                                @php($dataDia = \Illuminate\Support\Carbon::create($folha->ano, $folha->mes, $dia))
                                <tr wire:key="dia-{{ $dia }}" class="border-b border-borda/60 {{ $dataDia->isWeekend() ? 'bg-fundo/60' : '' }}">
                                    <td class="whitespace-nowrap px-3 py-1.5 text-texto-medio">{{ $dia }} <span class="text-xs text-texto-fraco">{{ $dataDia->translatedFormat('D') }}</span></td>
                                    <td class="px-2 py-1.5">
                                        <input wire:model="linhas.{{ $dia }}.descricao" type="text" class="campo-input w-full min-w-[14rem] px-2 py-1 text-sm" placeholder="">
                                    </td>
                                    @foreach ($colunas as $i => $coluna)
                                        <td class="px-1 py-1.5">
                                            <input wire:model.live.debounce.600ms="linhas.{{ $dia }}.valores.{{ $i }}" type="number" step="0.01" min="0" inputmode="decimal" class="campo-input w-24 px-2 py-1 text-right text-sm">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-borda bg-fundo font-semibold text-texto-forte">
                                <td class="px-3 py-2.5" colspan="2">Totais (€)</td>
                                @foreach ($totais as $t)
                                    <td class="px-2 py-2.5 text-right">{{ number_format($t, 2, ',', ' ') }} €</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            {{-- Resumo (como o rodapé da folha impressa). --}}
            <section class="cartao mt-6">
                <div class="grid grid-cols-2 gap-x-8 gap-y-3 px-6 py-5 text-sm sm:grid-cols-4">
                    <div><p class="campo-label">Total despesas</p><p class="text-lg font-semibold text-texto-forte">{{ number_format($total, 2, ',', ' ') }} €</p></div>
                    <div><p class="campo-label">Adiantado</p><p class="text-lg font-semibold text-texto-forte">{{ number_format(is_numeric($adiantado) ? (float) $adiantado : 0, 2, ',', ' ') }} €</p></div>
                    <div><p class="campo-label">A devolver</p><p class="text-lg font-semibold {{ $aDevolver > 0 ? 'text-aviso-500' : 'text-texto-forte' }}">{{ number_format($aDevolver, 2, ',', ' ') }} €</p></div>
                    <div><p class="campo-label">A receber</p><p class="text-lg font-semibold {{ $aReceber > 0 ? 'text-verde-700' : 'text-texto-forte' }}">{{ number_format($aReceber, 2, ',', ' ') }} €</p></div>
                </div>
            </section>

            {{-- Recibos digitalizados: tirar foto com a câmara do telemóvel ou escolher da galeria.
                 Upload imediato (nada se perde); "Tirar foto" SEM multiple (o multiple+capture
                 quebrava o "Repetir" no iOS — mesma lição das fotos dos relatórios). --}}
            <section class="cartao mt-6">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-texto-forte">Recibos</h2>
                        <p class="mt-1 text-xs text-texto-fraco">Digitaliza os recibos com a câmara — ficam anexados a esta folha.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="botao-secundario cursor-pointer">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Tirar foto
                            <input type="file" wire:model="recibosNovos" accept="image/*" capture="environment" class="hidden">
                        </label>
                        <label class="botao-secundario cursor-pointer">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            Galeria
                            <input type="file" wire:model="recibosNovos" accept="image/*" multiple class="hidden">
                        </label>
                    </div>
                </div>
                <div wire:loading wire:target="recibosNovos" class="px-6 pb-3 text-sm text-texto-medio">A carregar recibo…</div>
                @error('recibosNovos.*') <p class="px-6 pb-3 text-xs text-perigo-500">{{ $message }}</p> @enderror
                @if ($recibos->isNotEmpty())
                    <div class="grid grid-cols-2 gap-3 border-t border-borda px-6 py-5 sm:grid-cols-4 lg:grid-cols-6">
                        @foreach ($recibos as $recibo)
                            <div class="group relative" wire:key="recibo-{{ $recibo->id }}">
                                <a href="{{ route('anexos.ver', $recibo) }}" target="_blank">
                                    <img src="{{ route('anexos.ver', $recibo) }}" alt="{{ $recibo->nome_ficheiro }}" class="h-28 w-full rounded-lg border border-borda object-cover">
                                </a>
                                <button type="button" wire:click="removerRecibo({{ $recibo->id }})" wire:confirm="Remover este recibo?"
                                    class="absolute -right-2 -top-2 hidden h-6 w-6 items-center justify-center rounded-full bg-perigo-600 text-white shadow group-hover:flex" title="Remover">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <div class="mt-6 flex justify-end">
                <button wire:click="guardar" wire:loading.attr="disabled" wire:target="guardar" class="botao-primario">Guardar folha</button>
            </div>
        </div>
    </main>
</div>
