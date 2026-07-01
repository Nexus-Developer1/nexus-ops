@props([
    'prefixo',              // caminho Livewire da ficha, ex.: "fichas.123"
    'equipamentoId',        // id do equipamento (para o × remover)
    'serie' => '—',         // nº de série (destaque no cabeçalho — distingue os equipamentos)
    'modelo' => '',         // modelo (texto secundário)
    'principal' => false,   // equipamento principal do relatório?
])

@php
    use App\Models\FichaMedicao;

    // Grupos de valores elétricos (rótulo do grupo → [campo => rótulo curto]).
    $eletricos = [
        'Entrada — Tensão L-N (V)' => ['ve_ln_l1' => 'L1', 've_ln_l2' => 'L2', 've_ln_l3' => 'L3'],
        'Entrada — Tensão L-L (V)' => ['ve_ll_l1l2' => 'L1-L2', 've_ll_l1l3' => 'L1-L3', 've_ll_l2l3' => 'L2-L3'],
        'Carga (%)' => ['carga_l1' => 'L1', 'carga_l2' => 'L2', 'carga_l3' => 'L3'],
        'Frequência (Hz)' => ['frequencia' => 'Hz'],
        'Saída — Tensão L-N (V)' => ['vs_ln_l1' => 'L1', 'vs_ln_l2' => 'L2', 'vs_ln_l3' => 'L3'],
        'Saída — Tensão L-L (V)' => ['vs_ll_l1l2' => 'L1-L2', 'vs_ll_l1l3' => 'L1-L3', 'vs_ll_l2l3' => 'L2-L3'],
        'Saída — Corrente (A)' => ['is_l1' => 'L1', 'is_l2' => 'L2', 'is_l3' => 'L3'],
        'Saída — Corrente de pico (A)' => ['ispico_l1' => 'L1', 'ispico_l2' => 'L2', 'ispico_l3' => 'L3'],
        'Baterias / Temperatura' => ['vbat_pos' => 'Vbat +', 'vbat_neg' => 'Vbat −', 'temperatura' => 'Temp (°C)'],
    ];
@endphp

