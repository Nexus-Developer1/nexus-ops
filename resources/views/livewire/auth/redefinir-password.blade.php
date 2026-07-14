<div class="flex min-h-screen items-center justify-center bg-white px-6 py-12">
    <div class="w-full max-w-sm">
        <div class="mb-10">
            <div class="text-2xl font-bold leading-none text-verde-500">Nexus Infra</div>
            <div class="mt-1 text-[11px] font-medium uppercase tracking-[0.22em] text-texto-fraco">Technical Suite</div>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight text-texto-forte">Definir nova palavra-passe</h1>
        <p class="mt-2 text-sm text-texto-medio">Escolha uma nova palavra-passe para a sua conta.</p>

        <form wire:submit="redefinir" class="mt-9 space-y-5">
            <div>
                <label class="campo-label" for="email">Email</label>
                <input wire:model="email" id="email" type="email" class="campo-input" autocomplete="username">
                @error('email') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="campo-label" for="password">Nova palavra-passe</label>
                <div class="relative" x-data="{ mostrar: false }">
                    <input wire:model="password" id="password" :type="mostrar ? 'text' : 'password'" class="campo-input pr-11" placeholder="••••••••" autocomplete="new-password">
                    <button type="button" @click="mostrar = !mostrar" :aria-label="mostrar ? 'Ocultar palavra-passe' : 'Mostrar palavra-passe'" class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-texto-fraco transition hover:text-texto-medio">
                        <svg x-show="!mostrar" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <svg x-show="mostrar" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                    </button>
                </div>
                @error('password') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-xs text-texto-fraco">Mínimo 8 caracteres.</p>
            </div>

            <div>
                <label class="campo-label" for="password_confirmation">Confirmar palavra-passe</label>
                <input wire:model="password_confirmation" id="password_confirmation" type="password" class="campo-input" placeholder="••••••••" autocomplete="new-password">
            </div>

            <button type="submit" class="botao-primario w-full py-3">
                <span wire:loading.remove wire:target="redefinir">Redefinir palavra-passe</span>
                <span wire:loading wire:target="redefinir">A guardar…</span>
            </button>
        </form>

        <a href="{{ route('login') }}" class="mt-6 inline-flex items-center gap-1.5 text-sm font-medium text-verde-600 hover:text-verde-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar ao início de sessão
        </a>
    </div>
</div>
