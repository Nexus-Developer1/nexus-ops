{{-- Identificação do equipamento --}}
<div class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
    <div><label class="campo-label">Marca</label><input type="text" wire:model="{{ $prefixo }}.marca" class="campo-input"></div>
    <div><label class="campo-label">Modelo</label><input type="text" wire:model="{{ $prefixo }}.modelo" class="campo-input"></div>
    <div><label class="campo-label">Nº de série</label><input type="text" wire:model="{{ $prefixo }}.serie" class="campo-input"></div>
    <div><label class="campo-label">Baterias</label><input type="text" wire:model="{{ $prefixo }}.baterias" class="campo-input" placeholder="Ex: 40"></div>
</div>