<section class="rounded-lg border border-borda bg-fundo/40" x-data="{ aberta: false }" wire:key="ficha-{{ $prefixo }}">
    <div class="flex items-center gap-3 px-4 py-3">
        {{-- Clicar no cabeçalho expande/colapsa a ficha deste equipamento. --}}
        <button type="button" @click="aberta=!aberta" class="flex min-w-0 flex-1 items-center gap-3 text-left">
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-texto-forte">{{ $serie ?: '—' }}</span>
                <span class="block truncate text-xs text-texto-medio">{{ $modelo ?: 'UPS' }}</span>
            </span>
            @if ($principal)
                <span class="shrink-0 rounded-full bg-verde-50 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-verde-700">principal</span>
            @endif
            <svg :class="aberta && 'rotate-180'" class="ml-auto h-5 w-5 shrink-0 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
        {{-- Remover o equipamento do relatório (função já existente). --}}
        <button type="button" wire:click="removerEquipamentoDoRelatorio({{ $equipamentoId }})" class="shrink-0 text-texto-fraco transition hover:text-perigo-600" title="Remover equipamento do relatório">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div x-show="aberta" x-transition x-cloak class="space-y-6 border-t border-borda px-4 py-5">

        {{-- Identificação do equipamento --}}
        <div class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
            <div><label class="campo-label">Marca</label><input type="text" wire:model="{{ $prefixo }}.marca" class="campo-input"></div>
            <div><label class="campo-label">Modelo</label><input type="text" wire:model="{{ $prefixo }}.modelo" class="campo-input"></div>
            <div><label class="campo-label">Nº de série</label><input type="text" wire:model="{{ $prefixo }}.serie" class="campo-input"></div>
            <div><label class="campo-label">Baterias</label><input type="text" wire:model="{{ $prefixo }}.baterias" class="campo-input" placeholder="Ex: 40"></div>
        </div>

        {{-- Configuração --}}
        <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
            <div>
                <label class="campo-label">Configuração</label>
                <select wire:model="{{ $prefixo }}.config_tipo" class="campo-select">
                    <option value="">—</option>
                    <option value="simples">Simples</option>
                    <option value="modular">Modular</option>
                    <option value="paralelo">Paralelo</option>
                </select>
            </div>
            <div class="flex items-end pb-1">
                <label class="inline-flex items-center gap-2 text-sm text-texto-forte">
                    <input type="checkbox" wire:model="{{ $prefixo }}.bypass_externo" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500">
                    Bypass externo
                </label>
            </div>
        </div>

        {{-- Módulos e bancos de bateria (até 4 cada) --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach (['modulos' => 'Módulos de potência', 'bancos_bateria' => 'Bancos de bateria'] as $grelha => $rotuloGrelha)
                <div>
                    <p class="campo-label">{{ $rotuloGrelha }}</p>
                    <div class="space-y-2">
                        @for ($i = 0; $i < FichaMedicao::MAX_LINHAS; $i++)
                            <div class="grid grid-cols-2 gap-2">
                                <input type="text" wire:model="{{ $prefixo }}.{{ $grelha }}.{{ $i }}.modelo" class="campo-input" placeholder="Modelo">
                                <input type="text" wire:model="{{ $prefixo }}.{{ $grelha }}.{{ $i }}.sn" class="campo-input" placeholder="N/S">
                            </div>
                        @endfor
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Valores elétricos --}}
        <div>
            <p class="mb-2 text-sm font-semibold text-texto-forte">Medições elétricas</p>
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($eletricos as $grupo => $campos)
                    <div class="rounded-lg border border-borda bg-white px-3 py-2.5">
                        <p class="mb-1.5 text-xs font-medium text-texto-medio">{{ $grupo }}</p>
                        <div class="grid gap-2 {{ count($campos) === 1 ? 'grid-cols-1' : 'grid-cols-3' }}">
                            @foreach ($campos as $campo => $curto)
                                <div>
                                    <label class="mb-0.5 block text-[11px] text-texto-fraco">{{ $curto }}</label>
                                    <input type="number" step="0.01" inputmode="decimal" wire:model="{{ $prefixo }}.{{ $campo }}" class="campo-input px-2 py-1.5 text-sm">
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Verificações --}}
        <div>
            <p class="mb-2 text-sm font-semibold text-texto-forte">Verificações</p>
            <div class="space-y-2">
                @foreach (FichaMedicao::VERIFICACOES as $chave => $rotulo)
                    <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-[1fr,7rem,1fr]">
                        <span class="text-sm text-texto-forte">{{ $rotulo }}</span>
                        <select wire:model="{{ $prefixo }}.verificacoes.{{ $chave }}.estado" class="campo-select py-1.5 text-sm">
                            <option value="">—</option>
                            <option value="ok">OK</option>
                            <option value="nok">NOK</option>
                        </select>
                        <input type="text" wire:model="{{ $prefixo }}.verificacoes.{{ $chave }}.nota" class="campo-input py-1.5 text-sm" placeholder="Observação">
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Teste de descarga --}}
        <div>
            <p class="mb-2 text-sm font-semibold text-texto-forte">Teste de descarga</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[34rem] text-sm">
                    <thead>
                        <tr class="text-left text-xs text-texto-medio">
                            <th class="px-2 py-1.5 font-medium">Tempo</th>
                            @foreach (FichaMedicao::COLS_DESCARGA as $col => $rotuloCol)
                                <th class="px-2 py-1.5 font-medium">{{ $rotuloCol }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (FichaMedicao::LINHAS_DESCARGA as $linha => $rotuloLinha)
                            <tr class="border-t border-borda">
                                <td class="px-2 py-1 font-medium text-texto-forte whitespace-nowrap">{{ $rotuloLinha }}</td>
                                @foreach (array_keys(FichaMedicao::COLS_DESCARGA) as $col)
                                    <td class="px-1 py-1">
                                        <input type="number" step="0.01" inputmode="decimal" wire:model="{{ $prefixo }}.teste_descarga.{{ $linha }}.{{ $col }}" class="campo-input px-2 py-1 text-sm">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3 sm:max-w-xs">
                <label class="campo-label">Baterias em funcionamento</label>
                <select wire:model="{{ $prefixo }}.baterias_funcionamento" class="campo-select">
                    <option value="">—</option>
                    <option value="ok">OK</option>
                    <option value="nok">NOK</option>
                </select>
            </div>
        </div>

        {{-- Conclusão --}}
        <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
            <div>
                <label class="campo-label">Carga a funcionar</label>
                <select wire:model="{{ $prefixo }}.carga_a_funcionar" class="campo-select">
                    <option value="">—</option>
                    <option value="ok">OK</option>
                    <option value="nok">NOK</option>
                </select>
            </div>
            <div>
                <label class="campo-label">UPS em modo normal</label>
                <select wire:model="{{ $prefixo }}.ups_modo_normal" class="campo-select">
                    <option value="">—</option>
                    <option value="ok">OK</option>
                    <option value="nok">NOK</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="campo-label">Notas finais</label>
                <textarea wire:model="{{ $prefixo }}.notas_finais" rows="2" class="campo-input resize-none" placeholder="Observações gerais da intervenção neste equipamento…"></textarea>
            </div>
        </div>
    </div>
</section>
