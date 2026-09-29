<div
    x-data="{
        inicio: $wire.entangle('hora_inicio'),
        fim: $wire.entangle('hora_fim'),
        diaInicio: $wire.entangle('data'),
        diaFim: $wire.entangle('data_fim'),
        // Duração do início ao fim, contando com a mudança de dia (ex.: 14h de 25/09 às 02h
        // de 26/09 = 12h). Só se mostra até 24h: num serviço de vários dias com horas por dia,
        // o tempo corrido não é o tempo de trabalho.
        get duracao() {
            if (!this.inicio || !this.fim) return '';
            const [hi, mi] = this.inicio.split(':').map(Number);
            const [hf, mf] = this.fim.split(':').map(Number);
            let dias = 0;
            if (this.diaInicio && this.diaFim) {
                dias = Math.round((Date.parse(this.diaFim) - Date.parse(this.diaInicio)) / 86400000);
            }
            let min = dias * 1440 + (hf * 60 + mf) - (hi * 60 + mi);
            if (isNaN(min) || min < 0 || min > 1440) return '';
            const h = Math.floor(min / 60), m = min % 60;
            if (h && m) return h + 'h' + String(m).padStart(2, '0');
            if (h) return h + 'h';
            return m + 'min';
        },
    }"
>
    <label class="campo-label">Horas</label>
    <div class="grid grid-cols-1 gap-4 min-[380px]:grid-cols-2">
        <div>
            <span class="mb-1 block text-xs text-texto-medio">Hora de início</span>
            <input type="time" x-model="inicio" class="campo-input" aria-label="Hora de início">
        </div>
        <div>
            <span class="mb-1 block text-xs text-texto-medio">Hora de fim</span>
            <input type="time" x-model="fim" class="campo-input" aria-label="Hora de fim">
        </div>
    </div>
    <p x-show="duracao" x-cloak class="mt-1 text-xs text-texto-medio">Duração: <span class="font-medium text-texto-forte" x-text="duracao"></span></p>
    @error('hora_inicio') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('hora_fim') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
