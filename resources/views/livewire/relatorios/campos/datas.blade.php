<div>
    <label class="campo-label">Datas da intervenção <span class="text-perigo-500">*</span></label>
    <div class="grid grid-cols-1 gap-4 min-[380px]:grid-cols-2">
        <input wire:model="data" type="date" class="campo-input" aria-label="Data de início">
        <input wire:model="data_fim" type="date" class="campo-input" aria-label="Data de término">
    </div>
    @error('data') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('data_fim') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
