@props([
    'prefixo',              // caminho Livewire da ficha, ex.: "fichas.123"
    'descarga' => [],       // valores do teste de descarga já gravados (para o gráfico)
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
        // Temperatura separada das baterias: é a temperatura NA UPS, não a das baterias.
        'Baterias' => ['vbat_pos' => 'Vbat +', 'vbat_neg' => 'Vbat −'],
        'Temperatura UPS' => ['temperatura' => 'Temp (°C)'],
    ];
@endphp

{{-- Campos da ficha de medições (sem cabeçalho próprio — o contexto/tab vem de fora). --}}
<div class="space-y-6" wire:key="ficha-{{ $prefixo }}">

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

        {{-- Módulos de potência (até 4). Os "Bancos de bateria" saíram do formulário a pedido
             da equipa — os bancos registam-se na ficha do EQUIPAMENTO; fichas antigas com
             bancos preenchidos continuam a mostrá-los no PDF. --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div>
                <p class="campo-label">Módulos de potência</p>
                <div class="space-y-2">
                    @for ($i = 0; $i < FichaMedicao::MAX_LINHAS; $i++)
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" wire:model="{{ $prefixo }}.modulos.{{ $i }}.modelo" class="campo-input" placeholder="Modelo">
                            <input type="text" wire:model="{{ $prefixo }}.modulos.{{ $i }}.sn" class="campo-input" placeholder="N/S">
                        </div>
                    @endfor
                </div>
            </div>
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
                                    @if ($campo === 'temperatura')
                                        {{-- Acima de 25 °C fica a vermelho, em tempo real e ao carregar a ficha. --}}
                                        <input type="number" step="0.01" inputmode="decimal" wire:model="{{ $prefixo }}.{{ $campo }}"
                                            x-data="{ marcar() { const v = parseFloat(this.$el.value); const alta = !isNaN(v) && v > 25; ['text-perigo-600', 'border-perigo-500', 'font-semibold'].forEach(c => this.$el.classList.toggle(c, alta)); } }"
                                            x-init="marcar()" @input="marcar()"
                                            class="campo-input px-2 py-1.5 text-sm">
                                    @else
                                        <input type="number" step="0.01" inputmode="decimal" wire:model="{{ $prefixo }}.{{ $campo }}" class="campo-input px-2 py-1.5 text-sm">
                                    @endif
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
            {{-- Gráfico Vbat+/Vbat− (o mesmo do PDF); redesenha quando os valores sincronizam. --}}
            @if ($descarga !== [])
                <div class="mt-3 overflow-x-auto">
                    <x-relatorios.grafico-descarga :dados="$descarga" />
                </div>
            @endif
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
                <label class="campo-label">Equipamento a suportar a carga e sem anomalias</label>
                <select wire:model="{{ $prefixo }}.carga_a_funcionar" class="campo-select">
                    <option value="">—</option>
                    <option value="ok">OK</option>
                    <option value="nok">NOK</option>
                </select>
            </div>
            <div>
                <label class="campo-label">Equipamento com status carga no inversor</label>
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

        {{-- Recomendações e próximos passos (por equipamento) --}}
        <div>
            <p class="mb-2 text-sm font-semibold text-texto-forte">Recomendações e próximos passos</p>
            <div class="flex flex-col gap-3 sm:flex-row">
                <input type="text" wire:model="{{ $prefixo }}.recomendacao" class="campo-input flex-1" placeholder="Ex: Substituição de baterias">
                <select wire:model="{{ $prefixo }}.prioridade" class="campo-select w-full shrink-0 sm:w-40">
                    <option value="Baixa">Baixa</option>
                    <option value="Normal">Normal</option>
                    <option value="Alta">Alta</option>
                </select>
            </div>
        </div>
</div>
