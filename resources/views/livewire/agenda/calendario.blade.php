<div>
    <x-topbar :breadcrumb="['Manutenção', 'Agenda']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl" x-data="agendaCalendario">

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Agenda</h1>
                    <p class="mt-2 text-sm text-texto-medio">Arraste para reagendar · selecione um intervalo livre para criar evento.</p>
                </div>
                <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                    {{-- Filtro por técnico (pelo nome usado nos eventos). Disponível para todos
                         (técnico = admin). Vazio = todos os técnicos. --}}
                    <select wire:model.live="tecnicoNome" class="campo-select w-full sm:w-56">
                        <option value="">Todos os técnicos</option>
                        @foreach ($nomesTecnicos as $t)
                            <option value="{{ $t['nome'] }}">{{ $t['nome'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Confirmação (ex.: rascunho de relatório gerado a partir do evento) --}}
            @if (session('sucesso'))
                <div class="mt-6 flex items-center gap-2 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm font-medium text-verde-700">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    {{ session('sucesso') }}
                </div>
            @endif

            {{-- Aviso de conflito ao reagendar --}}
            <div x-show="erro" x-cloak x-transition
                 class="mt-6 flex items-center gap-2 rounded-lg border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm font-medium text-perigo-600">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span x-text="erro"></span>
            </div>

            {{-- Calendário (FullCalendar montado pelo Alpine) --}}
            <div class="cartao mt-6 p-3 sm:p-5">
                <div x-ref="cal" wire:ignore></div>
            </div>

            {{-- Painel de detalhe do evento (iniciar visita → intervenção) --}}
            @if ($evento)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fecharModal">
                    <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-borda bg-white shadow-xl">
                        <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                            <div>
                                <h2 class="text-lg font-semibold text-texto-forte">{{ $evento->titulo }}</h2>
                                <p class="mt-1 text-sm text-texto-medio">{{ $evento->tipo->rotulo() }} · {{ $evento->estado->rotulo() }}</p>
                            </div>
                            <button wire:click="fecharModal" class="-m-2 flex items-center justify-center rounded-lg p-2 text-texto-fraco transition hover:bg-fundo hover:text-texto-forte">
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
                            <div class="flex justify-between gap-4"><dt class="text-texto-fraco">Técnico</dt><dd class="text-right font-medium text-texto-forte">{{ $evento->tecnico_label ?? 'Por atribuir' }}</dd></div>
                        </dl>

                        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-borda px-6 py-4">
                            {{-- Editar: eventos não-preventivos cujo relatório (se existir) ainda é rascunho.
                                 Relatório finalizado/enviado = documento oficial → evento trancado. --}}
                            @if ($eventoEditavel)
                                <button wire:click="abrirEdicao" class="botao-secundario">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Editar
                                </button>
                            @endif
                            @if ($evento->intervencao_id)
                                @php($rel = $evento->intervencao?->relatorio)
                                @if ($evento->tipo !== \App\Enums\TipoEvento::VisitaPreventiva)
                                    @if ($rel && $rel->estado !== \App\Enums\EstadoRelatorio::Rascunho)
                                        <span class="mr-auto text-xs text-texto-fraco">Relatório finalizado (nº {{ $rel->numero }}) — não removível</span>
                                    @else
                                        <button wire:click="removerEvento" wire:confirm="{{ $rel ? 'Remover este evento e o rascunho de relatório associado?' : 'Remover este evento?' }}" class="botao inline-flex items-center gap-2 bg-perigo-600 px-5 py-2.5 text-white hover:bg-perigo-500">Remover</button>
                                    @endif
                                @endif
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

            {{-- Modal de criação/edição de evento próprio (campo único "Tipo de evento") --}}
            @if ($modalCriar)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" wire:click.self="fecharCriar">
                    <form wire:submit="criarEvento" class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-borda bg-white shadow-xl">
                        <div class="flex items-start justify-between border-b border-borda px-6 py-5">
                            <h2 class="text-lg font-semibold text-texto-forte">{{ $editandoId ? 'Editar evento' : 'Novo evento' }}</h2>
                            <button type="button" wire:click="fecharCriar" class="-m-2 flex items-center justify-center rounded-lg p-2 text-texto-fraco transition hover:bg-fundo hover:text-texto-forte">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="space-y-5 px-6 py-5">
                            {{-- Tipo de evento: campo único pesquisável ligado ao lookup (cresce com o uso).
                                 Filtra os existentes à medida que se escreve; "Adicionar «X»" guarda um novo. --}}
                            <div
                                x-data="{
                                    tipos: @js($assuntos->map(fn ($a) => ['nome' => $a->nome])->values()),
                                    titulo: $wire.entangle('formTitulo'),
                                    aberto: false,
                                    destaque: 0,
                                    norm(s) { return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
                                    get filtrados() {
                                        const n = this.norm(this.titulo);
                                        if (n === '') return this.tipos;
                                        return this.tipos.filter(t => this.norm(t.nome).includes(n));
                                    },
                                    get existeExato() {
                                        const n = this.norm(this.titulo);
                                        return n !== '' && this.tipos.some(t => this.norm(t.nome) === n);
                                    },
                                    abrir() { this.aberto = true; this.destaque = 0; },
                                    fechar() { this.aberto = false; },
                                    escolher(nome) { this.titulo = nome; this.aberto = false; },
                                    adicionar() {
                                        const v = (this.titulo || '').trim();
                                        if (v === '') return;
                                        this.$wire.adicionarAssunto(v).then(ok => {
                                            if (ok) { this.tipos.push({ nome: v }); this.aberto = false; }
                                        });
                                    },
                                }"
                                @click.outside="fechar()"
                                @keydown.escape.stop="fechar()"
                                class="relative"
                            >
                                <label class="campo-label" for="tipo-combo">Tipo de evento</label>
                                <input id="tipo-combo" type="text" x-model="titulo"
                                    @focus="abrir()" @click="abrir()" @input="abrir()"
                                    @keydown.enter.prevent="existeExato ? fechar() : adicionar()"
                                    class="campo-input" placeholder="Ex: Reunião" autocomplete="off"
                                    role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                    <template x-for="(t, i) in filtrados" :key="t.nome">
                                        <li @click="escolher(t.nome)" @mouseenter="destaque = i" :class="i === destaque ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'" class="cursor-pointer px-4 py-2 text-sm" role="option">
                                            <span x-text="t.nome"></span>
                                        </li>
                                    </template>
                                    <li x-show="titulo && titulo.trim() !== '' && !existeExato" @click="adicionar()" class="flex cursor-pointer items-center gap-1.5 px-4 py-2 text-sm font-medium text-verde-600 hover:bg-verde-50">
                                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                                        <span>Adicionar «<span x-text="titulo.trim()"></span>»</span>
                                    </li>
                                    <li x-show="filtrados.length === 0 && (!titulo || titulo.trim() === '')" class="px-4 py-2 text-sm text-texto-medio">Escreva para criar um tipo de evento.</li>
                                </ul>
                                @error('formTitulo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                @error('novoAssunto') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>

                            {{-- Equipamento opcional: pesquisa server-side (~17k registos, nunca carregar tudo).
                                 Ao escolher, o evento herda local e cliente do equipamento. --}}
                            <div>
                                <label class="campo-label" for="equip-evento-combo">Equipamento (opcional)</label>
                                @if ($editandoConvertido)
                                    <p class="mb-1.5 text-xs text-texto-fraco">Este evento já tem relatório em rascunho: o equipamento principal gere-se no relatório; pode <strong>acrescentar mais</strong> aqui — entram como equipamentos cobertos do relatório.</p>
                                @else
                                    <p class="mb-1.5 text-xs text-texto-fraco">O 1.º escolhido é o principal (dá o cliente ao evento); pode acrescentar mais do mesmo cliente.</p>
                                @endif
                                <div x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
                                    <input
                                        id="equip-evento-combo"
                                        type="text"
                                        wire:model.live.debounce.300ms="formEquipamentoBusca"
                                        @focus="aberto = true"
                                        @click="aberto = true"
                                        @input="aberto = true; destaque = 0"
                                        @keydown.arrow-down.prevent="aberto = true; if ($refs['eopt' + (destaque + 1)]) destaque++"
                                        @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
                                        @keydown.enter.prevent="$refs['eopt' + destaque]?.click()"
                                        class="campo-input pr-10"
                                        placeholder="Pesquisar por cliente, nº de série, fabricante ou modelo... (opcional)"
                                        autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
                                    <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>

                                    <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
                                        @forelse ($equipamentosFiltrados as $idx => $eq)
                                            <li x-ref="eopt{{ $idx }}" wire:key="eq-{{ $eq->id }}"
                                                wire:click="selecionarEquipamento({{ $eq->id }})"
                                                @click="aberto = false"
                                                @mouseenter="destaque = {{ $idx }}"
                                                :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                                                class="cursor-pointer px-4 py-2 text-sm" role="option">
                                                <span class="font-medium">{{ $eq->numero_serie ?? '—' }}</span>
                                                <span class="text-xs text-texto-fraco"> · {{ trim($eq->fabricante . ' ' . $eq->modelo) ?: '—' }} · {{ $eq->local?->cliente?->nome ?? '—' }}</span>
                                            </li>
                                        @empty
                                            <li class="px-4 py-2 text-sm text-texto-medio">
                                                {{ $formEquipamentoBusca === '' ? 'Escreva para pesquisar…' : 'Nenhum equipamento encontrado.' }}
                                            </li>
                                        @endforelse
                                    </ul>
                                </div>
                                @error('formEquipamentoId') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                @error('formEquipamentosExtra.*') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror

                                {{-- Chips: principal (verde) + adicionais (com ×). Num evento convertido o principal
                                     não sai daqui (é do relatório); os adicionais sim. --}}
                                @if ($equipamentoPrincipal || $equipamentosExtra->isNotEmpty())
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @if ($equipamentoPrincipal)
                                            <span wire:key="chip-eq-{{ $equipamentoPrincipal->id }}" title="Principal · {{ $equipamentoPrincipal->local?->cliente?->nome ?? '—' }}" class="inline-flex items-center gap-1.5 rounded-full border border-verde-200 bg-verde-50 px-3 py-1 text-xs font-medium text-verde-700">
                                                {{ $equipamentoPrincipal->numero_serie ?? '—' }} <span class="font-normal text-verde-600">· {{ trim($equipamentoPrincipal->fabricante . ' ' . $equipamentoPrincipal->modelo) ?: '—' }}</span>
                                            </span>
                                        @endif
                                        @foreach ($equipamentosExtra as $eq)
                                            <span wire:key="chip-eq-{{ $eq->id }}" title="{{ $eq->local?->cliente?->nome ?? '—' }}" class="inline-flex items-center gap-1.5 rounded-full border border-borda bg-fundo px-3 py-1 text-xs text-texto-forte">
                                                {{ $eq->numero_serie ?? '—' }} <span class="text-texto-fraco">· {{ trim($eq->fabricante . ' ' . $eq->modelo) ?: '—' }}</span>
                                                <button type="button" wire:click="removerEquipamentoExtra({{ $eq->id }})" class="ml-0.5 rounded-full text-texto-fraco hover:text-perigo-600" title="Retirar" aria-label="Retirar {{ $eq->numero_serie }}">&times;</button>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            {{-- Técnicos: CONTAS de utilizador (mesma lista do relatório), 1 ou mais. Ligar
                                 a conta ativa o feed iCal e as notificações. --}}
                            <div>
                                <label class="campo-label">Técnicos (opcional)</label>
                                <div class="space-y-1 rounded-lg border border-borda px-4 py-3">
                                    @forelse ($tecnicos as $t)
                                        {{-- py-2 + h-5: alvo de toque confortável no telemóvel (linha inteira clicável). --}}
                                        <label class="flex cursor-pointer items-center gap-3 py-2 text-sm text-texto-forte">
                                            <input type="checkbox" wire:model="formTecnicoIds" value="{{ $t['id'] }}" class="h-5 w-5 rounded border-borda text-verde-600 focus:ring-verde-500">
                                            {{ $t['nome'] }}
                                        </label>
                                    @empty
                                        <p class="py-1 text-sm text-texto-medio">Sem técnicos com conta ativa.</p>
                                    @endforelse
                                </div>
                                <p class="mt-1.5 text-xs text-texto-fraco">Marca um ou mais; o evento entra no calendário (iCal) de cada um.</p>
                                {{-- Email aos técnicos marcados — ao criar, e também quando o evento for alterado
                                     (aqui ou por arrasto na agenda) ou removido. A escolha fica guardada no evento. --}}
                                <label class="mt-3 flex cursor-pointer items-center gap-3 rounded-lg border border-borda px-4 py-3 text-sm text-texto-forte">
                                    <input type="checkbox" wire:model="formNotificar" class="h-5 w-5 rounded border-borda text-verde-600 focus:ring-verde-500">
                                    <span>Avisar os técnicos por email <span class="block text-xs font-normal text-texto-fraco">ao criar, alterar ou remover este evento (quem faz a ação não recebe)</span></span>
                                </label>
                                @error('formTecnicoIds') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                @error('formTecnicoIds.*') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="campo-label">Início <span class="text-perigo-500">*</span></label>
                                    {{-- live: com fim noutro dia, aparecem as linhas de horas por dia. --}}
                                    <input wire:model.live="formInicio" type="datetime-local" class="campo-input">
                                    @error('formInicio') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="campo-label">Fim <span class="text-perigo-500">*</span></label>
                                    <input wire:model.live="formFim" type="datetime-local" class="campo-input">
                                    @error('formFim') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <p class="text-xs text-texto-fraco sm:col-span-2">
                                    Escreva as horas realmente trabalhadas — a qualquer hora, em qualquer dia; o fim pode ser <strong>noutro dia</strong>. A agenda só avisa se o técnico já tiver outro evento nesse período.
                                </p>

                                {{-- Dia inteiro (férias, ausências): 00:00–23:59 em CADA dia do período. Liga-se sozinho
                                     quando o tipo de evento é "Férias"; pode marcar-se à mão para outros casos. --}}
                                <label class="sm:col-span-2 flex cursor-pointer items-center gap-3 rounded-lg border border-borda px-4 py-3 text-sm text-texto-forte">
                                    <input type="checkbox" wire:model.live="formDiaInteiro" class="h-5 w-5 rounded border-borda text-verde-600 focus:ring-verde-500">
                                    <span>Dia inteiro <span class="block text-xs font-normal text-texto-fraco">ocupa cada dia do período das 00:00 às 23:59 — férias, ausências (marca-se sozinho ao escrever "Férias")</span></span>
                                </label>

                                {{-- Horas trabalhadas POR DIA (serviços de vários dias): uma linha
                                     por dia, horas editáveis — o calendário mostra um bloco por dia. --}}
                                @if (count($formHorasDias) > 1)
                                    <div class="sm:col-span-2 rounded-lg border border-borda bg-slate-50/60 p-4">
                                        <p class="campo-label">Horas trabalhadas em cada dia</p>
                                        <div class="mt-1 space-y-2">
                                            @foreach ($formHorasDias as $i => $linha)
                                                <div class="flex flex-wrap items-center gap-2" wire:key="hd-{{ $linha['dia'] }}">
                                                    <span class="w-32 text-sm font-medium text-texto-forte">{{ \Illuminate\Support\Carbon::parse($linha['dia'])->translatedFormat('D, d/m') }}</span>
                                                    <input type="time" wire:model="formHorasDias.{{ $i }}.inicio" class="campo-input w-28 py-1.5 text-sm">
                                                    <span class="text-texto-fraco">–</span>
                                                    <input type="time" wire:model="formHorasDias.{{ $i }}.fim" class="campo-input w-28 py-1.5 text-sm">
                                                    @error("formHorasDias.$i.inicio") <p class="w-full text-xs text-perigo-500">{{ $message }}</p> @enderror
                                                    @error("formHorasDias.$i.fim") <p class="w-full text-xs text-perigo-500">{{ $message }}</p> @enderror
                                                </div>
                                            @endforeach
                                        </div>
                                        <p class="mt-2 text-xs text-texto-fraco">Cada dia pode ter o seu horário — pode voltar aqui em qualquer dia e acertar as horas realmente trabalhadas.</p>
                                    </div>
                                @endif
                            </div>

                            {{-- Contrato (opcional) + cobertura — liga a visita ao saldo do contrato. --}}
                            <div>
                                <label class="campo-label">Contrato (opcional)</label>
                                @if ($editandoConvertido)
                                    <p class="mb-1.5 text-xs text-texto-fraco">O contrato gere-se no relatório; aqui só a cobertura pode mudar.</p>
                                @endif
                                <select wire:model.live="formContratoId" @disabled($editandoConvertido) class="campo-select">
                                    <option value="">Sem contrato</option>
                                    @foreach ($contratos as $c)
                                        <option value="{{ $c->id }}">{{ $c->numero }} · {{ $c->cliente?->nome ?? '—' }}</option>
                                    @endforeach
                                </select>
                                @error('formContratoId') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>

                            @if ($formContratoId)
                                <div>
                                    <label class="campo-label">Cobertura</label>
                                    <div class="flex gap-2">
                                        <button type="button" wire:click="$set('formCobertura', 'incluida')"
                                            class="flex-1 rounded-lg px-3.5 py-2 text-sm font-medium {{ $formCobertura === 'incluida' ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">
                                            Incluída no contrato
                                        </button>
                                        <button type="button" wire:click="$set('formCobertura', 'extra')"
                                            class="flex-1 rounded-lg px-3.5 py-2 text-sm font-medium {{ $formCobertura === 'extra' ? 'bg-verde-600 text-white' : 'border border-borda bg-white text-texto-medio hover:bg-fundo' }}">
                                            Extra (faturável)
                                        </button>
                                    </div>
                                    <p class="mt-1.5 text-xs text-texto-fraco">"Incluída" desconta do saldo do contrato; "Extra" é faturável à parte.</p>
                                    {{-- Saldo em direto do contrato escolhido (Vaga 1) — deixa de se marcar às cegas. --}}
                                    @if ($saldoContratoForm)
                                        <p class="mt-1.5 text-xs {{ $saldoContratoForm['restantes'] === 0 ? 'font-medium text-aviso-500' : 'text-texto-medio' }}">
                                            Saldo: {{ $saldoContratoForm['usadas'] }} de {{ $saldoContratoForm['incluidas'] }} incluídas usadas — restam {{ $saldoContratoForm['restantes'] }}.
                                            @if ($saldoContratoForm['excedido'] > 0)
                                                Já excedido em {{ $saldoContratoForm['excedido'] }} — esta visita será faturável à parte.
                                            @endif
                                        </p>
                                    @endif
                                    @error('formCobertura') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-borda px-6 py-4">
                            <button type="button" wire:click="fecharCriar" class="botao-secundario">Cancelar</button>
                            <button type="submit" class="botao-primario">{{ $editandoId ? 'Guardar' : 'Criar' }}</button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Legenda de técnicos (por nome, com a cor dos eventos) --}}
            <div class="mt-5 flex flex-wrap items-center gap-6 text-sm text-texto-medio">
                @foreach ($nomesTecnicos as $t)
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
