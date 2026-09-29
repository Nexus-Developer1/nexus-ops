<div>
    <label class="campo-label">Datas da intervenção <span class="text-perigo-500">*</span></label>
    {{-- Cada campo diz o que é (pedido da equipa, set. 2026): num serviço de dois dias — ex. das
         14h de 25/09 às 02h de 26/09 — não se percebia qual das datas era qual. --}}
    <div class="grid grid-cols-1 gap-4 min-[380px]:grid-cols-2">
        <div>
            <span class="mb-1 block text-xs text-texto-medio">Data de início</span>
            <input wire:model="data" type="date" class="campo-input" aria-label="Data de início">
        </div>
        <div>
            <span class="mb-1 block text-xs text-texto-medio">Data de fim</span>
            <input wire:model="data_fim" type="date" class="campo-input" aria-label="Data de fim">
        </div>
    </div>
    @error('data') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('data_fim') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
