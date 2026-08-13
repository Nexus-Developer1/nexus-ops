<div>
    <x-topbar :breadcrumb="['Alertas']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">
            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Alertas</h1>
            <p class="mt-2 text-sm text-texto-medio">Renovações, baterias, visitas em atraso e SLA em risco — o que precisa de atenção.</p>

            {{-- Resumo por tipo (filtros) — dinâmico: os 4 tipos base aparecem sempre; os
                 restantes (visitas/manutenções programadas, backup) só quando têm alertas.
                 Assim o "Todos" bate SEMPRE com a lista (Vaga 1). --}}
            @php
                $rotulos = [
                    'renovacao' => 'Renovações',
                    'bateria' => 'Baterias',
                    'visita_atraso' => 'Visitas em atraso',
                    'sla' => 'SLA em risco',
                    'visita_programada' => 'Visitas programadas',
                    'manutencao_programada' => 'Manutenções programadas',
                    'backup' => 'Backups',
                ];
                $cartoes = [['tipo' => '', 'rotulo' => 'Todos', 'n' => array_sum($contagens)]];
                foreach ($rotulos as $t => $r) {
                    if (($contagens[$t] ?? 0) > 0 || in_array($t, ['renovacao', 'bateria', 'visita_atraso', 'sla'], true)) {
                        $cartoes[] = ['tipo' => $t, 'rotulo' => $r, 'n' => $contagens[$t] ?? 0];
                    }
                }
            @endphp
            <div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ($cartoes as $c)
                    <button wire:click="filtrar('{{ $c['tipo'] }}')"
                        class="cartao p-4 text-left transition {{ $tipo === $c['tipo'] ? 'ring-2 ring-verde-500' : 'hover:bg-fundo' }}">
                        <div class="text-2xl font-semibold text-texto-forte">{{ $c['n'] }}</div>
                        <div class="mt-1 text-xs font-medium text-texto-medio">{{ $c['rotulo'] }}</div>
                    </button>
                @endforeach
            </div>

            {{-- Lista de alertas --}}
            <div class="cartao mt-6 overflow-hidden">
                <ul>
                    @forelse ($alertas as $a)
                        <li class="flex items-center gap-4 border-b border-borda px-6 py-4 last:border-0" wire:key="alerta-{{ $loop->index }}">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $a['severidade'] === 'alta' ? 'bg-perigo-100 text-perigo-600' : 'bg-aviso-100 text-aviso-500' }}">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-texto-forte">{{ $a['titulo'] }}</div>
                                <div class="text-xs text-texto-medio">{{ $a['descricao'] }}</div>
                            </div>
                            <span class="etiqueta {{ $a['severidade'] === 'alta' ? 'bg-perigo-100 text-perigo-600' : 'bg-aviso-100 text-aviso-500' }}">
                                {{ $a['severidade'] === 'alta' ? 'Alta' : 'Média' }}
                            </span>
                            <a href="{{ $a['url'] }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Ver</a>
                        </li>
                    @empty
                        <li class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <svg class="h-10 w-10 text-verde-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <p class="text-sm font-medium text-texto-forte">Tudo em ordem</p>
                            <p class="text-sm text-texto-medio">Não há alertas neste momento.</p>
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </main>
</div>
