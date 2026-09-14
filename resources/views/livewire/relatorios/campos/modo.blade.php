{{-- Modo: relatório de contrato (equipamentos vêm do contrato) vs individual (à mão). --}}
<div class="sm:col-span-2">
    <label class="campo-label">Tipo de relatório</label>
    <div class="inline-flex rounded-lg border border-borda bg-fundo p-1">
        <button type="button" wire:click="definirModo('contrato')" class="rounded-md px-4 py-1.5 text-sm font-medium transition {{ $modo === 'contrato' ? 'bg-white text-texto-forte shadow-sm' : 'text-texto-medio hover:text-texto-forte' }}">Relatório de contrato</button>
        <button type="button" wire:click="definirModo('individual')" class="rounded-md px-4 py-1.5 text-sm font-medium transition {{ $modo === 'individual' ? 'bg-white text-texto-forte shadow-sm' : 'text-texto-medio hover:text-texto-forte' }}">Relatório individual</button>
    </div>
</div>
