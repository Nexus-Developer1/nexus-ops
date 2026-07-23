<div>
    <x-topbar :breadcrumb="['Equipamentos', $equipamento->numero_serie ?? 'Equipamento']">
        <a href="{{ route('equipamentos.associar', $equipamento) }}" wire:navigate class="botao-secundario">Alterar local</a>
        <button wire:click="novaIntervencao" class="botao-primario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
            Nova Intervenção
        </button>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            @if (session('sucesso'))
                <div class="mb-6 flex items-center gap-2 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm font-medium text-verde-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    {{ session('sucesso') }}
                </div>
            @endif

            {{-- Cabeçalho --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $equipamento->numero_serie ?? 'Equipamento' }}</h1>
                <span class="etiqueta {{ $equipamento->tipo->classesEtiqueta() }}">{{ $equipamento->tipo->rotulo() }}</span>
                <span class="etiqueta {{ $equipamento->estado->classesEtiqueta() }}">{{ $equipamento->estado->rotulo() }}</span>
            </div>
            <p class="mt-2 text-sm text-texto-medio">{{ $equipamento->local->cliente->nome }} · {{ $equipamento->local->designacao }} · {{ $equipamento->fabricante }} {{ $equipamento->modelo }}</p>

            <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-3">

                {{-- Coluna principal --}}
                <div class="space-y-6 lg:col-span-2">

                    {{-- Especificações --}}
                    <section class="cartao">
                        <div class="flex items-center gap-3 px-6 py-5">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span>
                            <h2 class="text-lg font-semibold text-texto-forte">Especificações</h2>
                        </div>
                        @if (count($especificacoes))
                            <div class="grid grid-cols-2 gap-x-8 gap-y-6 border-t border-borda px-6 py-6 sm:grid-cols-3">
                                @foreach ($especificacoes as $spec)
                                    <div>
                                        <div class="text-xs uppercase tracking-wide text-texto-fraco">{{ $spec['rotulo'] }}</div>
                                        <div class="mt-1 text-sm font-medium text-texto-forte">{{ $spec['valor'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="border-t border-borda px-6 py-6 text-sm text-texto-medio">Sem especificações registadas.</div>
                        @endif
                    </section>

                    {{-- Identificação: cliente final + localização da instalação (texto livre). --}}
                    <section class="cartao">
                        <div class="flex items-center gap-3 px-6 py-5">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></span>
                            <h2 class="text-lg font-semibold text-texto-forte">Cliente final e localização</h2>
                        </div>
                        <div class="grid grid-cols-1 gap-x-8 gap-y-6 border-t border-borda px-6 py-6 sm:grid-cols-2">
                            <div>
                                <label class="campo-label">Cliente final</label>
                                <input wire:model="clienteFinal" type="text" class="campo-input" placeholder="Utilizador real do equipamento">
                                @error('clienteFinal') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="campo-label">Localização da instalação</label>
                                <input wire:model="localizacaoInstalacao" type="text" class="campo-input" placeholder="Ex: Edifício B, piso 2, sala UPS">
                                @error('localizacaoInstalacao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2 flex justify-end">
                                <button wire:click="guardarIdentificacao" wire:loading.attr="disabled" wire:target="guardarIdentificacao" class="botao-primario">Guardar</button>
                            </div>
                        </div>
                    </section>

                    {{-- Banco de baterias (parte deste equipamento). --}}
                    <section class="cartao">
                        <div class="flex items-center gap-3 px-6 py-5">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span>
                            <h2 class="text-lg font-semibold text-texto-forte">Banco de baterias</h2>
                        </div>

                        {{-- Este equipamento É um banco/kit associado a um UPS → link para o pai. --}}
                        @if ($equipamentoPai)
                            <div class="border-t border-borda px-6 py-5">
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-info-100 bg-info-100/40 px-4 py-3">
                                    <div class="text-sm text-texto-medio">
                                        Associado ao equipamento
                                        <a href="{{ route('equipamentos.ficha', $equipamentoPai) }}" wire:navigate class="font-medium text-info-600 hover:underline">{{ $equipamentoPai->numero_serie ?? '—' }}</a>
                                        <span class="text-xs text-texto-fraco"> · {{ trim(($equipamentoPai->fabricante ?? '') . ' ' . ($equipamentoPai->modelo ?? '')) ?: '—' }}</span>
                                    </div>
                                </div>
                            </div>
                        @else
                            {{-- Bancos/kits (equipamentos próprios) associados a este UPS. --}}
                            <div class="border-t border-borda px-6 py-5">
                                @if ($bancosAssociados->isNotEmpty())
                                    <ul class="space-y-2">
                                        @foreach ($bancosAssociados as $banco)
                                            <li wire:key="banco-{{ $banco->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-borda bg-fundo px-4 py-3">
                                                <div class="min-w-0">
                                                    <a href="{{ route('equipamentos.ficha', $banco) }}" wire:navigate class="text-sm font-medium text-texto-forte hover:underline">{{ $banco->numero_serie ?? '—' }}</a>
                                                    <div class="mt-0.5 truncate text-xs text-texto-fraco">{{ trim(($banco->fabricante ?? '') . ' ' . ($banco->modelo ?? '')) ?: '—' }} · {{ $banco->local?->cliente?->nome ?? '—' }} · {{ $banco->local?->designacao ?? '—' }}</div>
                                                </div>
                                                @unless (auth()->user()->ehCliente())
                                                    <button wire:click="desassociarBanco({{ $banco->id }})" wire:confirm="Desassociar este banco de baterias?" class="text-xs font-medium text-perigo-500 hover:text-perigo-600">Desassociar</button>
                                                @endunless
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="text-sm text-texto-medio">Nenhum banco de baterias associado a este equipamento.</p>
                                @endif

                                @unless (auth()->user()->ehCliente())
                                    {{-- Combobox server-side: pesquisa por nº de série, modelo ou local; sem texto sugere bancos livres no mesmo local. --}}
                                    <div class="mt-4">
                                        <label class="campo-label" for="banco-combo">Associar equipamento (banco/kit de baterias)</label>
                                        <div wire:key="combo-banco" x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                            <input id="banco-combo" type="text"
                                                wire:model.live.debounce.300ms="bancoBusca"
                                                @focus="aberto = true" @click="aberto = true" @input="aberto = true; destaque = 0"
                                                @keydown.arrow-down.prevent="aberto = true; if ($refs['b' + (destaque + 1)]) destaque++"
                                                @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                                @keydown.enter.prevent="$refs['b' + destaque]?.click()"
                                                class="campo-input pr-10" placeholder="Pesquisar por nº de série ou local..." autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                            <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                            <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                                @forelse ($bancosFiltrados as $idx => $b)
                                                    <li x-ref="b{{ $idx }}" wire:key="bf-{{ $b->id }}"
                                                        wire:click="associarBanco({{ $b->id }})" @click="aberto = false"
                                                        @mouseenter="destaque = {{ $idx }}"
                                                        :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                                        class="cursor-pointer px-4 py-2 text-sm" role="option">
                                                        <span class="font-medium text-texto-forte">{{ $b->numero_serie ?? '—' }}</span>
                                                        <span class="text-xs text-texto-fraco"> · {{ $b->modelo ?? '—' }} · {{ $b->local?->cliente?->nome ?? 'sem cliente' }} · {{ $b->local?->designacao ?? '—' }}</span>
                                                    </li>
                                                @empty
                                                    <li class="px-4 py-2 text-sm text-texto-medio">{{ trim($bancoBusca) === '' ? 'Sem bancos livres neste local — pesquise por nº de série ou local.' : 'Nenhum equipamento encontrado.' }}</li>
                                                @endforelse
                                            </ul>
                                        </div>
                                        @error('bancoBusca') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                    </div>
                                @endunless
                            </div>
                        @endif

                        <div class="border-t border-borda px-6 py-6">
                            <div class="mb-3 flex items-center justify-between">
                                <p class="text-sm text-texto-medio">Bancos que fazem parte deste equipamento (podes ter vários).</p>
                                <button wire:click="adicionarBanco" class="botao-secundario">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                                    Banco
                                </button>
                            </div>
                            @forelse ($bancos as $i => $banco)
                                <div wire:key="fbanco-{{ $i }}" class="mb-4 rounded-lg border border-borda p-4 last:mb-0">
                                    <div class="mb-3 flex items-center justify-between">
                                        <span class="text-sm font-semibold text-texto-medio">Banco {{ $i + 1 }}</span>
                                        <button wire:click="removerBanco({{ $i }})" class="text-xs font-medium text-texto-fraco hover:text-perigo-600">Remover</button>
                                    </div>
                                    <div class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
                                        <div>
                                            <label class="campo-label">Nº de série do banco</label>
                                            <input wire:model="bancos.{{ $i }}.numero_serie" type="text" class="campo-input" placeholder="Identificação do banco">
                                            @error('bancos.'.$i.'.numero_serie') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="campo-label">Modelo / fabricante</label>
                                            <input wire:model="bancos.{{ $i }}.modelo" type="text" class="campo-input" placeholder="Ex: Riello / bloco 12V">
                                            @error('bancos.'.$i.'.modelo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="campo-label">Capacidade (Ah / V)</label>
                                            <input wire:model="bancos.{{ $i }}.capacidade" type="text" class="campo-input" placeholder="Ex: 7 Ah / 384 V">
                                            @error('bancos.'.$i.'.capacidade') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="campo-label">Nº de baterias</label>
                                            <input wire:model="bancos.{{ $i }}.num_baterias" type="number" min="0" class="campo-input" placeholder="Ex: 16">
                                            @error('bancos.'.$i.'.num_baterias') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="campo-label">Data de instalação</label>
                                            <input wire:model="bancos.{{ $i }}.data_instalacao" type="date" class="campo-input">
                                            @error('bancos.'.$i.'.data_instalacao') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="campo-label">Próxima troca</label>
                                            <input wire:model="bancos.{{ $i }}.proxima_troca" type="date" class="campo-input">
                                            @error('bancos.'.$i.'.proxima_troca') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-texto-medio">Sem bancos de baterias. Usa "Banco" para adicionar (a próxima troca mais próxima alimenta os alertas de manutenção).</p>
                            @endforelse
                            <div class="mt-4 flex justify-end">
                                <button wire:click="guardarBanco" wire:loading.attr="disabled" wire:target="guardarBanco" class="botao-primario">Guardar bancos</button>
                            </div>
                        </div>
                    </section>

                    {{-- Componentes do sistema (equipamentos compostos, ex.: deteção de incêndio). --}}
                    <section class="cartao">
                        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                            <div class="flex items-center gap-3">
                                <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg></span>
                                <h2 class="text-lg font-semibold text-texto-forte">Componentes do sistema</h2>
                            </div>
                            <button wire:click="adicionarComponente" class="botao-secundario">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                                Componente
                            </button>
                        </div>
                        <div class="border-t border-borda px-6 py-6">
                            @forelse ($componentes as $i => $comp)
                                <div class="mb-3 flex items-start gap-3 last:mb-0" wire:key="fcomp-{{ $i }}">
                                    <input wire:model="componentes.{{ $i }}.designacao" type="text" class="campo-input flex-1" placeholder="Ex: Detetor ótico convencional 701P">
                                    <input wire:model="componentes.{{ $i }}.quantidade" type="number" min="1" class="campo-input w-24" placeholder="Qtd">
                                    <button wire:click="removerComponente({{ $i }})" class="mt-2 shrink-0 text-texto-fraco hover:text-perigo-600" title="Remover">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            @empty
                                <p class="text-sm text-texto-medio">Sem componentes. Usa "+ Componente" para os listar (cilindro, detetores, baterias, botoneiras…).</p>
                            @endforelse
                            <div class="mt-4 flex justify-end">
                                <button wire:click="guardarComponentes" wire:loading.attr="disabled" wire:target="guardarComponentes" class="botao-primario">Guardar componentes</button>
                            </div>
                        </div>
                    </section>

                    {{-- Notas --}}
                    <section class="cartao">
                        <div class="flex items-center gap-3 px-6 py-5">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></span>
                            <h2 class="text-lg font-semibold text-texto-forte">Notas</h2>
                        </div>
                        <div class="border-t border-borda px-6 py-6">
                            <textarea wire:model="notas" rows="4" class="campo-input" placeholder="Observações sobre este equipamento..."></textarea>
                            @error('notas') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            <div class="mt-3 flex justify-end">
                                <button wire:click="guardarNotas" wire:loading.attr="disabled" wire:target="guardarNotas" class="botao-primario">Guardar notas</button>
                            </div>
                        </div>
                    </section>

                    {{-- Histórico de intervenções --}}
                    <section class="cartao">
                        <div class="flex items-center justify-between px-6 py-5">
                            <h2 class="text-lg font-semibold text-texto-forte">Histórico de Intervenções</h2>
                            <span class="text-sm text-texto-fraco">{{ $intervencoes->count() }}</span>
                        </div>
                        @forelse ($intervencoes as $i)
                            <div class="flex items-center gap-4 border-t border-borda px-6 py-4">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $i->estado->classesEtiqueta() }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-texto-forte">
                                        {{ $i->tipo->rotulo() }}@if ($i->descricao_problema) — {{ \Illuminate\Support\Str::limit($i->descricao_problema, 48) }}@endif
                                    </div>
                                    <div class="text-xs text-texto-fraco">{{ $i->tecnico?->nome ?? 'Sem técnico' }} · {{ $i->data_inicio?->translatedFormat('d M Y') ?? '—' }}</div>
                                </div>
                                <span class="etiqueta {{ $i->estado->classesEtiqueta() }}">{{ $i->estado->rotulo() }}</span>
                            </div>
                        @empty
                            <div class="border-t border-borda px-6 py-10 text-center">
                                <p class="text-sm text-texto-medio">Ainda sem intervenções registadas.</p>
                                <p class="mt-1 text-xs text-texto-fraco">Use "Nova Intervenção" para iniciar uma.</p>
                            </div>
                        @endforelse
                    </section>
                </div>

                {{-- Coluna lateral --}}
                <div class="space-y-6">

                    {{-- Identificação + QR --}}
                    <section class="cartao p-6">
                        <div class="flex items-start justify-between">
                            <h2 class="text-base font-semibold text-texto-forte">Identificação</h2>
                            <div class="flex h-16 w-16 items-center justify-center rounded-lg border border-borda bg-fundo text-texto-fraco">
                                <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 3h3m0 0h3m-3 0v3m0-3v-3"/></svg>
                            </div>
                        </div>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between gap-4"><dt class="shrink-0 text-texto-medio">Nº de série</dt><dd class="text-right font-medium text-texto-forte">{{ $equipamento->numero_serie ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="shrink-0 text-texto-medio">Fabricante</dt><dd class="text-right font-medium text-texto-forte">{{ $equipamento->fabricante ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="shrink-0 text-texto-medio">Modelo</dt><dd class="text-right font-medium text-texto-forte">{{ $equipamento->modelo ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="shrink-0 text-texto-medio">Instalação</dt><dd class="text-right font-medium text-texto-forte">{{ $equipamento->data_instalacao?->translatedFormat('d M Y') ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="shrink-0 text-texto-medio">Fim de garantia</dt><dd class="text-right font-medium text-texto-forte">{{ $equipamento->fim_garantia?->translatedFormat('d M Y') ?? '—' }}</dd></div>
                        </dl>
                    </section>

                    {{-- Contrato(s) associado(s) via contrato_equipamentos --}}
                    <section class="cartao p-6">
                        <h2 class="text-base font-semibold text-texto-forte">{{ $contratos->count() > 1 ? 'Contratos' : 'Contrato' }}</h2>
                        @if ($contratos->isEmpty())
                            <p class="mt-3 text-sm text-texto-medio">Sem contrato associado.</p>
                        @else
                            <div class="mt-3 space-y-2">
                                @foreach ($contratos as $contrato)
                                    <a href="{{ route('contratos.ficha', $contrato) }}" wire:navigate wire:key="contrato-{{ $contrato->id }}"
                                       class="flex items-center justify-between gap-3 rounded-lg border border-borda px-3 py-2.5 transition hover:border-verde-300 hover:bg-verde-50/40">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-texto-forte">{{ $contrato->numero }}</span>
                                            <span class="block truncate text-xs text-texto-medio">{{ $contrato->tipo->rotulo() }} · {{ $contrato->data_inicio?->format('d/m/Y') ?? '—' }} – {{ $contrato->data_fim?->format('d/m/Y') ?? '—' }}</span>
                                        </span>
                                        <span class="etiqueta shrink-0 {{ $contrato->estado->classesEtiqueta() }}">{{ $contrato->estado->rotulo() }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    {{-- Alerta de baterias --}}
                    @if ($equipamento->proxima_troca_baterias)
                        <section class="rounded-xl border border-aviso-200 bg-aviso-100/60 p-5">
                            <div class="flex gap-3">
                                <svg class="h-5 w-5 shrink-0 text-aviso-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                <div>
                                    <div class="text-sm font-semibold text-texto-forte">Troca de baterias</div>
                                    <div class="mt-1 text-xs text-texto-medio">Próxima troca recomendada para <span class="font-medium text-texto-forte">{{ $equipamento->proxima_troca_baterias->translatedFormat('d M Y') }}</span>.</div>
                                </div>
                            </div>
                        </section>
                    @endif
                </div>
            </div>

        </div>
    </main>
</div>
