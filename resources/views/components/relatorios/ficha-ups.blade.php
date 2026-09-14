@props([
    'prefixo',              // caminho Livewire da ficha, ex.: "fichas.123"
    'descarga' => [],       // valores da tabela do teste de descarga (último recurso do gráfico)
    'curva' => [],          // curva importada do ficheiro do teste (fonte preferida do gráfico)
    'ordem',                // ordem dos blocos escolhida pelo utilizador (Novo::CAMPOS['ficha_ups'] validada)
])

@php
    use App\Models\FichaMedicao;

    // Valores elétricos em 4 FILAS (as mesmas do PDF — pedido da equipa, set. 2026):
    // 1.ª entrada, 2.ª saída, 3.ª carga e correntes, 4.ª baterias e temperatura.
    // (rótulo do grupo → [campo => rótulo curto]). A frequência de ENTRADA fica na 1.ª fila
    // e a de SAÍDA na 2.ª — são medições diferentes (em bateria a saída não segue a rede).
    $filasEletricas = [
        [
            'Entrada — Tensão L-N (V)' => ['ve_ln_l1' => 'L1', 've_ln_l2' => 'L2', 've_ln_l3' => 'L3'],
            'Entrada — Tensão L-L (V)' => ['ve_ll_l1l2' => 'L1-L2', 've_ll_l1l3' => 'L1-L3', 've_ll_l2l3' => 'L2-L3'],
            'Entrada — Frequência (Hz)' => ['frequencia' => 'Hz'],
        ],
        [
            'Saída — Tensão L-N (V)' => ['vs_ln_l1' => 'L1', 'vs_ln_l2' => 'L2', 'vs_ln_l3' => 'L3'],
            'Saída — Tensão L-L (V)' => ['vs_ll_l1l2' => 'L1-L2', 'vs_ll_l1l3' => 'L1-L3', 'vs_ll_l2l3' => 'L2-L3'],
            'Saída — Frequência (Hz)' => ['frequencia_saida' => 'Hz'],
        ],
        [
            'Carga (%)' => ['carga_l1' => 'L1', 'carga_l2' => 'L2', 'carga_l3' => 'L3'],
            'Saída — Corrente (A)' => ['is_l1' => 'L1', 'is_l2' => 'L2', 'is_l3' => 'L3'],
            'Saída — Corrente de pico (A)' => ['ispico_l1' => 'L1', 'ispico_l2' => 'L2', 'ispico_l3' => 'L3'],
        ],
        [
            // Temperatura separada das baterias: é a temperatura NA UPS, não a das baterias.
            'Baterias' => ['vbat_pos' => 'Vbat +', 'vbat_neg' => 'Vbat −'],
            'Temperatura UPS' => ['temperatura' => 'Temp (°C)'],
        ],
    ];
@endphp

{{-- Campos da ficha de medições (sem cabeçalho próprio — o contexto/tab vem de fora). --}}
<div class="space-y-6" wire:key="ficha-{{ $prefixo }}" x-data="{ organizar: false, arrastado: null }">

    {{-- Blocos REORDENÁVEIS por utilizador (pedido da equipa, set. 2026), tal como nos Dados
         Gerais: «Organizar campos» → arrastar (desktop) ou setas ▲▼ (telemóvel). Cada bloco
         vive numa partial em components/relatorios/ficha-ups; a ordem vem do componente Livewire
         (grupo ficha_ups), já validada contra a whitelist. --}}
    <div class="-mb-3 flex flex-wrap items-center justify-end gap-3 text-xs">
        <button type="button" x-show="organizar" x-cloak wire:click="reporOrdemCampos('ficha_ups')" class="font-medium text-texto-medio hover:text-texto-forte hover:underline">Repor ordem de fábrica</button>
        <button type="button" @click="organizar = !organizar; arrastado = null" class="inline-flex items-center gap-1.5 rounded-lg border border-borda px-3 py-1.5 font-medium text-texto-medio transition hover:bg-fundo hover:text-texto-forte" :class="organizar && 'border-verde-300 bg-verde-50 text-verde-700'">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg>
            <span x-text="organizar ? 'Concluir' : 'Organizar campos'"></span>
        </button>
    </div>

    @foreach ($ordem as $bloco)
        <div wire:key="campo-{{ $prefixo }}-{{ $bloco }}" class="relative"
            :class="organizar && 'rounded-lg border border-dashed border-verde-300 bg-verde-50/40 p-3 pt-9 cursor-move'"
            :draggable="organizar"
            x-on:dragstart="if (!organizar) return; arrastado = '{{ $bloco }}'"
            x-on:dragover.prevent
            x-on:drop.prevent="if (organizar && arrastado && arrastado !== '{{ $bloco }}') { $wire.reordenarCampos(window.reordenar($wire.ordemCampos.ficha_ups, arrastado, '{{ $bloco }}'), 'ficha_ups') } arrastado = null">
            <div x-show="organizar" x-cloak class="absolute right-2 top-2 flex items-center gap-1">
                <button type="button" wire:click="moverCampo('{{ $bloco }}', -1, 'ficha_ups')" title="Subir" class="flex h-7 w-7 items-center justify-center rounded-md border border-borda bg-white text-texto-medio hover:text-verde-700 disabled:opacity-30" @disabled($loop->first)>
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                </button>
                <button type="button" wire:click="moverCampo('{{ $bloco }}', 1, 'ficha_ups')" title="Descer" class="flex h-7 w-7 items-center justify-center rounded-md border border-borda bg-white text-texto-medio hover:text-verde-700 disabled:opacity-30" @disabled($loop->last)>
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </div>
            @include('components.relatorios.ficha-ups.' . $bloco)
        </div>
    @endforeach
</div>
