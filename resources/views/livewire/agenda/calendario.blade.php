<div>
    <x-topbar :breadcrumb="['Manutenção', 'Agenda']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl" x-data="agendaCalendario">

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Agenda</h1>
                    <p class="mt-2 text-sm text-texto-medio">Arraste para reagendar · selecione um intervalo livre para criar evento ou ausência.</p>
                </div>
                <div class="flex items-center gap-3">
                    @if ($urlIcal)
                        <a href="{{ $urlIcal }}" class="botao-secundario" title="Subscrever em calendário externo">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            iCal
                        </a>
                    @endif
                    @unless (auth()->user()->ehTecnico())
                        <select wire:model.live="tecnicoId" class="campo-select w-56">
                            <option value="">Todos os técnicos</option>
                            @foreach ($tecnicos as $t)
                                <option value="{{ $t['id'] }}">{{ $t['nome'] }}</option>
                            @endforeach
                        </select>
                    @endunless
                </div>
            </div>

            {{-- Aviso de conflito ao reagendar --}}
            <div x-show="erro" x-cloak x-transition
                 class="mt-6 flex items-center gap-2 rounded-lg border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm font-medium text-perigo-600">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span x-text="erro"></span>
            </div>

            {{-- Calendário (FullCalendar montado pelo Alpine) --}}
            <div class="cartao mt-6 p-5">
                <div x-ref="cal" wire:ignore></div>
            </div>

            {{-- Painel de detalhe do evento (iniciar visita → intervenção) --}}
            @if ($evento)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fecharModal">
                    <div class="w-full max-w-md rounded-xl border border-borda bg-white shadow-xl">
                        <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                            <div>
                                <h2 class="text-lg font-semibold text-texto-forte">{{ $evento->titulo }}</h2>
                                <p class="mt-1 text-sm text-texto-medio">{{ $evento->tipo->rotulo() }} · {{ $evento->estado->rotulo() }}</p>
                            </div>
                            <button wire:click="fecharModal" class="text-texto-fraco hover:text-texto-forte">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <dl class="space-y-3 px-6 py-5 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-texto-fraco">Quando</dt>
                                <dd class="text-right font-medium text-texto-forte">{{ $evento->inicio->translatedFormat('d M Y · H:i') }} – {{ $evento->fim->format('H:i') }}</dd>
                            </div>
                            @if ($evento->cliente)
                                <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Cliente</dt><dd class="text-right font-medium text-texto-forte">{{ $evento->cliente->nome }}</dd></div>
                            @endif
                            @if ($evento->equipamento)
                                <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Equipamento</dt><dd class="text-right font-medium text-texto-forte">{{ trim($evento->equipamento->fabricante . ' ' . $evento->equipamento->modelo) ?: $evento->equipamento->numero_serie }}</dd></div>
                            @endif
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Técnico</dt><dd class="text-right font-medium text-texto-forte">{{ $evento->tecnico?->nome ?? 'Por atribuir' }}</dd></div>
                        </dl>

                        <div class="flex items-center justify-end gap-3 border-t border-borda px-6 py-4">
                            @if ($evento->intervencao_id)
                                <a href="{{ route('intervencoes.formulario', $evento->intervencao_id) }}" class="botao-primario">Abrir intervenção</a>
                            @elseif (in_array($evento->tipo, [\App\Enums\TipoEvento::VisitaPreventiva, \App\Enums\TipoEvento::Intervencao]))
                                <button wire:click="fecharModal" class="botao-secundario">Fechar</button>
                                <button wire:click="iniciarVisita" wire:loading.attr="disabled" class="botao-primario">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Iniciar visita
                                </button>
                            @elseif ($evento->tipo === \App\Enums\TipoEvento::Outro)
                                <button wire:click="removerEvento" wire:confirm="Remover este evento?" class="botao inline-flex items-center gap-2 bg-perigo-600 px-5 py-2.5 text-white hover:bg-perigo-500">Remover</button>
                                <button wire:click="fecharModal" class="botao-secundario">Fechar</button>
                            @else
                                <button wire:click="fecharModal" class="botao-secundario">Fechar</button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Painel de detalhe de uma ausência --}}
            @if ($ausencia)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fecharAusencia">
                    <div class="w-full max-w-md rounded-xl border border-borda bg-white shadow-xl">
                        <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                            <h2 class="text-lg font-semibold text-texto-forte">Ausência</h2>
                            <button wire:click="fecharAusencia" class="text-texto-fraco hover:text-texto-forte">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <dl class="space-y-3 px-6 py-5 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Técnico</dt><dd class="text-right font-medium text-texto-forte">{{ $ausencia->tecnico?->nome }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Motivo</dt><dd class="text-right font-medium text-texto-forte">{{ $ausencia->motivo ?: '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">De</dt><dd class="text-right font-medium text-texto-forte">{{ $ausencia->inicio->translatedFormat('d M Y · H:i') }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Até</dt><dd class="text-right font-medium text-texto-forte">{{ $ausencia->fim->translatedFormat('d M Y · H:i') }}</dd></div>
                        </dl>
                        <div class="flex items-center justify-end gap-3 border-t border-borda px-6 py-4">
                            <button wire:click="fecharAusencia" class="botao-secundario">Fechar</button>
                            <button wire:click="removerAusencia" wire:confirm="Remover esta ausência?" class="botao inline-flex items-center gap-2 bg-perigo-600 px-5 py-2.5 text-white hover:bg-perigo-500">Remover</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Modal de criação de evento próprio / ausência --}}
            @if ($modalCriar)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fecharCriar">
                    <form wire:submit="criarEvento" class="w-full max-w-md rounded-xl border border-borda bg-white shadow-xl">
                        <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                            <h2 class="text-lg font-semibold text-texto-forte">Novo evento</h2>
                            <button type="button" wire:click="fecharCriar" class="text-texto-fraco hover:text-texto-forte">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="space-y-5 px-6 py-5">
                            <div>
                                <label class="campo-label">Tipo</label>
                                <select wire:model.live="formTipo" class="campo-select">
                                    <option value="outro">Evento próprio (reunião/outro)</option>
                                    <option value="ausencia">Ausência de técnico</option>
                                </select>
                            </div>

                            @if ($formTipo === 'outro')
                                <div>
                                    <label class="campo-label">Título</label>
                                    <input wire:model="formTitulo" type="text" class="campo-input" placeholder="Ex: Reunião de equipa">
                                    @error('formTitulo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                            @else
                                <div>
                                    <label class="campo-label">Motivo</label>
                                    <input wire:model="formMotivo" type="text" class="campo-input" placeholder="Ex: Férias">
                                </div>
                            @endif

                            <div>
                                <label class="campo-label">Técnico {{ $formTipo === 'ausencia' ? '' : '(opcional)' }}</label>
                                <select wire:model="formTecnicoId" class="campo-select">
                                    <option value="">{{ $formTipo === 'ausencia' ? 'Selecione...' : 'Por atribuir' }}</option>
                                    @foreach ($tecnicos as $t)
                                        <option value="{{ $t['id'] }}">{{ $t['nome'] }}</option>
                                    @endforeach
                                </select>
                                @error('formTecnicoId') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="campo-label">Início</label>
                                    <input wire:model="formInicio" type="datetime-local" class="campo-input">
                                    @error('formInicio') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="campo-label">Fim</label>
                                    <input wire:model="formFim" type="datetime-local" class="campo-input">
                                    @error('formFim') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-borda px-6 py-4">
                            <button type="button" wire:click="fecharCriar" class="botao-secundario">Cancelar</button>
                            <button type="submit" class="botao-primario">Criar</button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Legenda de técnicos --}}
            <div class="mt-5 flex flex-wrap items-center gap-6 text-sm text-texto-medio">
                @foreach ($tecnicos as $t)
                    <span class="inline-flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full" style="background-color: {{ $t['cor'] }}"></span> {{ $t['nome'] }}
                    </span>
                @endforeach
                <span class="inline-flex items-center gap-2">
                    <span class="h-3 w-3 rounded-full" style="background-color: #94a3b8"></span> Por atribuir
                </span>
            </div>

        </div>
    </main>
</div>
