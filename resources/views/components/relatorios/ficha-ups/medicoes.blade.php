{{-- Valores elétricos --}}
<div>
    <p class="mb-2 text-sm font-semibold text-texto-forte">Medições elétricas</p>
    <div class="space-y-4">
    @foreach ($filasEletricas as $fila)
    <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($fila as $grupo => $campos)
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
    @endforeach
    </div>
</div>
