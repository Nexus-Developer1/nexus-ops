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
