<div>
    <x-topbar :breadcrumb="['Manutenção', 'Contratos', $contrato->numero]">
        <a href="{{ route('contratos.editar', $contrato) }}" wire:navigate class="botao-secundario">Editar</a>

        @if ($contrato->estado === \App\Enums\EstadoContrato::Rascunho)
            <button wire:click="ativar" class="botao-primario">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Ativar
            </button>
        @elseif ($contrato->estado === \App\Enums\EstadoContrato::Ativo)
            <button wire:click="suspender" class="botao-secundario">Suspender</button>
        @elseif ($contrato->estado === \App\Enums\EstadoContrato::Suspenso)
            <button wire:click="reativar" class="botao-primario">Reativar</button>
        @endif
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            @if (session('sucesso'))
                <div class="mb-6 flex items-center gap-2 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm font-medium text-verde-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    {{ session('sucesso') }}
                </div>
            @endif
            @if (session('erro'))
                <div class="mb-6 flex items-center gap-2 rounded-lg border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm font-medium text-perigo-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ session('erro') }}
                </div>
            @endif

            {{-- Cabeçalho --}}
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $contrato->numero }}</h1>
                        <span class="etiqueta {{ $contrato->estado->classesEtiqueta() }}">{{ $contrato->estado->rotulo() }}</span>
                    </div>
                    <p class="mt-2 text-sm text-texto-medio">{{ $contrato->cliente->nome }} · {{ $contrato->tipo->rotulo() }}</p>
                </div>
                @if ($contrato->estaAExpirar())
                    <span class="etiqueta bg-aviso-100 text-aviso-500">Renova em {{ $contrato->diasParaFim() }} dias</span>
                @endif
            </div>

            {{-- Dados gerais --}}
            <section class="cartao mt-8">
                <div class="px-6 py-5"><h2 class="text-lg font-semibold text-texto-forte">Dados gerais</h2></div>
                <dl class="grid grid-cols-2 gap-x-8 gap-y-5 border-t border-borda px-6 py-6 sm:grid-cols-4">
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Início</dt><dd class="mt-1 text-sm text-texto-forte">{{ $contrato->data_inicio->translatedFormat('d M Y') }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Fim</dt><dd class="mt-1 text-sm text-texto-forte">{{ $contrato->data_fim->translatedFormat('d M Y') }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Faturação</dt><dd class="mt-1 text-sm text-texto-forte">{{ $contrato->modeloFaturacao?->nome ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Valor</dt><dd class="mt-1 text-sm text-texto-forte">{{ $contrato->valor ? number_format((float) $contrato->valor, 2, ',', '.') . ' €' : '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Período faturação</dt><dd class="mt-1 text-sm text-texto-forte">{{ $contrato->periodo_faturacao ?: '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Renovação automática</dt><dd class="mt-1 text-sm text-texto-forte">{{ $contrato->renovacao_automatica ? 'Sim' : 'Não' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Aviso de renovação</dt><dd class="mt-1 text-sm text-texto-forte">{{ $contrato->periodo_aviso_dias }} dias</dd></div>
                </dl>
                @if ($contrato->coberturas || $contrato->exclusoes)
                    <div class="grid grid-cols-1 gap-6 border-t border-borda px-6 py-6 sm:grid-cols-2">
                        @if ($contrato->coberturas)
                            <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Coberturas</dt><dd class="mt-1 whitespace-pre-line text-sm text-texto-medio">{{ $contrato->coberturas }}</dd></div>
                        @endif
                        @if ($contrato->exclusoes)
                            <div><dt class="text-xs uppercase tracking-wide text-texto-fraco">Exclusões</dt><dd class="mt-1 whitespace-pre-line text-sm text-texto-medio">{{ $contrato->exclusoes }}</dd></div>
                        @endif
                    </div>
                @endif
            </section>

            {{-- Saldo de visitas (modelo manual) — só aparece se o contrato definiu visitas incluídas. --}}
            @if ($saldo)
                <section class="cartao mt-8 p-6">
                    <div class="flex items-baseline justify-between">
                        <h2 class="text-lg font-semibold text-texto-forte">Saldo de visitas</h2>
                        @if ($saldo['extras'] > 0)
                            <span class="text-sm text-texto-medio">{{ $saldo['extras'] }} {{ \Illuminate\Support\Str::plural('extra', $saldo['extras']) }}</span>
                        @endif
                    </div>
                    <div class="mt-4 flex flex-wrap items-baseline gap-x-8 gap-y-2">
                        <span class="text-sm text-texto-medio"><span class="text-2xl font-semibold text-texto-forte">{{ $saldo['incluidas'] }}</span> incluídas</span>
                        <span class="text-sm text-texto-medio"><span class="text-2xl font-semibold text-texto-forte">{{ $saldo['usadas'] }}</span> usadas</span>
                        <span class="text-sm text-texto-medio"><span class="text-2xl font-semibold {{ $saldo['excedido'] > 0 ? 'text-perigo-600' : 'text-verde-600' }}">{{ $saldo['restantes'] }}</span> restantes</span>
                    </div>
                    @if ($saldo['excedido'] > 0)
                        <p class="mt-3 flex items-center gap-2 text-sm font-medium text-perigo-600">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                            Limite excedido — {{ $saldo['excedido'] }} {{ \Illuminate\Support\Str::plural('visita', $saldo['excedido']) }} além do incluído.
                        </p>
                    @endif
                </section>
            @endif

            {{-- Visitas do contrato (Vaga 2): o saldo dizia "3 usadas" sem mostrar QUAIS —
                 auditar/contestar o número obrigava a ir procurar na agenda à mão. --}}
            @if ($visitas->isNotEmpty())
                <section class="cartao mt-8">
                    <div class="flex items-center justify-between px-6 py-5">
                        <h2 class="text-lg font-semibold text-texto-forte">Visitas do contrato</h2>
                        <span class="text-sm text-texto-fraco">{{ $visitas->count() }}</span>
                    </div>
                    <ul class="border-t border-borda">
                        @foreach ($visitas as $v)
                            <li class="flex flex-wrap items-center justify-between gap-2 border-b border-borda px-6 py-3.5 last:border-0" wire:key="visita-{{ $v->id }}">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-texto-forte">{{ $v->inicio?->translatedFormat('d M Y H:i') ?? '—' }} · {{ $v->titulo }}</div>
                                    <div class="text-xs text-texto-medio">{{ $v->tecnico->nome ?? 'Sem técnico' }} · {{ $v->estado->rotulo() }}</div>
                                </div>
                                @if ($v->cobertura === 'incluida')
                                    <span class="etiqueta bg-verde-50 text-verde-700">Incluída</span>
                                @elseif ($v->cobertura === 'extra')
                                    <span class="etiqueta bg-aviso-100 text-aviso-500">Extra (faturável)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Equipamentos cobertos --}}
            <section class="cartao mt-8">
                <div class="flex items-center justify-between px-6 py-5">
                    <h2 class="text-lg font-semibold text-texto-forte">Equipamentos cobertos</h2>
                    <span class="text-sm text-texto-fraco">{{ $contrato->equipamentos->count() }}</span>
                </div>
                <ul class="border-t border-borda">
                    @forelse ($contrato->equipamentos as $e)
                        <li class="flex items-center justify-between border-b border-borda px-6 py-3.5 last:border-0">
                            <div>
                                <a href="{{ route('equipamentos.ficha', $e) }}" wire:navigate class="text-sm font-medium text-texto-forte hover:text-verde-600">{{ trim($e->fabricante . ' ' . $e->modelo) ?: $e->tipo->rotulo() }}</a>
                                <div class="text-xs text-texto-fraco">{{ $e->numero_serie ?? '—' }}</div>
                                {{-- ONDE está instalado (morada real, não o nome do local). --}}
                                <div class="mt-0.5 flex items-center gap-1 text-xs text-texto-medio">
                                    <svg class="h-3 w-3 shrink-0 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    <span class="truncate">{{ $e->localInstalacao() }}</span>
                                </div>
                            </div>
                            <span class="etiqueta {{ $e->tipo->classesEtiqueta() }}">{{ $e->tipo->rotulo() }}</span>
                        </li>
                    @empty
                        <li class="px-6 py-8 text-center text-sm text-texto-medio">Sem equipamentos associados.</li>
                    @endforelse
                </ul>
            </section>

            {{-- SLAs --}}
            <section class="cartao mt-8">
                <div class="px-6 py-5"><h2 class="text-lg font-semibold text-texto-forte">SLAs</h2></div>
                <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        {{-- (A coluna "Prioridade" saiu — os SLAs deixaram de ser por prioridade.) --}}
                        <tr class="border-y border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3 font-semibold">Tempo de resposta</th>
                            <th class="px-6 py-3 font-semibold">Tempo de resolução</th>
                            <th class="px-6 py-3 font-semibold">Cobertura</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($contrato->slas as $s)
                            <tr class="border-b border-borda last:border-0">
                                <td class="px-6 py-3.5 text-texto-medio">{{ $s->rotuloResposta() }}</td>
                                <td class="px-6 py-3.5 text-texto-medio">{{ $s->tempo_resolucao_horas ? $s->tempo_resolucao_horas . ' h' : '—' }}</td>
                                <td class="px-6 py-3.5 text-texto-medio">{{ $s->horario_cobertura }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-6 py-8 text-center text-sm text-texto-medio">Sem SLAs definidos.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </section>

            {{-- Relatórios realizados no âmbito do contrato (intervencoes.contrato_id). --}}
            <section class="cartao mt-8">
                <div class="flex items-center justify-between px-6 py-5">
                    <h2 class="text-lg font-semibold text-texto-forte">Relatórios do contrato</h2>
                    <span class="text-sm text-texto-fraco">{{ $relatorios->count() }} no total</span>
                </div>
                <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-y border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <th class="px-6 py-3 font-semibold">Nº</th>
                            <th class="px-6 py-3 font-semibold">Data</th>
                            <th class="px-6 py-3 font-semibold">Equipamento</th>
                            <th class="px-6 py-3 font-semibold">Técnico</th>
                            <th class="px-6 py-3 font-semibold">Estado</th>
                            <th class="px-6 py-3 text-right font-semibold">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($relatorios as $r)
                            <tr class="border-b border-borda last:border-0">
                                <td class="px-6 py-3.5 font-medium text-texto-forte">{{ $r->numero ?? 'Rascunho' }}</td>
                                <td class="px-6 py-3.5 text-texto-medio">{{ $r->data?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-6 py-3.5 text-texto-medio">{{ $r->intervencao?->equipamento?->numero_serie ?? '—' }}</td>
                                <td class="px-6 py-3.5 text-texto-medio">{{ $r->intervencao?->tecnico?->nome ?? '—' }}</td>
                                <td class="px-6 py-3.5"><span class="etiqueta {{ $r->estado->classesEtiqueta() }}">{{ $r->estado->rotulo() }}</span></td>
                                <td class="px-6 py-3.5">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($r->estado !== \App\Enums\EstadoRelatorio::Rascunho)
                                            <a href="{{ route('relatorios.pdf', $r) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-verde-600 transition hover:bg-verde-50">PDF</a>
                                        @endif
                                        <a href="{{ route('relatorios.editar', $r) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-texto-medio transition hover:bg-fundo">{{ $r->estado === \App\Enums\EstadoRelatorio::Rascunho ? 'Retomar' : 'Editar' }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-texto-medio">Ainda não há relatórios no âmbito deste contrato.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </section>

        </div>
    </main>
</div>
