<div
    x-data="{
        inicio: $wire.entangle('hora_inicio'),
        fim: $wire.entangle('hora_fim'),
        get duracao() {
            if (!this.inicio || !this.fim) return '';
            const [hi, mi] = this.inicio.split(':').map(Number);
            const [hf, mf] = this.fim.split(':').map(Number);
            let min = (hf * 60 + mf) - (hi * 60 + mi);
            if (isNaN(min) || min < 0) return '';
            const h = Math.floor(min / 60), m = min % 60;
            if (h && m) return h + 'h' + String(m).padStart(2, '0');
            if (h) return h + 'h';
            return m + 'min';
        },
    }"
>
    <label class="campo-label">Horas</label>
    <div class="grid grid-cols-1 gap-4 min-[380px]:grid-cols-2">
        <input type="time" x-model="inicio" class="campo-input" aria-label="Hora de início">
        <input type="time" x-model="fim" class="campo-input" aria-label="Hora de fim">
    </div>
    <p x-show="duracao" x-cloak class="mt-1 text-xs text-texto-medio">Duração: <span class="font-medium text-texto-forte" x-text="duracao"></span></p>
    @error('hora_inicio') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('hora_fim') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
