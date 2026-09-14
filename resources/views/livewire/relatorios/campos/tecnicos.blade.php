{{-- Técnicos: quem FEZ a intervenção (não necessariamente quem redige o
     relatório) — nada vem pré-selecionado e não há hierarquia entre eles.
     A lista vem da BD a cada render, por isso reflete quem for entrando. --}}
<div class="sm:col-span-2">
    <label class="campo-label">Técnicos <span class="text-perigo-500">*</span></label>
    <div class="flex flex-wrap gap-2">
        @foreach ($tecnicos as $t)
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition {{ in_array($t->id, array_map('intval', $tecnicoIds), true) ? 'border-verde-400 bg-verde-50 font-medium text-verde-700' : 'border-borda text-texto-medio hover:bg-fundo' }}">
                <input type="checkbox" wire:model.live="tecnicoIds" value="{{ $t->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                {{ $t->nome }}
            </label>
        @endforeach
    </div>

    @if ($tecnicos->isEmpty())
        <p class="mt-1.5 text-xs text-texto-fraco">Não há técnicos ativos para selecionar.</p>
    @else
    @endif
    @error('tecnicoIds') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
    @error('tecnicoIds.*') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
