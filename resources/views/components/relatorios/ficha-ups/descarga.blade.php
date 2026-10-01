{{-- Teste de descarga --}}
<div>
    <p class="mb-2 text-sm font-semibold text-texto-forte">Teste de descarga</p>
    {{-- No telemóvel a tabela encolhe para caber no ecrã (sem colunas escondidas à direita);
         a partir de sm volta à largura confortável. --}}
    <div class="overflow-x-auto">
        <table class="w-full table-fixed text-sm sm:table-auto sm:min-w-[34rem]">
            <thead>
                <tr class="text-left text-xs text-texto-medio">
                    <th class="w-14 px-1 py-1.5 align-bottom font-medium leading-tight sm:w-auto sm:px-2">Tempo</th>
                    @foreach (\App\Models\FichaMedicao::COLS_DESCARGA as $col => $rotuloCol)
                        <th class="px-1 py-1.5 align-bottom font-medium leading-tight sm:px-2"><span class="sm:hidden">{{ str_replace('Autonomia', 'Aut.', $rotuloCol) }}</span><span class="hidden sm:inline">{{ $rotuloCol }}</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach (\App\Models\FichaMedicao::LINHAS_DESCARGA as $linha => $rotuloLinha)
                    <tr class="border-t border-borda">
                        <td class="px-1 py-1 font-medium text-texto-forte whitespace-nowrap sm:px-2">{{ $rotuloLinha }}</td>
                        @foreach (array_keys(\App\Models\FichaMedicao::COLS_DESCARGA) as $col)
                            <td class="px-0.5 py-1 sm:px-1">
                                <input type="number" step="0.01" inputmode="decimal" wire:model="{{ $prefixo }}.teste_descarga.{{ $linha }}.{{ $col }}" class="campo-input min-w-0 px-1.5 py-1 text-sm sm:px-2">
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @php($equipId = (int) (explode('.', $prefixo)[1] ?? 0))
    {{-- Ficheiro do teste (battest.txt) → o gráfico desenha a curva completa, igual à
         do Excel; a tabela acima só é usada quando não há ficheiro. --}}
    <div class="mt-3 flex flex-wrap items-center gap-3">
        <label class="campo-label !mb-0" for="descarga-ficheiro-{{ $equipId }}">Ficheiro do teste</label>
        <input id="descarga-ficheiro-{{ $equipId }}" type="file" accept=".txt,.csv,.log"
            wire:model="descargaFicheiros.{{ $equipId }}"
            class="max-w-full text-sm text-texto-medio file:mr-3 file:rounded-lg file:border-0 file:bg-verde-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-verde-700 hover:file:bg-verde-100">
        <span wire:loading wire:target="descargaFicheiros.{{ $equipId }}" class="text-xs text-texto-fraco">A ler…</span>
        @if ($curva !== [])
            <button type="button" wire:click="removerCurvaDescarga({{ $equipId }})" class="text-sm font-medium text-texto-fraco hover:text-perigo-600">Remover gráfico</button>
        @endif
    </div>
    @error('descargaFicheiros.'.$equipId) <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror

    {{-- Gráfico (o mesmo do PDF): curva do ficheiro; sem ficheiro, os valores da tabela. --}}
    @if ($curva !== [] || $descarga !== [])
        {{-- O SVG traz largura fixa (é a mesma do PDF); no ecrã estreito encolhe para caber. --}}
        <div class="mt-3 overflow-x-auto [&>svg]:h-auto [&>svg]:max-w-full">
            <x-relatorios.grafico-descarga :curva="$curva" :dados="$descarga" :largura="640" :altura="260" />
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
