// Sidebar partilhada por todas as paginas do preview.
// Fonte unica de verdade da navegacao -> vira <x-sidebar :ativo="..."> em Blade.
// Renderiza dentro de [data-sidebar] antes do Alpine arrancar.

const ITENS_NAV = [
    { id: 'dashboard',  label: 'Dashboard',  href: 'dashboard.html',  icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM13 5a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1h-5a1 1 0 01-1-1V5zM4 14a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1v-5zM13 14a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1h-5a1 1 0 01-1-1v-5z"/>' },
    { id: 'ativos',     label: 'Ativos',     href: 'ativos.html',     icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7l2 2 4-4"/>' },
    { id: 'relatorios', label: 'Relatórios', href: 'relatorios.html', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>' },
    { id: 'manutencao', label: 'Manutenção', href: 'manutencao.html', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>' },
];

function renderSidebar(ativo) {
    const itens = ITENS_NAV.map((it) => {
        const on = it.id === ativo;
        const barra = on
            ? '<span class="absolute left-0 top-1/2 h-7 w-1 -translate-x-5 -translate-y-1/2 rounded-r-full bg-sidebar-barra"></span>'
            : '';
        return `
            <a href="${it.href}" class="nav-item ${on ? 'nav-item-ativo' : ''}">
                ${barra}
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">${it.icon}</svg>
                <span class="${on ? 'font-semibold' : ''}">${it.label}</span>
            </a>`;
    }).join('');

    const html = `
        <aside class="bg-sidebar-grad sticky top-0 flex h-screen w-sidebar shrink-0 flex-col self-start px-5 py-7">
            <div class="px-2">
                <div class="text-2xl font-bold leading-none text-verde-400">Nexus Ops</div>
                <div class="mt-1 text-[11px] font-medium uppercase tracking-[0.22em] text-white/40">Technical Suite</div>
            </div>

            <nav class="mt-10 flex flex-1 flex-col gap-1.5">${itens}</nav>

            <a href="formulario.html" class="botao-primario mt-6 w-full py-3">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                Novo Relatório
            </a>

            <div class="mt-6 flex items-center gap-3 border-t border-white/10 pt-5">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-verde-600 text-sm font-semibold text-white">AN</div>
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-white">Admin Nexus</div>
                    <div class="truncate text-xs text-white/45">admin@nexus.pt</div>
                </div>
            </div>
        </aside>`;

    document.querySelectorAll('[data-sidebar]').forEach((el) => { el.outerHTML = html; });
}
