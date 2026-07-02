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

        </div>
    </main>
</div>
