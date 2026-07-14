<div class="flex min-h-screen items-center justify-center bg-white px-6 py-12">
    <div class="w-full max-w-sm">
        <div class="mb-10">
            <div class="text-2xl font-bold leading-none text-verde-500">Nexus Infra</div>
            <div class="mt-1 text-[11px] font-medium uppercase tracking-[0.22em] text-texto-fraco">Technical Suite</div>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight text-texto-forte">Recuperar palavra-passe</h1>
        <p class="mt-2 text-sm text-texto-medio">Indique o seu email e enviaremos um link para definir uma nova palavra-passe.</p>

        @if ($estado)
            <div class="mt-6 flex items-start gap-2 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm font-medium text-verde-700">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                {{ $estado }}
            </div>
        @endif

        <form wire:submit="enviarLink" class="mt-9 space-y-5">
            <div>
                <label class="campo-label" for="email">Email</label>
                <input wire:model="email" id="email" type="email" class="campo-input" placeholder="nome@empresa.pt" autocomplete="username" autofocus>
                @error('email') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="botao-primario w-full py-3">
                <span wire:loading.remove wire:target="enviarLink">Enviar link de recuperação</span>
                <span wire:loading wire:target="enviarLink">A enviar…</span>
            </button>
        </form>

        <a href="{{ route('login') }}" class="mt-6 inline-flex items-center gap-1.5 text-sm font-medium text-verde-600 hover:text-verde-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar ao início de sessão
        </a>
    </div>
</div>
