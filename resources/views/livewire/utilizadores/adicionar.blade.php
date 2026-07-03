<div>
    <x-topbar :breadcrumb="['Utilizadores', 'Adicionar']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-2xl">

            <div>
                <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Adicionar utilizador</h1>
                <p class="mt-2 text-sm text-texto-medio">O utilizador recebe um email de convite para definir a sua palavra-passe. Fica com acesso de técnico.</p>
            </div>

            @if (session('sucesso'))
                <div class="mt-6 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm text-verde-700">
                    {{ session('sucesso') }}
                </div>
            @endif

            <form wire:submit="convidar">
                <section class="cartao mt-7">
                    <div class="space-y-5 px-6 py-6">
                        <div>
                            <label class="campo-label" for="nome">Nome <span class="text-perigo-500">*</span></label>
                            <input id="nome" wire:model="nome" type="text" class="campo-input" placeholder="Nome do utilizador" autocomplete="off">
                            @error('nome') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="campo-label" for="email">Email <span class="text-perigo-500">*</span></label>
                            <input id="email" wire:model="email" type="email" class="campo-input" placeholder="nome@dominio.pt" autocomplete="off">
                            @error('email') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-borda px-6 py-4">
                        <a href="{{ route('dashboard') }}" class="botao-secundario">Cancelar</a>
                        <button type="submit" wire:loading.attr="disabled" wire:target="convidar" class="botao-primario">
                            <span wire:loading.remove wire:target="convidar">Enviar convite</span>
                            <span wire:loading wire:target="convidar">A enviar…</span>
                        </button>
                    </div>
                </section>
            </form>

            {{-- Técnicos já no site: convite pendente (ainda não aceitou) vs aceite (ativo). --}}
            <section class="cartao mt-8">
                <div class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <div>
                        <h2 class="text-base font-semibold text-texto-forte">Técnicos</h2>
                        <p class="mt-0.5 text-xs text-texto-medio">Convites enviados e contas já ativas.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-texto-medio">{{ $tecnicos->count() }}</span>
                </div>

                <ul class="divide-y divide-borda">
                    @forelse ($tecnicos as $tecnico)
                        @php($pendente = $tecnico->password === null)
                        <li class="flex items-center justify-between gap-4 px-6 py-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-texto-forte">{{ $tecnico->nome }}</p>
                                <p class="truncate text-xs text-texto-medio">{{ $tecnico->email }}</p>
                            </div>

                            @if ($pendente)
                                <div class="flex shrink-0 items-center gap-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-aviso-100 px-2.5 py-1 text-xs font-medium text-aviso-500">
                                        <span class="h-1.5 w-1.5 rounded-full bg-aviso-500"></span>
                                        Convite pendente
                                    </span>
                                    <button type="button" wire:click="reenviar({{ $tecnico->id }})"
                                        wire:loading.attr="disabled" wire:target="reenviar({{ $tecnico->id }})"
                                        class="text-xs font-medium text-verde-700 hover:text-verde-800 disabled:opacity-50">
                                        <span wire:loading.remove wire:target="reenviar({{ $tecnico->id }})">Reenviar convite</span>
                                        <span wire:loading wire:target="reenviar({{ $tecnico->id }})">A enviar…</span>
                                    </button>
                                </div>
                            @else
                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-verde-50 px-2.5 py-1 text-xs font-medium text-verde-700">
                                    <span class="h-1.5 w-1.5 rounded-full bg-verde-600"></span>
                                    Ativo
                                </span>
                            @endif
                        </li>
                    @empty
                        <li class="px-6 py-8 text-center text-sm text-texto-medio">Ainda não há técnicos convidados.</li>
                    @endforelse
                </ul>
            </section>

        </div>
    </main>
</div>
