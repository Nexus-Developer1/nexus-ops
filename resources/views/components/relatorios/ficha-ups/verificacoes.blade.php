{{-- Verificações --}}
<div>
    <p class="mb-2 text-sm font-semibold text-texto-forte">Verificações</p>
    <div class="space-y-2">
        @foreach (\App\Models\FichaMedicao::VERIFICACOES as $chave => $rotulo)
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
