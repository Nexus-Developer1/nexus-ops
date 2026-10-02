<div>
    <label class="campo-label">Tipo de intervenção <span class="text-perigo-500">*</span></label>
    {{-- Espaço do sub-rótulo («Data de início», «Hora de início») dos campos ao lado: sem ele, a
         caixa ficava mais acima do que as das datas/horas. Só lado a lado (sm+). --}}
    <span class="mb-1 hidden text-xs sm:block" aria-hidden="true">&nbsp;</span>
    <select wire:model.live="tipo" class="campo-select">
        @foreach ($tipos as $t)
            <option value="{{ $t->value }}">{{ $t->rotulo() }}</option>
        @endforeach
    </select>
    @error('tipo') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    {{-- Relógio do SLA de resposta (Vaga 2): quando o cliente PEDIU a
         assistência — só corretivas. Nasce preenchido; corrige-se para
         pedidos telefónicos registados mais tarde. --}}
    @if ($tipo === 'corretiva')
        <div class="mt-3">
            <label class="campo-label">Pedido do cliente em</label>
            <input wire:model="pedido_em" type="datetime-local" class="campo-input">
            @error('pedido_em') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
        </div>
    @endif
</div>
