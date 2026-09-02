<div>
    <x-topbar :breadcrumb="['Início', 'Dashboard']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Bom dia, {{ auth()->user()->nome }}</h1>
                    <p class="mt-2 text-sm text-texto-medio">Resumo da operação · {{ \Illuminate\Support\Carbon::now()->locale('pt')->translatedFormat('l, d \d\e F \d\e Y') }}</p>
                </div>
                {{-- Força o sync do PHC já (o agendado corre às 08h/13h/19h). Corre em background. --}}
                <button type="button" wire:click="sincronizarErp" wire:loading.attr="disabled" wire:target="sincronizarErp"
                    class="botao-secundario inline-flex items-center gap-2" title="Sincronizar já clientes, faturação e equipamentos do PHC">
                    <svg wire:loading.class="animate-spin" wire:target="sincronizarErp" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                    Sincronizar PHC
                </button>
            </div>

            {{-- Sync pedido pelo botão: à espera (poll) → resumo do que foi criado/atualizado.
                 wire:key em TODOS os ramos: sem chaves estáveis, o morph podia confundir estes
                 blocos condicionais com os vizinhos (os gráficos com wire:ignore) e enviar
                 payloads corrompidos ao servidor (500 "property [$]", visto em produção). --}}
            @if ($syncPedidoEm)
                <div wire:key="sync-espera" wire:poll.3s="verificarSync" class="mt-5 flex items-center gap-2 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm font-medium text-verde-700">
                    <svg class="h-4 w-4 shrink-0 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                    A sincronizar com o PHC (clientes → equipamentos → faturação)… o resumo aparece aqui quando terminar.
                </div>
            @elseif ($syncResultado)
                <div wire:key="sync-resultado" class="mt-5 rounded-lg border px-4 py-3 text-sm {{ $syncResultado['falhou'] ? 'border-perigo-200 bg-perigo-100 text-perigo-600' : 'border-verde-200 bg-verde-50 text-verde-700' }}">
                    <div class="flex items-center gap-2 font-medium">
                        @if ($syncResultado['falhou'])
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008zM21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Sincronização terminou com falhas:
                        @else
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Sincronização concluída:
                        @endif
                    </div>
                    <ul class="mt-1.5 list-inside list-disc space-y-0.5">
                        @foreach ($syncResultado['resultados'] as $etapa => $r)
                            <li><span class="font-semibold">{{ $etapa }}</span>: {{ $r['detalhe'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (session('erro-sync'))
                <div wire:key="sync-erro" class="mt-5 flex items-center gap-2 rounded-lg border border-aviso-200 bg-aviso-100/60 px-4 py-3 text-sm font-medium text-aviso-500">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008zM21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ session('erro-sync') }}
                </div>
            @endif

            {{-- KPIs (cada cartão navega para a respetiva área) --}}
            <div class="mt-8 grid grid-cols-2 gap-5 lg:grid-cols-4">
                <a href="{{ route('contratos') }}" wire:navigate class="cartao block p-6 transition hover:border-verde-300 hover:shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Contratos ativos</div>
                    <div class="mt-2 text-3xl font-semibold text-texto-forte">{{ $resumo['contratos_ativos'] }}</div>
                </a>
                <a href="{{ route('ativos') }}" wire:navigate class="cartao block p-6 transition hover:border-verde-300 hover:shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Equipamentos</div>
                    <div class="mt-2 text-3xl font-semibold text-texto-forte">{{ $resumo['equipamentos'] }}</div>
                </a>
                <a href="{{ route('contratos') }}" wire:navigate class="cartao block p-6 transition hover:border-verde-300 hover:shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Renovações próximas</div>
                    <div class="mt-2 text-3xl font-semibold {{ $resumo['renovacoes'] > 0 ? 'text-aviso-500' : 'text-texto-forte' }}">{{ $resumo['renovacoes'] }}</div>
                </a>
                <a href="{{ route('alertas') }}" wire:navigate class="cartao block p-6 transition hover:border-verde-300 hover:shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Alertas em aberto</div>
                    <div class="mt-2 text-3xl font-semibold {{ $numAlertas > 0 ? 'text-perigo-600' : 'text-texto-forte' }}">{{ $numAlertas }}</div>
                </a>
            </div>

            {{-- Agenda dos próximos dias + próximos alertas (equipamentos/contratos) --}}
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="cartao">
                    {{-- UMA linha sempre: título à esquerda (trunca se faltar espaço), filtro + link
                         encostados à direita — sem wrap, para não cair para uma 2.ª linha. --}}
                    <div class="flex items-center gap-3 px-6 py-5">
                        <h2 class="min-w-0 flex-1 truncate text-lg font-semibold text-texto-forte">Agenda — próximos 7 dias</h2>
                        <div class="flex shrink-0 items-center gap-3">
                            {{-- Filtro por técnico (principal ou adicional do evento). --}}
                            <select wire:model.live="agendaTecnico" class="campo-select w-40 py-1.5 text-sm" title="Ver a agenda de um técnico">
                                <option value="">Todos os técnicos</option>
                                @foreach ($tecnicosAgenda as $t)
                                    <option value="{{ $t->id }}">{{ $t->nome }}</option>
                                @endforeach
                            </select>
                            <a href="{{ route('agenda') }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Ver agenda</a>
                        </div>
                    </div>
                    <ul class="border-t border-borda">
                        @forelse ($agendaSemana as $ev)
                            <li class="flex items-center justify-between gap-3 border-b border-borda px-6 py-3.5 last:border-0" wire:key="agenda-dash-{{ $ev->id }}">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-texto-forte">{{ $ev->titulo }}</div>
                                    <div class="truncate text-xs text-texto-fraco">{{ $ev->cliente->nome ?? '—' }}{{ $ev->tecnico ? ' · ' . $ev->tecnico->nome : '' }} · {{ $ev->estado->rotulo() }}</div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-sm font-medium {{ $ev->inicio->isToday() ? 'text-verde-600' : 'text-texto-forte' }}">{{ $ev->inicio->isToday() ? 'Hoje' : $ev->inicio->translatedFormat('D, d M') }}</div>
                                    <div class="text-xs text-texto-fraco">{{ $ev->inicio->format('H:i') }}–{{ $ev->fim->format('H:i') }}</div>
                                </div>
                            </li>
                        @empty
                            <li class="px-6 py-8 text-center text-sm text-texto-medio">{{ $agendaTecnico !== '' ? 'Sem eventos deste técnico nos próximos 7 dias.' : 'Sem eventos agendados para os próximos 7 dias.' }}</li>
                        @endforelse
                    </ul>
                </section>

                <section class="cartao">
                    {{-- Mesmo cabeçalho do cartão da agenda: uma linha, filtro + link à direita. --}}
                    <div class="flex items-center gap-3 px-6 py-5">
                        <h2 class="min-w-0 flex-1 truncate text-lg font-semibold text-texto-forte">Próximos alertas</h2>
                        <div class="flex shrink-0 items-center gap-3">
                            <select wire:model.live="alertasTecnico" class="campo-select w-40 py-1.5 text-sm" title="Só os alertas atribuídos a">
                                <option value="">Todos os técnicos</option>
                                @foreach ($tecnicosAgenda as $t)
                                    <option value="{{ $t->id }}">{{ $t->nome }}</option>
                                @endforeach
                            </select>
                            <a href="{{ route('alertas') }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Ver alertas</a>
                        </div>
                    </div>
                    <ul class="border-t border-borda">
                        @forelse ($proximosAlertas as $a)
                            <li class="flex items-center justify-between gap-3 border-b border-borda px-6 py-3.5 last:border-0">
                                <div class="min-w-0">
                                    <a href="{{ $a['url'] }}" wire:navigate class="block truncate text-sm font-medium text-texto-forte hover:text-verde-600">{{ $a['titulo'] }}</a>
                                    <div class="truncate text-xs text-texto-fraco">{{ $a['descricao'] }}@if ($a['atribuido_nome']) · {{ $a['atribuido_nome'] }}@endif</div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="etiqueta {{ $a['severidade'] === 'alta' ? 'bg-perigo-100 text-perigo-600' : 'bg-aviso-100 text-aviso-500' }}">{{ $a['severidade'] === 'alta' ? 'Alta' : 'Média' }}</span>
                                    <button type="button" wire:click="concluirAlerta('{{ $a['chave'] }}')" wire:confirm="Dar este alerta como concluído?" class="rounded-md border border-borda p-1 text-texto-fraco hover:border-verde-500 hover:text-verde-700" title="Concluir">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </button>
                                </div>
                            </li>
                        @empty
                            <li class="px-6 py-8 text-center text-sm text-texto-medio">{{ $alertasTecnico !== '' ? 'Sem alertas atribuídos a este técnico.' : 'Sem alertas em aberto — baterias, renovações e SLA em dia.' }}</li>
                        @endforelse
                    </ul>
                </section>
            </div>

            {{-- Saíram a pedido da equipa: cartão "Cumprimento de SLA", gráficos (visitas de
                 contrato, equipamentos por tipo/estado) e "Equipamentos sem visitas recentes"
                 — as métricas continuam no ServicoMetricas para os relatórios de gestão. --}}

            {{-- Renovações próximas --}}
            <section class="cartao mt-6">
                <div class="flex items-center justify-between px-6 py-5">
                    <h2 class="text-lg font-semibold text-texto-forte">Renovações próximas</h2>
                    <a href="{{ route('contratos') }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Ver contratos</a>
                </div>
                <ul class="border-t border-borda">
                    @forelse ($renovacoes as $c)
                        <li class="flex items-center justify-between border-b border-borda px-6 py-3.5 last:border-0">
                            <div>
                                <a href="{{ route('contratos.ficha', $c) }}" wire:navigate class="text-sm font-medium text-texto-forte hover:text-verde-600">{{ $c->numero }}</a>
                                <div class="text-xs text-texto-fraco">{{ $c->cliente->nome }}</div>
                            </div>
                            <span class="text-sm text-aviso-500">termina {{ $c->data_fim->translatedFormat('d M Y') }}</span>
                        </li>
                    @empty
                        <li class="px-6 py-8 text-center text-sm text-texto-medio">Sem renovações próximas.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </main>
</div>
