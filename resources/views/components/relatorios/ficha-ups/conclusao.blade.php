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
