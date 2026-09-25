{{-- validacao-falhou: os campos validados vivem no separador "Dados Gerais" — salta para lá
     e sobe ao topo, senão o erro ficava escondido e o botão parecia não fazer nada.
     Autosave, honestidade offline e espelho local vivem no componente Alpine
     `editorRelatorio` (resources/js/app.js) — Vaga 2. --}}
<div x-data="editorRelatorio()"
    x-on:validacao-falhou.window="tab = 'gerais'; window.scrollTo({ top: 0, behavior: 'smooth' })"
    x-on:confirmar-fichas-vazias.window="if (confirm('Sem medições nem assinaturas em: ' + $event.detail.equipamentos.join(', ') + '.\n\nEssas fichas não vão constar do relatório. Finalizar mesmo assim?')) { $wire.set('finalizarComFichasVazias', true, false); $wire.finalizar() }"
    x-on:input="marcarSuja()" x-on:change="marcarSuja()"
    x-on:auto-gravado.window="gravado($event.detail.url)"
    x-on:rascunho-guardado.window="gravado(null)">
    <x-topbar :breadcrumb="['Relatórios', $relatorioId ? 'Rascunho' : 'Novo']">
        <a href="{{ route('relatorios') }}" class="botao-secundario">Cancelar</a>
        <button wire:click="guardarRascunho" wire:loading.attr="disabled" wire:target="guardarRascunho" class="botao-secundario">
            <span wire:loading.remove wire:target="guardarRascunho">Guardar rascunho</span>
            <span wire:loading wire:target="guardarRascunho">A guardar…</span>
        </button>
        <button wire:click="finalizar" wire:loading.attr="disabled" wire:target="finalizar" wire:confirm="Finalizar o relatório? Gera o PDF e fica como documento oficial." class="botao-primario">
            <span wire:loading.remove wire:target="finalizar">Finalizar relatório</span>
            <span wire:loading wire:target="finalizar">A finalizar…</span>
        </button>
    </x-topbar>

    {{-- Badge de honestidade offline (Vaga 2): sem rede, o técnico SABE que há alterações
         por enviar (guardadas neste dispositivo) — antes o autosave falhava em silêncio. --}}
    <div x-show="semRede" x-cloak
        class="fixed bottom-6 left-6 z-50 flex items-center gap-2 rounded-lg bg-aviso-100 px-4 py-3 text-sm font-medium text-aviso-500 shadow-lg">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m2.829 2.829a5 5 0 000 7.07m7.07 0a5 5 0 000-7.07M12 12h.01"/></svg>
        Sem ligação — alterações guardadas neste dispositivo, por enviar
    </div>

    {{-- Toast do save de prevenção ("Guardar rascunho" em edição fica na página) e do
         autosave (texto próprio, para o técnico saber que está protegido sem fazer nada). --}}
    <div x-data="{ visivel: false, texto: 'Rascunho guardado' }"
        x-on:rascunho-guardado.window="texto = 'Rascunho guardado'; visivel = true; setTimeout(() => visivel = false, 2500)"
        x-on:auto-gravado.window="texto = 'Rascunho gravado automaticamente'; visivel = true; setTimeout(() => visivel = false, 2500)"
        x-show="visivel" x-cloak x-transition.opacity
        class="fixed bottom-6 right-6 z-50 flex items-center gap-2 rounded-lg bg-verde-600 px-4 py-3 text-sm font-medium text-white shadow-lg">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        <span x-text="texto"></span>
    </div>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">

            <x-toast-sucesso />

            {{-- Resumo de erros de validação — SEMPRE visível (acima dos separadores), para a
                 falha nunca passar despercebida esteja-se no separador que se estiver. --}}
            @if ($errors->any())
                <div class="mb-6 rounded-lg border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600">
                    <div class="flex items-center gap-2 font-medium">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-8.02 13.89A2 2 0 004 21h16a2 2 0 001.73-3.25L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        O relatório não foi gravado — corrija os campos assinalados:
                    </div>
                    <ul class="mt-1.5 list-inside list-disc space-y-0.5">
                        @foreach ($errors->all() as $erro)
                            <li>{{ $erro }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Cabeçalho --}}
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Relatório de Intervenção Técnica</h1>
                </div>
                @php($estadoBadge = \App\Enums\EstadoRelatorio::tryFrom($estadoInicial ?? '') ?? \App\Enums\EstadoRelatorio::Rascunho)
                <span class="etiqueta {{ $estadoBadge->classesEtiqueta() }} uppercase tracking-wide">{{ $estadoBadge->rotulo() }}</span>
            </div>

            {{-- Editar um relatório JÁ ENVIADO: aviso claro — a versão do cliente só muda ao reenviar. --}}
            @if ($estadoInicial === \App\Enums\EstadoRelatorio::Enviado->value)
                {{-- O texto vai num <p> só: solto dentro do flex, cada pedaço (texto e <strong>)
                     virava uma coluna e partia linhas por conta própria. --}}
                <div class="mt-5 flex items-start gap-2.5 rounded-lg border border-aviso-200 bg-aviso-100/60 px-4 py-3 text-sm text-aviso-500">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008zM21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="leading-relaxed">
                        Este relatório já foi enviado ao cliente. Ao gravar, <strong>deixa de estar visível no portal do cliente</strong>
                        até ser <strong>reenviado</strong> depois de finalizar. A cópia que seguiu por email mantém-se na caixa dele.
                    </p>
                </div>
            @endif

            {{-- Tabs: Dados Gerais / Diagnóstico + um separador por equipamento (ambos os modos). --}}
            <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-1 border-b border-borda">
                <button @click="tab='gerais'" :class="tab==='gerais' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">Dados Gerais</button>

                @if ($equipamentoPrincipal || $cobertosSelecionados->isNotEmpty())
                    <span class="mx-1 h-4 w-px bg-borda" aria-hidden="true"></span>
                    @if ($equipamentoPrincipal)
                        <button wire:key="tab-btn-{{ $equipamentoPrincipal->id }}" @click="tab='equip-{{ $equipamentoPrincipal->id }}'" :class="tab==='equip-{{ $equipamentoPrincipal->id }}' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">
                            {{-- Marca + modelo em destaque (série só como fallback) — pedido da equipa. --}}
                            {{ trim($equipamentoPrincipal->fabricante . ' ' . $equipamentoPrincipal->modelo) ?: ($equipamentoPrincipal->numero_serie ?? '—') }}
                        </button>
                    @endif
                    @foreach ($cobertosSelecionados as $e)
                        <button wire:key="tab-btn-{{ $e->id }}" @click="tab='equip-{{ $e->id }}'" :class="tab==='equip-{{ $e->id }}' ? 'border-verde-500 text-verde-600 font-semibold' : 'border-transparent text-texto-medio font-medium hover:text-texto-forte'" class="-mb-px border-b-2 pb-3 text-sm transition">
                            {{ trim($e->fabricante . ' ' . $e->modelo) ?: ($e->numero_serie ?? '—') }}
                        </button>
                    @endforeach
                @endif
            </div>

            {{-- ===== DADOS GERAIS ===== --}}
            <div x-show="tab==='gerais'" class="space-y-5">

                {{-- Equipamento e Intervenção --}}
                <section class="cartao mt-7" x-data="{ aberto: true, organizar: false, arrastado: null }">
                    {{-- Cabeçalho com o botão «Organizar campos» ao lado da seta (set. 2026): dentro do corpo
                         do cartão ocupava uma linha inteira e deixava um vazio por cima dos campos. --}}
                    <div class="cartao-cabecalho gap-3">
                        <button type="button" @click="aberto=!aberto" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m6-14h1m-1 4h1m4-4h1m-1 4h1m-5 8v-4a1 1 0 011-1h2a1 1 0 011 1v4"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Equipamento e Intervenção</span>
                        </button>
                        <div class="flex shrink-0 items-center gap-3 text-xs" x-show="aberto">
                            <button type="button" x-show="organizar" x-cloak wire:click="reporOrdemCampos('gerais')" class="font-medium text-texto-medio hover:text-texto-forte hover:underline">Repor ordem de fábrica</button>
                            <button type="button" @click="organizar = !organizar; arrastado = null" class="inline-flex items-center gap-1.5 rounded-lg border border-borda px-3 py-1.5 font-medium text-texto-medio transition hover:bg-fundo hover:text-texto-forte" :class="organizar && 'border-verde-300 bg-verde-50 text-verde-700'">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg>
                                <span x-text="organizar ? 'Concluir' : 'Organizar campos'"></span>
                            </button>
                        </div>
                        <button type="button" @click="aberto=!aberto" aria-label="Mostrar ou esconder" class="shrink-0"><svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg></button>
                    </div>
                    <div x-show="aberto" x-transition class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-6 px-6 pb-7">
                        {{-- Campos REORDENÁVEIS (pedido da equipa, set. 2026): cada utilizador organiza estes blocos
                             como preferir, mediante a importância. Modo «Organizar campos» → arrastar (desktop) ou
                             setas ▲▼ (telemóvel); o botão está no cabeçalho do cartão. A ordem fica nas preferências do utilizador; a whitelist é
                             revalidada no servidor. Cada bloco vive numa partial em livewire/relatorios/campos. --}}
                        @foreach ($ordemCampos['gerais'] as $campo)
                            <div wire:key="campo-{{ $campo }}"
                                class="relative {{ in_array($campo, ['tipo', 'datas', 'horas'], true) ? '' : 'sm:col-span-2' }}"
                                :class="organizar && 'rounded-lg border border-dashed border-verde-300 bg-verde-50/40 p-3 pt-9 cursor-move'"
                                :draggable="organizar"
                                x-on:dragstart="if (!organizar) return; arrastado = '{{ $campo }}'"
                                x-on:dragover.prevent
                                x-on:drop.prevent="if (organizar && arrastado && arrastado !== '{{ $campo }}') { $wire.reordenarCampos(window.reordenar($wire.ordemCampos.gerais, arrastado, '{{ $campo }}'), 'gerais') } arrastado = null">
                                <div x-show="organizar" x-cloak class="absolute right-2 top-2 flex items-center gap-1">
                                    <button type="button" wire:click="moverCampo('{{ $campo }}', -1, 'gerais')" title="Subir" class="flex h-7 w-7 items-center justify-center rounded-md border border-borda bg-white text-texto-medio hover:text-verde-700 disabled:opacity-30" @disabled($loop->first)>
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                    </button>
                                    <button type="button" wire:click="moverCampo('{{ $campo }}', 1, 'gerais')" title="Descer" class="flex h-7 w-7 items-center justify-center rounded-md border border-borda bg-white text-texto-medio hover:text-verde-700 disabled:opacity-30" @disabled($loop->last)>
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                </div>
                                @include('livewire.relatorios.campos.' . $campo)
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- Constatações Técnicas --}}
                <section class="cartao" x-data="{ aberto: true }">
                    <button @click="aberto=!aberto" class="cartao-cabecalho">
                        <span class="flex items-center gap-3">
                            <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg></span>
                            <span class="text-lg font-semibold text-texto-forte">Constatações Técnicas</span>
                        </span>
                        <svg :class="aberto && 'rotate-180'" class="h-5 w-5 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="aberto" x-transition class="px-6 pb-7">
                        <label class="campo-label">Resumo da intervenção</label>
                        {{-- Cresce com o texto (auto-resize): altura acompanha o conteúdo, sem scroll interno. --}}
                        <textarea wire:model="resumo" rows="3"
                            x-data="{ ajustar() { if (! this.$el.scrollHeight) return; this.$el.style.height = 'auto'; this.$el.style.height = this.$el.scrollHeight + 'px'; } }"
                            x-init="ajustar()" @input="ajustar()"
                            class="campo-input resize-none overflow-hidden" placeholder="Descreva as constatações técnicas observadas durante a intervenção…"></textarea>
                    </div>
                </section>

                {{-- Recomendações + fotos passaram para CADA ficha de equipamento (abaixo). --}}
            </div>

            {{-- ===== FICHAS DE MEDIÇÃO (uma "página" por equipamento) — ambos os modos ===== --}}
            {{-- Nota: construir a lista com diretivas INLINE (bloco raw de PHP partiria a compilação). --}}
            @if ($equipamentoPrincipal || $cobertosSelecionados->isNotEmpty())
                @php($equipamentosFicha = collect())
                @if ($equipamentoPrincipal) @php($equipamentosFicha->push(['e' => $equipamentoPrincipal, 'principal' => true])) @endif
                @foreach ($cobertosSelecionados as $e) @php($equipamentosFicha->push(['e' => $e, 'principal' => false])) @endforeach
                @foreach ($equipamentosFicha as $item)
                    @php($e = $item['e'])
                    <div x-show="tab==='equip-{{ $e->id }}'" x-cloak class="space-y-5" wire:key="tab-ficha-{{ $e->id }}">
                        <section class="cartao mt-7" x-data="{ organizar: false, arrastado: null }">
                            <div class="flex items-center justify-between gap-3 px-6 py-5">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span>
                                    <div class="min-w-0">
                                        {{-- Marca + modelo em destaque, série no subtítulo (pedido da equipa; fallback pelo TIPO real). --}}
                                        <h2 class="flex items-center gap-2 text-lg font-semibold text-texto-forte">
                                            <span class="truncate">{{ trim($e->fabricante . ' ' . $e->modelo) ?: $e->tipo->rotulo() }}</span>
                                        </h2>
                                        <p class="truncate text-sm text-texto-medio">{{ $e->numero_serie ?? '—' }}</p>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-4">
                                    {{-- Organizar os blocos da ficha (só UPS; a SADEI espelha a folha oficial). --}}
                                    @unless ($e->tipo === \App\Enums\TipoEquipamento::Incendio)
                                        <button type="button" x-show="organizar" x-cloak wire:click="reporOrdemCampos('ficha_ups')" class="text-xs font-medium text-texto-medio hover:text-texto-forte hover:underline">Repor ordem de fábrica</button>
                                        <button type="button" @click="organizar = !organizar; arrastado = null" class="inline-flex items-center gap-1.5 rounded-lg border border-borda px-3 py-1.5 text-xs font-medium text-texto-medio transition hover:bg-fundo hover:text-texto-forte" :class="organizar && 'border-verde-300 bg-verde-50 text-verde-700'">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg>
                                            <span x-text="organizar ? 'Concluir' : 'Organizar campos'"></span>
                                        </button>
                                    @endunless
                                {{-- Remove o equipamento do relatório e volta aos Dados Gerais. --}}
                                <button type="button" @click="tab='gerais'" wire:click="removerEquipamentoDoRelatorio({{ $e->id }})" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-texto-medio transition hover:text-perigo-600" title="Remover equipamento do relatório">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    Remover
                                </button>
                                </div>
                            </div>
                            <div class="border-t border-borda px-6 py-6">
                                {{-- Equipamentos de incêndio têm ficha técnica própria (SADEI); os restantes usam a de medições UPS. --}}
                                @if ($e->tipo === \App\Enums\TipoEquipamento::Incendio)
                                    <x-relatorios.ficha-incendio :prefixo="'fichas.' . $e->id" :equip-id="$e->id"
                                        :linhas-grelhas="['cilindros' => count($fichas[$e->id]['sadei']['cilindros'] ?? []), 'piloto' => count($fichas[$e->id]['sadei']['piloto'] ?? [])]"
                                        :assinaturas="$assinaturasGravadas[$e->id] ?? []" />
                                @else
                                    <x-relatorios.ficha-ups :prefixo="'fichas.' . $e->id" :descarga="$fichas[$e->id]['teste_descarga'] ?? []" :curva="$fichas[$e->id]['descarga_curva'] ?? []" :ordem="$ordemCampos['ficha_ups']" />
                                @endif
                            </div>

                            {{-- Fotografias DESTE equipamento (aparecem junto das medições no PDF). --}}
                            <div class="border-t border-borda px-6 py-6">
                                <p class="mb-3 text-sm font-semibold text-texto-forte">Fotografias</p>
                                {{-- Duas entradas para a MESMA propriedade: "Tirar foto" abre a câmara
                                     (capture) e "Galeria" abre o seletor de ficheiros/álbum. Em
                                     telemóvel/tablet mostram-se as duas; no computador só a galeria
                                     (o capture é ignorado e só confundia). --}}
                                <div x-data="{ toque: ('ontouchstart' in window) || navigator.maxTouchPoints > 0, ...fotosUpload(@js('fotos.' . $e->id)) }"
                                    wire:key="fotos-{{ $e->id }}"
                                    class="flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-borda py-6 text-texto-fraco">
                                    <div wire:loading.remove wire:target="fotos.{{ $e->id }}" class="flex flex-wrap items-center justify-center gap-3">
                                        <label x-show="toque" x-cloak class="botao-secundario inline-flex cursor-pointer items-center gap-2">
                                            {{-- SEM multiple: no iOS, capture+multiple quebra o "Repetir/Usar foto" da
                                                 câmara. Tira-se uma de cada vez (o botão fica logo pronto para a
                                                 seguinte e as fotos acumulam); várias de uma vez faz-se pela Galeria. --}}
                                            <input type="file" @change="escolher($event)" accept="image/*" capture="environment" class="hidden">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            Tirar foto
                                        </label>
                                        <label class="botao-secundario inline-flex cursor-pointer items-center gap-2">
                                            <input type="file" @change="escolher($event)" multiple accept="image/*" class="hidden">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                            <span x-text="toque ? 'Galeria' : 'Escolher ficheiros'">Escolher ficheiros</span>
                                        </label>
                                    </div>
                                    <span class="flex items-center gap-2 text-xs font-medium" wire:loading wire:target="fotos.{{ $e->id }}">
                                        <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12a8 8 0 018-8"/></svg>
                                        A enviar…
                                    </span>
                                </div>

                                {{-- Já gravadas deste equipamento --}}
                                @php($anexosDoEquip = $anexosExistentes->where('equipamento_id', $e->id))
                                @if ($anexosDoEquip->count())
                                    <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                                        @foreach ($anexosDoEquip as $ax)
                                            <div class="relative aspect-square overflow-hidden rounded-xl bg-zinc-800" wire:key="ax-{{ $ax->id }}">
                                                <img src="{{ route('anexos.ver', $ax) }}" alt="{{ $ax->nome_ficheiro }}" title="Ver maior"
                                                    @click="$dispatch('ver-foto', { src: @js(route('anexos.ver', $ax)), legenda: @js($ax->nome_ficheiro) })"
                                                    class="h-full w-full cursor-zoom-in object-cover">
                                                {{-- Sai no PDF do cliente? Desligado = só registo interno (fica guardada). --}}
                                                <label wire:key="nr-{{ $ax->id }}" @click.stop title="{{ $ax->no_relatorio ? 'Sai no relatório do cliente — clique para guardar só como registo interno' : 'Só registo interno — clique para sair no relatório' }}"
                                                    class="absolute bottom-1.5 left-1.5 flex cursor-pointer items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-medium {{ $ax->no_relatorio ? 'bg-verde-600 text-white' : 'bg-black/60 text-white/80' }}">
                                                    <input type="checkbox" wire:click="alternarFotoNoRelatorio({{ $ax->id }})" @click="window.preservarScroll()" @checked($ax->no_relatorio) class="h-3.5 w-3.5 rounded border-white/60 bg-transparent text-verde-600 focus:ring-0">
                                                    {{ $ax->no_relatorio ? 'No relatório' : 'Só interno' }}
                                                </label>
                                                <button type="button" @click="window.preservarScroll()" wire:click="removerAnexoExistente({{ $ax->id }})" wire:confirm="Remover esta foto?" class="absolute right-1.5 top-1.5 flex h-9 w-9 items-center justify-center rounded-lg bg-black/60 text-white transition hover:bg-perigo-500">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Novas (por gravar) deste equipamento --}}
                                @php($novasDoEquip = $fotosNovas[$e->id] ?? [])
                                @if (count($novasDoEquip))
                                    <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                                        @foreach ($novasDoEquip as $indice => $foto)
                                            <div class="relative aspect-square overflow-hidden rounded-xl bg-zinc-800" wire:key="foto-nova-{{ $e->id }}-{{ $indice }}">
                                                @if ($foto->isPreviewable())
                                                    <img src="{{ $foto->temporaryUrl() }}" alt="{{ $foto->getClientOriginalName() }}" title="Ver maior"
                                                        @click="$dispatch('ver-foto', { src: @js($foto->temporaryUrl()), legenda: @js($foto->getClientOriginalName()) })"
                                                        class="h-full w-full cursor-zoom-in object-cover">
                                                @else
                                                    <span class="flex h-full w-full items-center justify-center text-xs text-white/60">{{ $foto->getClientOriginalName() }}</span>
                                                @endif
                                                <button type="button" @click="window.preservarScroll()" wire:click="removerFotoNova({{ $e->id }}, {{ $indice }})" class="absolute right-1.5 top-1.5 flex h-9 w-9 items-center justify-center rounded-lg bg-black/60 text-white transition hover:bg-perigo-500" title="Remover">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @error('fotos.' . $e->id . '.*') <p class="mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                @error('fotosNovas.' . $e->id . '.*') <p class="mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror

                                {{-- Relatórios antigos: fotos sem equipamento aparecem no separador principal. --}}
                                @if ($item['principal'])
                                    @php($anexosGerais = $anexosExistentes->whereNull('equipamento_id'))
                                    @if ($anexosGerais->count())
                                        <p class="mb-3 mt-6 text-sm font-semibold text-texto-forte">Fotos gerais <span class="font-normal text-texto-fraco">(relatório antigo, sem equipamento)</span></p>
                                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                            @foreach ($anexosGerais as $ax)
                                                <div class="relative aspect-square overflow-hidden rounded-xl bg-zinc-800" wire:key="ax-{{ $ax->id }}">
                                                    <img src="{{ route('anexos.ver', $ax) }}" alt="{{ $ax->nome_ficheiro }}" title="Ver maior"
                                                        @click="$dispatch('ver-foto', { src: @js(route('anexos.ver', $ax)), legenda: @js($ax->nome_ficheiro) })"
                                                        class="h-full w-full cursor-zoom-in object-cover">
                                                    {{-- Sai no PDF do cliente? Desligado = só registo interno (fica guardada). --}}
                                                    <label wire:key="nr-{{ $ax->id }}" @click.stop title="{{ $ax->no_relatorio ? 'Sai no relatório do cliente — clique para guardar só como registo interno' : 'Só registo interno — clique para sair no relatório' }}"
                                                        class="absolute bottom-1.5 left-1.5 flex cursor-pointer items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-medium {{ $ax->no_relatorio ? 'bg-verde-600 text-white' : 'bg-black/60 text-white/80' }}">
                                                        <input type="checkbox" wire:click="alternarFotoNoRelatorio({{ $ax->id }})" @click="window.preservarScroll()" @checked($ax->no_relatorio) class="h-3.5 w-3.5 rounded border-white/60 bg-transparent text-verde-600 focus:ring-0">
                                                        {{ $ax->no_relatorio ? 'No relatório' : 'Só interno' }}
                                                    </label>
                                                    <button type="button" @click="window.preservarScroll()" wire:click="removerAnexoExistente({{ $ax->id }})" wire:confirm="Remover esta foto?" class="absolute right-1.5 top-1.5 flex h-9 w-9 items-center justify-center rounded-lg bg-black/60 text-white transition hover:bg-perigo-500">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </section>
                    </div>
                @endforeach
            @endif

        </div>
    </main>
</div>
