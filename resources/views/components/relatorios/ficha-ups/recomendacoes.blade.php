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
