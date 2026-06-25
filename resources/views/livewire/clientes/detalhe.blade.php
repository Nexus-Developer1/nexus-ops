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

        </div>
    </main>
</div>
