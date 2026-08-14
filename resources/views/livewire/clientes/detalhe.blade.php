<div>
    <x-topbar :breadcrumb="['Início', 'Clientes', $cliente->nome]">
        <a href="{{ route('clientes') }}" wire:navigate class="botao-secundario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $cliente->nome }}</h1>
            <p class="mt-2 text-sm text-texto-medio">Nº de cliente ERP: {{ $cliente->id_erp ?? '—' }}</p>

            {{-- Dados gerais --}}
            <section class="cartao mt-8 p-6">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-texto-fraco">Dados gerais</h2>
                <dl class="mt-4 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-3">
                    <div><dt class="text-xs text-texto-fraco">Nº cliente (ERP)</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->id_erp ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">NIF</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->nif ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Código postal</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->codpost ?? '—' }}</dd></div>
                    <div class="sm:col-span-3"><dt class="text-xs text-texto-fraco">Morada</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->morada ?? '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-texto-fraco">Email</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->email ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Telefone</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->telefone ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Telemóvel</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->tlmvl ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-texto-fraco">Vendedor</dt><dd class="mt-0.5 text-sm font-medium text-texto-forte">{{ $cliente->vendnm ?? '—' }}</dd></div>
                </dl>
            </section>

            {{-- Contratos --}}
            <section class="cartao mt-5 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-texto-forte">Contratos <span class="text-texto-fraco">({{ $contratosTotal }})</span></h2>
                    @if ($contratosTotal > $limite)
                        <a href="{{ route('clientes.contratos', $cliente) }}" wire:navigate class="text-sm font-medium text-verde-600 hover:text-verde-700">Ver todos →</a>
                    @endif
                </div>
                <div class="mt-4 space-y-2">
                    @forelse ($contratos as $ct)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-borda px-3 py-2" wire:key="ct-{{ $ct->id }}">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-texto-forte">{{ $ct->numero }}</div>
                                <div class="truncate text-xs text-texto-fraco">
                                    {{ $ct->modeloFaturacao?->nome ?? '—' }} ·
                                    {{ $ct->data_inicio?->translatedFormat('d M Y') ?? '—' }} – {{ $ct->data_fim?->translatedFormat('d M Y') ?? '—' }}
                                </div>
                            </div>
                            <span class="etiqueta {{ $ct->estado->classesEtiqueta() }} shrink-0">{{ $ct->estado->rotulo() }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-texto-medio">Sem contratos.</p>
                    @endforelse
                </div>
            </section>

            {{-- Equipamentos --}}
            <section class="cartao mt-5 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-texto-forte">Equipamentos <span class="text-texto-fraco">({{ $equipamentosTotal }})</span></h2>
                    @if ($equipamentosTotal > $limite)
                        <a href="{{ route('clientes.equipamentos', $cliente) }}" wire:navigate class="text-sm font-medium text-verde-600 hover:text-verde-700">Ver todos →</a>
                    @endif
                </div>
                <div class="mt-4 space-y-2">
                    @forelse ($equipamentos as $eq)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-borda px-3 py-2" wire:key="eq-{{ $eq->id }}">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-texto-forte">{{ trim($eq->fabricante . ' ' . $eq->modelo) ?: '—' }}</div>
                                <div class="truncate text-xs text-texto-fraco">Nº série: {{ $eq->numero_serie ?? '—' }} · {{ $eq->local?->designacao ?? '—' }}</div>
                            </div>
                            <span class="etiqueta {{ $eq->estado->classesEtiqueta() }} shrink-0">{{ $eq->estado->rotulo() }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-texto-medio">Sem equipamentos associados.</p>
                    @endforelse
                </div>
            </section>

            {{-- Relatórios --}}
            <section class="cartao mt-5 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-texto-forte">Relatórios <span class="text-texto-fraco">({{ $relatoriosTotal }})</span></h2>
                    @if ($relatoriosTotal > $limite)
                        <a href="{{ route('clientes.relatorios', $cliente) }}" wire:navigate class="text-sm font-medium text-verde-600 hover:text-verde-700">Ver todos →</a>
                    @endif
                </div>
                <div class="mt-4 space-y-2">
                    @forelse ($relatorios as $rl)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-borda px-3 py-2" wire:key="rl-{{ $rl->id }}">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-texto-forte">{{ $rl->numero }}</div>
                                <div class="truncate text-xs text-texto-fraco">
                                    {{ $rl->data?->translatedFormat('d M Y') ?? '—' }}
                                    @if ($rl->intervencao?->equipamento?->numero_serie) · {{ $rl->intervencao->equipamento->numero_serie }} @endif
                                </div>
                            </div>
                            <span class="etiqueta {{ $rl->estado->classesEtiqueta() }} shrink-0">{{ $rl->estado->rotulo() }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-texto-medio">Sem relatórios.</p>
                    @endforelse
                </div>
            </section>

            {{-- Trabalho faturável à parte (Vaga 2): visitas extra + intervenções sem contrato. --}}
            @if ($visitasExtraTotal > 0 || $semContratoTotal > 0)
                <section class="cartao mt-5 p-6">
                    <h2 class="text-sm font-semibold text-texto-forte">Trabalho faturável à parte
                        <span class="text-texto-fraco">({{ $visitasExtraTotal + $semContratoTotal }})</span>
                    </h2>
                    <p class="mt-1 text-xs text-texto-fraco">Visitas marcadas como "extra" e intervenções fora de contrato — o que há para faturar além das avenças.</p>
                    <ul class="mt-4">
                        @foreach ($visitasExtra as $v)
                            <li class="flex items-center justify-between border-b border-borda py-2.5 last:border-0" wire:key="extra-v-{{ $v->id }}">
                                <span class="text-sm text-texto-forte">{{ $v->inicio?->translatedFormat('d M Y') }} · {{ $v->titulo }}</span>
                                <span class="etiqueta bg-aviso-100 text-aviso-500">Visita extra</span>
                            </li>
                        @endforeach
                        @foreach ($semContrato as $i)
                            <li class="flex items-center justify-between border-b border-borda py-2.5 last:border-0" wire:key="extra-i-{{ $i->id }}">
                                <span class="text-sm text-texto-forte">
                                    {{ $i->data_inicio?->translatedFormat('d M Y') ?? '—' }} · {{ $i->tipo->rotulo() }}
                                    · {{ trim(($i->equipamento->fabricante ?? '') . ' ' . ($i->equipamento->modelo ?? '')) ?: ($i->equipamento->numero_serie ?? '—') }}
                                </span>
                                <span class="etiqueta bg-aviso-100 text-aviso-500">Sem contrato</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Encomendas / propostas (dossiês do PHC ligados por cliente_no = id_erp) --}}
            @if ($encomendasTotal > 0)
                <section class="cartao mt-5 p-6">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-texto-forte">Encomendas e propostas <span class="text-texto-fraco">({{ $encomendasTotal }})</span></h2>
                        @if ($encomendasTotal > $limite)
                            <a href="{{ route('encomendas', ['pesquisa' => $cliente->id_erp]) }}" wire:navigate class="text-sm font-medium text-verde-600 hover:text-verde-700">Ver todas →</a>
                        @endif
                    </div>
                    <div class="mt-4 space-y-2">
                        @foreach ($encomendas as $d)
                            <a href="{{ route('encomendas.ficha', $d) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg border border-borda px-3 py-2 transition hover:border-verde-300 hover:bg-fundo" wire:key="enc-{{ $d->id }}">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-texto-forte">{{ $d->tipoRotulo() }} {{ $d->obrano }}/{{ $d->ano }}</div>
                                    <div class="truncate text-xs text-texto-fraco">{{ $d->data?->translatedFormat('d M Y') ?? '—' }}</div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-sm font-medium text-texto-forte">{{ $d->total_debito !== null ? number_format((float) $d->total_debito, 2, ',', ' ') . ' €' : '—' }}</div>
                                    <span class="etiqueta {{ $d->fechada ? 'bg-fundo text-texto-medio' : 'bg-verde-50 text-verde-700' }}">{{ $d->fechada ? 'Fechada' : 'Em aberto' }}</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Faturação (linhas do PHC ligadas por cliente_no = id_erp) --}}
            <section class="cartao mt-5 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-texto-forte">Faturação <span class="text-texto-fraco">({{ $faturacaoTotal }})</span></h2>
                    @if ($faturacaoTotal > $limite)
                        <a href="{{ route('clientes.faturacao', $cliente) }}" wire:navigate class="text-sm font-medium text-verde-600 hover:text-verde-700">Ver todas →</a>
                    @endif
                </div>
                <div class="mt-4 space-y-2">
                    @forelse ($faturacao as $l)
                        @php $nSeries = filled($l->series) ? substr_count($l->series, ',') + 1 : 0; @endphp
                        <a href="{{ route('clientes.fatura', [$cliente, $l]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg border border-borda px-3 py-2 transition hover:border-verde-300 hover:bg-fundo" wire:key="fat-{{ $l->id }}">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-texto-forte">{{ $l->design ?: ($l->ref ?? '—') }}</div>
                                <div class="truncate text-xs text-texto-fraco">
                                    {{ $l->data?->translatedFormat('d M Y') ?? '—' }} · {{ trim($l->nmdoc . ' ' . $l->fno) ?: '—' }}
                                </div>
                            </div>
                            <span class="shrink-0 text-xs text-texto-fraco">{{ $nSeries > 0 ? $nSeries . ' ' . \Illuminate\Support\Str::plural('série', $nSeries) : '—' }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-texto-medio">Sem faturação.</p>
                    @endforelse
                </div>
            </section>

        </div>
    </main>
</div>
