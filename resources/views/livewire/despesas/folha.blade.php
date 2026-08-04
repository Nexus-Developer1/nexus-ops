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

            <div class="mt-6 flex justify-end">
                <button wire:click="guardar" wire:loading.attr="disabled" wire:target="guardar" class="botao-primario">Guardar folha</button>
            </div>
        </div>
    </main>
</div>
