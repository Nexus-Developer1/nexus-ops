<div class="flex min-h-screen">

    {{-- Painel de branding (esquerda) --}}
    <div class="bg-sidebar-grad relative hidden w-1/2 flex-col justify-between overflow-hidden p-12 text-white lg:flex">
        <div class="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full bg-verde-500/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-16 h-80 w-80 rounded-full bg-verde-700/10 blur-3xl"></div>

        <div>
            <div class="text-2xl font-bold leading-none text-verde-400">Nexus Ops</div>
            <div class="mt-1 text-[11px] font-medium uppercase tracking-[0.22em] text-white/40">Technical Suite</div>
        </div>

        <div class="relative max-w-md">
            <h2 class="text-3xl font-semibold leading-tight">A operação técnica,<br>sob controlo.</h2>
            <p class="mt-4 text-sm leading-relaxed text-white/55">
                Contratos, agenda, intervenções e relatórios — a cadeia completa da manutenção, numa só plataforma.
            </p>

            <ul class="mt-10 space-y-5">
                <li class="flex items-center gap-3.5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/5 text-verde-400 ring-1 ring-white/10">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </span>
                    <span class="text-sm font-medium text-white/85">Contratos de manutenção com SLA e periodicidade</span>
                </li>
                <li class="flex items-center gap-3.5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/5 text-verde-400 ring-1 ring-white/10">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </span>
                    <span class="text-sm font-medium text-white/85">Agenda que gera as intervenções dos técnicos</span>
                </li>
                <li class="flex items-center gap-3.5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/5 text-verde-400 ring-1 ring-white/10">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </span>
                    <span class="text-sm font-medium text-white/85">Relatórios PDF enviados ao cliente</span>
                </li>
            </ul>
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
                <div class="text-2xl font-bold leading-none text-verde-500">Nexus Ops</div>
                <div class="mt-1 text-[11px] font-medium uppercase tracking-[0.22em] text-texto-fraco">Technical Suite</div>
            </div>

            <h1 class="text-2xl font-semibold tracking-tight text-texto-forte">Bem-vindo de volta</h1>
            <p class="mt-2 text-sm text-texto-medio">Entre na sua conta para continuar.</p>

            <form wire:submit="autenticar" class="mt-9 space-y-5">
                <div>
                    <label class="campo-label" for="email">Email</label>
                    <input wire:model="email" id="email" type="email" class="campo-input" placeholder="nome@empresa.pt" autocomplete="username" autofocus>
                    @error('email') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <label class="campo-label !mb-0" for="password">Palavra-passe</label>
                        <a href="#" class="text-xs font-medium text-verde-600 hover:text-verde-700">Esqueceu-se da sua palavra-passe?</a>
                    </div>
                    <input wire:model="password" id="password" type="password" class="campo-input" placeholder="••••••••" autocomplete="current-password">
                    @error('password') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                </div>

                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-texto-medio">
                    <input wire:model="manter" type="checkbox" class="h-4 w-4 rounded border-borda accent-verde-600">
                    Manter sessão iniciada
                </label>

                <button type="submit" class="botao-primario w-full py-3">
                    <span wire:loading.remove wire:target="autenticar">Entrar</span>
                    <span wire:loading wire:target="autenticar">A entrar…</span>
                </button>
            </form>
        </div>
    </div>
</div>
