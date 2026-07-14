<div class="flex min-h-screen">

    {{-- Painel de branding (esquerda) --}}
    <div class="bg-sidebar-grad relative hidden w-1/2 flex-col justify-between overflow-hidden p-12 text-white lg:flex">
        <div class="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full bg-verde-500/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-16 h-80 w-80 rounded-full bg-verde-700/10 blur-3xl"></div>

        <div>
            <div class="text-2xl font-bold leading-none text-verde-400">Nexus Infra</div>
            <div class="mt-1 text-[11px] font-medium uppercase tracking-[0.22em] text-white/40">Technical Suite</div>
        </div>

        <div class="relative max-w-md">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/5 text-verde-400 ring-1 ring-white/10">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </span>
            <h2 class="mt-6 text-3xl font-semibold leading-tight">Mais uma verificação,<br>por segurança.</h2>
            <p class="mt-4 text-sm leading-relaxed text-white/55">
                Enviámos um código de utilização única para o seu email. Introduza-o para concluir o início de sessão.
            </p>
        </div>

        <div class="relative flex items-center gap-2 text-xs text-white/40">
            <svg class="h-4 w-4 text-verde-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            Transmissão segura · Nexus Solutions Operations
        </div>
    </div>

    {{-- Painel de formulário (direita) --}}
    <div class="flex w-full items-center justify-center bg-white px-6 py-12 lg:w-1/2">
        <div class="w-full max-w-sm">
            <div class="mb-10 lg:hidden">
                <div class="text-2xl font-bold leading-none text-verde-500">Nexus Infra</div>
                <div class="mt-1 text-[11px] font-medium uppercase tracking-[0.22em] text-texto-fraco">Technical Suite</div>
            </div>

            <h1 class="text-2xl font-semibold tracking-tight text-texto-forte">Verificação em duas etapas</h1>
            <p class="mt-2 text-sm text-texto-medio">
                Enviámos um código de 6 dígitos para
                <span class="font-medium text-texto-forte">{{ $this->emailMascarado }}</span>.
            </p>

            @if (session('reenviado'))
                <div class="mt-5 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm text-verde-700">
                    {{ session('reenviado') }}
                </div>
            @endif

            <form wire:submit="verificar" class="mt-8 space-y-5">
                <div>
                    <label class="campo-label" for="codigo">Código de acesso</label>
                    <input
                        wire:model="codigo"
                        id="codigo"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        placeholder="000000"
                        autofocus
                        class="campo-input text-center text-2xl font-semibold tracking-[0.5em]">
                    @error('codigo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="botao-primario w-full py-3">
                    <span wire:loading.remove wire:target="verificar">Confirmar e entrar</span>
                    <span wire:loading wire:target="verificar">A confirmar…</span>
                </button>
            </form>

            <div class="mt-6 flex items-center justify-between text-sm">
                <a href="{{ route('login') }}" wire:navigate class="font-medium text-texto-medio hover:text-texto-forte">← Voltar ao login</a>
                <button type="button" wire:click="reenviar" wire:loading.attr="disabled" wire:target="reenviar"
                    class="font-medium text-verde-600 hover:text-verde-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="reenviar">Reenviar código</span>
                    <span wire:loading wire:target="reenviar">A reenviar…</span>
                </button>
            </div>

            <p class="mt-8 text-xs leading-relaxed text-texto-fraco">
                O código expira em {{ \App\Services\Auth\ServicoMfa::EXPIRA_MINUTOS }} minutos. Não recebeu? Verifique a pasta de spam ou peça um novo.
            </p>
        </div>
    </div>
</div>
