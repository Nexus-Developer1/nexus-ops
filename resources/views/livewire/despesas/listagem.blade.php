<div>
    <x-topbar :breadcrumb="['Início', 'Despesas']">
        <a href="{{ route('despesas.nova') }}" wire:navigate class="botao-primario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
            Nova despesa
        </a>
    </x-topbar>

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-6xl">

            <x-toast-sucesso />

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Despesas</h1>

            {{-- Processo de validação: atalho para as pendentes de aprovação. --}}
            @if ($pendentes > 0)
                <button type="button" wire:click="$set('estado', 'pendente')" class="mt-4 inline-flex items-center gap-2 rounded-lg border border-aviso-200 bg-aviso-100 px-3 py-2 text-sm font-medium text-aviso-500">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ $pendentes }} {{ $pendentes === 1 ? 'despesa pendente de aprovação' : 'despesas pendentes de aprovação' }}
                </button>
            @endif

            {{-- Filtros — largura total no telemóvel, lado a lado no desktop. --}}
            <div class="mt-6 grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:items-center">
                <select wire:model.live="periodo" class="campo-select w-full sm:w-40">
                    <option value="mes">Este mês</option>
                    <option value="tudo">Todo o período</option>
                </select>
                <select wire:model.live="categoria" class="campo-select w-full sm:w-44">
                    <option value="">Todas as categorias</option>
                    @foreach ($categorias as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
                <select wire:model.live="estado" class="campo-select w-full sm:w-44">
                    <option value="">Todos os estados</option>
                    @foreach ($estados as $e)
                        <option value="{{ $e->value }}">{{ $e->rotulo() }}</option>
                    @endforeach
                </select>
                <select wire:model.live="colaborador" class="campo-select w-full sm:w-44">
                    <option value="">Todos os colaboradores</option>
                    @foreach ($colaboradores as $u)
                        <option value="{{ $u->id }}">{{ $u->nome }}</option>
                    @endforeach
                </select>
                <div class="relative col-span-2 sm:col-span-1 sm:min-w-56 sm:flex-1">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 17a6 6 0 100-12 6 6 0 000 12z"/></svg>
                    <input wire:model.live.debounce.300ms="pesquisa" type="text" class="campo-input pl-10" placeholder="Pesquisar por descrição ou colaborador...">
                </div>
            </div>

            {{-- MOBILE (< md): um cartão por registo. --}}
            <div class="mt-5 space-y-3 md:hidden">
                @forelse ($registos as $r)
                    @php($datasM = $r->despesas->pluck('data')->sort())
                    @php($descricoesM = $r->despesas->pluck('descricao')->unique()->values())
                    <div class="cartao p-4" wire:key="reg-m-{{ $r->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-texto-forte">
                                    <a href="{{ route('despesas.registo.ficha', $r) }}" wire:navigate>{{ \Illuminate\Support\Str::limit($descricoesM->first() ?? '—', 34) }}</a>@if ($descricoesM->count() > 1) <span class="text-xs font-normal text-texto-fraco">+{{ $descricoesM->count() - 1 }}</span>@endif
                                </p>
                                <span class="etiqueta mt-1 {{ $r->estado->classesEtiqueta() }}">{{ $r->estado->rotulo() }}</span>
                                <p class="mt-0.5 text-xs text-texto-medio">
                                    {{ $datasM->first()?->translatedFormat('d M') ?? '—' }}@if ($datasM->count() > 1 && ! $datasM->first()->isSameDay($datasM->last()))–{{ $datasM->last()->translatedFormat('d M') }}@endif
                                    · {{ $r->colaborador?->nome ?? '—' }} · {{ $r->despesas->count() }} {{ \Illuminate\Support\Str::plural('lançamento', $r->despesas->count()) }}
                                </p>
                            </div>
                            <span class="shrink-0 text-base font-semibold text-texto-forte">{{ number_format((float) $r->despesas->sum('valor'), 2, ',', ' ') }} €</span>
                        </div>
                        <div class="mt-3 flex items-center gap-4 border-t border-borda pt-3">
                            <a href="{{ route('despesas.registo.ficha', $r) }}" wire:navigate class="text-sm font-medium text-verde-600">Ver</a>
                            @if ($r->podeSerEditado())<a href="{{ route('despesas.registo.editar', $r) }}" wire:navigate class="text-sm font-medium text-texto-medio">Editar</a>@endif
                            <a href="{{ route('despesas.registo.pdf', $r) }}" class="text-sm font-medium text-texto-medio">PDF</a>
                            <button wire:click="eliminar({{ $r->id }})" wire:confirm="Eliminar este registo de despesas? Fica recuperável." class="ml-auto text-texto-fraco hover:text-perigo-600" title="Eliminar">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="cartao p-8 text-center">
                        <p class="text-sm text-texto-medio">Sem despesas no período/filtros selecionados.</p>
                        <p class="mt-1 text-xs text-texto-fraco">Use "Nova despesa" para registar a primeira.</p>
                    </div>
                @endforelse
            </div>

            {{-- DESKTOP (md+): tabela de REGISTOS — uma entrada por documento. --}}
            <div class="cartao mt-5 hidden overflow-x-auto md:block">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="border-b border-borda text-left text-xs uppercase tracking-wide text-texto-fraco">
                            <th class="px-6 py-3 font-semibold">Data(s)</th>
                            <th class="px-6 py-3 font-semibold">Descrição</th>
                            <th class="px-6 py-3 font-semibold">Colaborador</th>
                            <th class="px-6 py-3 font-semibold">Estado</th>
                            <th class="px-6 py-3 font-semibold">Lançamentos</th>
                            <th class="px-6 py-3 text-right font-semibold">Total</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($registos as $r)
                            @php($datas = $r->despesas->pluck('data')->sort())
                            @php($descricoes = $r->despesas->pluck('descricao')->unique()->values())
                            <tr class="border-b border-borda last:border-0 hover:bg-fundo">
                                <td class="whitespace-nowrap px-6 py-3.5 text-texto-medio">
                                    {{ $datas->first()?->translatedFormat('d M Y') ?? '—' }}@if ($datas->count() > 1 && ! $datas->first()->isSameDay($datas->last())) – {{ $datas->last()->translatedFormat('d M Y') }}@endif
                                </td>
                                <td class="px-6 py-3.5 font-medium text-texto-forte">
                                    <a href="{{ route('despesas.registo.ficha', $r) }}" wire:navigate class="hover:underline">{{ \Illuminate\Support\Str::limit($descricoes->first() ?? '—', 40) }}</a>@if ($descricoes->count() > 1) <span class="text-xs font-normal text-texto-fraco">+{{ $descricoes->count() - 1 }}</span>@endif
                                </td>
                                <td class="px-6 py-3.5 text-texto-medio">{{ $r->colaborador?->nome ?? '—' }}</td>
                                <td class="px-6 py-3.5"><span class="etiqueta {{ $r->estado->classesEtiqueta() }}">{{ $r->estado->rotulo() }}</span></td>
                                <td class="px-6 py-3.5 text-texto-medio">{{ $r->despesas->count() }}</td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-medium text-texto-forte">{{ number_format((float) $r->despesas->sum('valor'), 2, ',', ' ') }} €</td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right">
                                    <a href="{{ route('despesas.registo.ficha', $r) }}" wire:navigate class="text-sm font-medium text-verde-600 hover:underline">Ver</a>
                                    @if ($r->podeSerEditado())<a href="{{ route('despesas.registo.editar', $r) }}" wire:navigate class="ml-3 text-sm font-medium text-texto-medio hover:text-texto-forte">Editar</a>@endif
                                    <a href="{{ route('despesas.registo.pdf', $r) }}" class="ml-3 text-sm font-medium text-texto-medio hover:text-texto-forte">PDF</a>
                                    <button wire:click="eliminar({{ $r->id }})" wire:confirm="Eliminar este registo de despesas? Fica recuperável." class="ml-3 text-texto-fraco hover:text-perigo-600" title="Eliminar">
                                        <svg class="inline h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <p class="text-sm text-texto-medio">Sem despesas no período/filtros selecionados.</p>
                                    <p class="mt-1 text-xs text-texto-fraco">Use "Nova despesa" para registar a primeira.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-5">{{ $registos->links() }}</div>
        </div>
    </main>
</div>
