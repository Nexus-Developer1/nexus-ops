<div>
    <x-topbar :breadcrumb="['Relatórios', 'Enviar ' . $relatorio->numero]">
        <a href="{{ route('relatorios') }}" class="botao-secundario">Cancelar</a>
        <button wire:click="enviar" wire:loading.attr="disabled" wire:target="enviar" class="botao-primario">
            <span wire:loading.remove wire:target="enviar">{{ $quando === 'agora' ? 'Enviar email' : 'Agendar envio' }}</span>
            <span wire:loading wire:target="enviar">{{ $quando === 'agora' ? 'A enviar…' : 'A agendar…' }}</span>
        </button>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-screen-2xl">

            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Enviar relatório {{ $relatorio->numero }}</h1>
                    <p class="mt-2 text-sm text-texto-medio">Escreva o email antes de enviar. O PDF do relatório vai anexado automaticamente.</p>
                </div>
                @if ($relatorio->estado === \App\Enums\EstadoRelatorio::Enviado)
                    <span class="etiqueta {{ \App\Enums\EstadoRelatorio::Enviado->classesEtiqueta() }} uppercase tracking-wide">Reenvio</span>
                @endif
            </div>

            {{-- Envio agendado à espera (out. 2026): vê-se e cancela-se aqui. Enviar ou agendar de
                 novo substitui-o — o cliente nunca recebe duas vezes. --}}
            @if ($relatorio->temEnvioAgendado())
                <div class="mt-6 flex flex-wrap items-center gap-3 rounded-lg border border-aviso-200 bg-aviso-100 px-4 py-3 text-sm text-aviso-500">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="flex-1">Envio agendado para <strong>{{ $relatorio->envio_agendado_em->format('d/m') }} às {{ $relatorio->envio_agendado_em->format('H:i') }}</strong>, para {{ $relatorio->envio_agendado_destino }}.</span>
                    <button type="button" wire:click="cancelarAgendamento" wire:confirm="Cancelar o envio agendado? Nada será enviado ao cliente." class="font-medium underline hover:no-underline">Cancelar envio agendado</button>
                </div>
            @endif

            <section class="cartao mt-7">
                <div class="space-y-5 px-6 py-6">
                    <div>
                        <label class="campo-label" for="para">Para <span class="text-perigo-500">*</span></label>
                        {{-- type=text (não email): aceita vários endereços separados por «;». --}}
                        <input id="para" wire:model="para" type="text" class="campo-input" placeholder="cliente@dominio.pt; outro@dominio.pt" autocomplete="off">
                        @error('para') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        {{-- Quem envia recebe sempre cópia (pedido da equipa, set. 2026). --}}
                        @if (auth()->user()->email)
                            <p class="mt-1.5 text-xs text-texto-fraco">Recebes uma cópia em {{ auth()->user()->email }}.</p>
                        @endif
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

                    <div class="sm:max-w-xs">
                        <label class="campo-label" for="quando">Envio <span class="text-perigo-500">*</span></label>
                        <select id="quando" wire:model.live="quando" class="campo-select">
                            @foreach ($opcoesEnvio as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('quando') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Aviso ao comercial (out. 2026): o serviço pode ser faturado + nº da encomenda de
                         peças. Sai à mesma hora que o relatório (também se for agendado). --}}
                    <div class="rounded-lg border border-borda px-4 py-3">
                        <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium text-texto-forte">
                            <input type="checkbox" wire:model.live="avisarComercial" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-600">
                            Avisar o comercial de que o serviço pode ser faturado
                        </label>
                        @if ($avisarComercial)
                            <div class="mt-3">
                                <label class="campo-label" for="comercial">Comercial <span class="text-perigo-500">*</span></label>
                                <input id="comercial" wire:model="comercial" type="text" list="lista-comerciais" class="campo-input" placeholder="comercial@nxs.pt" autocomplete="off">
                                <datalist id="lista-comerciais">
                                    @foreach ($comerciais as $email)
                                        <option value="{{ $email }}"></option>
                                    @endforeach
                                </datalist>
                                @error('comercial') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                @if ($vendedorPhc)
                                    <p class="mt-1.5 text-xs text-texto-fraco">Vendedor deste cliente no PHC: {{ $vendedorPhc }}</p>
                                @endif
                                <p class="mt-2 text-sm text-texto-medio">
                                    Encomenda de peças:
                                    @if ($encomendas === [])
                                        <span class="text-texto-fraco">sem encomenda associada</span>
                                    @else
                                        <span class="font-medium text-texto-forte">{{ implode(' · ', $encomendas) }}</span>
                                    @endif
                                </p>
                            </div>
                        @endif
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
