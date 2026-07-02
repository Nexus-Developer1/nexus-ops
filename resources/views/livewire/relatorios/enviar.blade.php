<div>
    <x-topbar :breadcrumb="['Relatórios', 'Enviar ' . $relatorio->numero]">
        <a href="{{ route('relatorios') }}" class="botao-secundario">Cancelar</a>
        <button wire:click="enviar" wire:loading.attr="disabled" wire:target="enviar" class="botao-primario">
            <span wire:loading.remove wire:target="enviar">Enviar email</span>
            <span wire:loading wire:target="enviar">A enviar…</span>
        </button>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-3xl">

            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Enviar relatório {{ $relatorio->numero }}</h1>
                    <p class="mt-2 text-sm text-texto-medio">Escreva o email antes de enviar. O PDF do relatório vai anexado automaticamente.</p>
                </div>
                @if ($relatorio->estado === \App\Enums\EstadoRelatorio::Enviado)
                    <span class="etiqueta {{ \App\Enums\EstadoRelatorio::Enviado->classesEtiqueta() }} uppercase tracking-wide">Reenvio</span>
                @endif
            </div>

            <section class="cartao mt-7">
                <div class="space-y-5 px-6 py-6">
                    <div>
                        <label class="campo-label" for="para">Para <span class="text-perigo-500">*</span></label>
                        <input id="para" wire:model="para" type="email" class="campo-input" placeholder="cliente@dominio.pt" autocomplete="off">
                        @error('para') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="campo-label" for="assunto">Assunto <span class="text-perigo-500">*</span></label>
                        <input id="assunto" wire:model="assunto" type="text" class="campo-input" maxlength="255">
                        @error('assunto') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="campo-label" for="mensagem">Mensagem <span class="text-perigo-500">*</span></label>
                        <textarea id="mensagem" wire:model="mensagem" rows="10" class="campo-input resize-y" placeholder="Escreva a mensagem para o cliente…"></textarea>
                        @error('mensagem') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Anexo (PDF do relatório) --}}
                    <div class="flex items-center gap-2 rounded-lg border border-borda bg-fundo/60 px-3 py-2.5 text-sm text-texto-medio">
                        <svg class="h-4 w-4 shrink-0 text-verde-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                        Anexo: <span class="font-medium text-texto-forte">{{ str_replace('/', '-', $relatorio->numero) }}.pdf</span> (relatório de intervenção)
                    </div>
                </div>
            </section>

        </div>
    </main>
</div>
