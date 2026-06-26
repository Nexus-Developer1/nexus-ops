<div>
    <x-topbar :breadcrumb="['Início', 'Dashboard']">
        <a href="{{ route('alertas') }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
            {{ $numAlertas }} alertas
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Bom dia, {{ auth()->user()->nome }}</h1>
            <p class="mt-2 text-sm text-texto-medio">Resumo da operação · {{ \Illuminate\Support\Carbon::now()->locale('pt')->translatedFormat('l, d \d\e F \d\e Y') }}</p>

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

            {{-- Rentabilidade + SLA --}}
            <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="cartao p-6">
                    <div class="flex items-baseline justify-between">
                        <h2 class="text-lg font-semibold text-texto-forte">Rentabilidade de visitas</h2>
                        <span class="text-sm text-texto-medio">{{ now()->year }}</span>
                    </div>
                    <p class="mt-1 text-sm text-texto-medio">Visitas preventivas realizadas vs. orçamentadas.</p>
                    <div class="mt-5 flex items-end justify-between">
                        <div class="text-3xl font-semibold text-texto-forte">{{ $resumo['visitas']['taxa'] }}%</div>
                        <div class="text-sm text-texto-medio">{{ $resumo['visitas']['realizadas'] }} / {{ $resumo['visitas']['orcamentadas'] }}</div>
                    </div>
                    <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-fundo">
                        <div class="h-full rounded-full bg-verde-500" style="width: {{ $resumo['visitas']['taxa'] }}%"></div>
                    </div>
                </div>

                <div class="cartao p-6">
                    <h2 class="text-lg font-semibold text-texto-forte">Cumprimento de SLA</h2>
                    <p class="mt-1 text-sm text-texto-medio">Corretivas resolvidas dentro do prazo contratado.</p>
                    @if (is_null($resumo['cumprimento_sla']['taxa']))
                        <div class="mt-5 text-sm text-texto-medio">Ainda sem corretivas concluídas com SLA.</div>
                    @else
                        <div class="mt-5 flex items-end justify-between">
                            <div class="text-3xl font-semibold text-texto-forte">{{ $resumo['cumprimento_sla']['taxa'] }}%</div>
                            <div class="text-sm text-texto-medio">{{ $resumo['cumprimento_sla']['dentro'] }} / {{ $resumo['cumprimento_sla']['total'] }}</div>
                        </div>
                        <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-fundo">
                            <div class="h-full rounded-full {{ $resumo['cumprimento_sla']['taxa'] >= 90 ? 'bg-verde-500' : 'bg-aviso-500' }}" style="width: {{ $resumo['cumprimento_sla']['taxa'] }}%"></div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Gráficos --}}
            <div class="mt-6 cartao p-6">
                <h2 class="text-lg font-semibold text-texto-forte">Visitas preventivas por mês</h2>
                <p class="mt-1 text-sm text-texto-medio">Planeadas vs. realizadas em {{ now()->year }}.</p>
                <div wire:ignore x-data="grafico(@js($graficoVisitas))" class="mt-4" style="position: relative; height: 260px;">
                    <canvas x-ref="canvas"></canvas>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="cartao p-6">
                    <h2 class="text-lg font-semibold text-texto-forte">Equipamentos por tipo</h2>
                    @if (array_sum($graficoTipos['data']['datasets'][0]['data']) > 0)
                        <div wire:ignore x-data="grafico(@js($graficoTipos))" class="mt-4" style="position: relative; height: 260px;">
                            <canvas x-ref="canvas"></canvas>
                        </div>
                    @else
                        <p class="mt-4 text-sm text-texto-medio">Sem equipamentos registados.</p>
                    @endif
                </div>
                <div class="cartao p-6">
                    <h2 class="text-lg font-semibold text-texto-forte">Equipamentos por estado</h2>
                    @if (array_sum($graficoEstados['data']['datasets'][0]['data']) > 0)
                        <div wire:ignore x-data="grafico(@js($graficoEstados))" class="mt-4" style="position: relative; height: 260px;">
                            <canvas x-ref="canvas"></canvas>
                        </div>
                    @else
                        <p class="mt-4 text-sm text-texto-medio">Sem equipamentos registados.</p>
                    @endif
                </div>
            </div>

            {{-- Renovações + equipamentos sem visitas --}}
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="cartao">
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

                <section class="cartao">
                    <div class="px-6 py-5"><h2 class="text-lg font-semibold text-texto-forte">Equipamentos sem visitas recentes</h2></div>
                    <ul class="border-t border-borda">
                        @forelse ($semVisitas as $e)
                            <li class="flex items-center justify-between border-b border-borda px-6 py-3.5 last:border-0">
                                <div>
                                    <a href="{{ route('equipamentos.ficha', $e) }}" wire:navigate class="text-sm font-medium text-texto-forte hover:text-verde-600">{{ trim($e->fabricante . ' ' . $e->modelo) ?: ($e->numero_serie ?? '—') }}</a>
                                    <div class="text-xs text-texto-fraco">{{ $e->local->cliente->nome ?? '—' }}</div>
                                </div>
                                <span class="etiqueta {{ $e->tipo->classesEtiqueta() }}">{{ $e->tipo->rotulo() }}</span>
                            </li>
                        @empty
                            <li class="px-6 py-8 text-center text-sm text-texto-medio">Todos os equipamentos têm visitas recentes.</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>
    </main>
</div>
