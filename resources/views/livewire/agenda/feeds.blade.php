<div>
    <x-topbar :breadcrumb="['Início', 'Agenda', 'Feeds']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-5xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Feeds da agenda</h1>
            <p class="mt-2 text-sm text-texto-medio">URLs de subscrição da agenda no Outlook (só leitura, atualiza de hora a hora). Cada pessoa tem o seu URL — é o segredo: quem o tiver vê a agenda. Revogar invalida o URL de imediato.</p>
            <p class="mt-1 text-xs text-texto-fraco">Os técnicos recebem os seus eventos por convite (email); o feed de cada pessoa não inclui os eventos em que ela é convidada — nunca vê nada a dobrar.</p>

            @if (session('sucesso'))
                <div class="mt-6 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm text-verde-700">{{ session('sucesso') }}</div>
            @endif

            <div class="cartao mt-8 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-borda bg-fundo text-xs uppercase tracking-wide text-texto-medio">
                            <tr>
                                <th class="px-5 py-3 text-left">Utilizador</th>
                                <th class="px-5 py-3 text-left">URL do feed</th>
                                <th class="px-5 py-3 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-borda">
                            @foreach ($utilizadores as $u)
                                <tr class="{{ $u['ativo'] ? '' : 'opacity-60' }}">
                                    <td class="px-5 py-4 align-top">
                                        <div class="font-medium text-texto-forte">{{ $u['nome'] }}</div>
                                        <div class="text-xs text-texto-fraco">{{ $u['email'] }} · {{ $u['papel'] }}{{ $u['ativo'] ? '' : ' · inativo' }}</div>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        @if ($u['url'])
                                            <div x-data="{ copiado: false }" class="flex items-center gap-2">
                                                <code class="max-w-md truncate rounded-md bg-fundo px-2 py-1 text-xs text-texto-medio" title="{{ $u['url'] }}">{{ $u['url'] }}</code>
                                                <button type="button"
                                                        @click="navigator.clipboard.writeText(@js($u['url'])).then(() => { copiado = true; setTimeout(() => copiado = false, 2000) })"
                                                        class="botao-secundario px-3 py-1.5 text-xs">
                                                    <span x-show="!copiado">Copiar</span>
                                                    <span x-show="copiado" x-cloak class="text-verde-600">Copiado ✓</span>
                                                </button>
                                            </div>
                                        @else
                                            <span class="text-xs text-texto-fraco">Sem feed</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-right align-top">
                                        @if ($u['tem_feed'])
                                            <button type="button" wire:click="gerar({{ $u['id'] }})" wire:confirm="Regenerar o token de {{ $u['nome'] }}? O URL atual deixa de funcionar e é preciso subscrever o novo no Outlook." class="botao-secundario px-3 py-1.5 text-xs">Regenerar</button>
                                            <button type="button" wire:click="revogar({{ $u['id'] }})" wire:confirm="Revogar o feed de {{ $u['nome'] }}? O Outlook deixa de conseguir atualizar a agenda." class="botao px-3 py-1.5 text-xs text-perigo-600 hover:bg-perigo-100">Revogar</button>
                                        @elseif ($u['ativo'])
                                            <button type="button" wire:click="gerar({{ $u['id'] }})" class="botao-primario px-3 py-1.5 text-xs">Gerar feed</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="cartao mt-6 px-6 py-5 text-sm text-texto-medio">
                <p class="font-semibold text-texto-forte">Subscrever no Outlook</p>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    <li><strong>Outlook novo / Web:</strong> Calendário → <em>Adicionar calendário</em> → <em>Subscrever a partir da Web</em> → colar o URL → nome "Nexus Infra" → <em>Importar</em>.</li>
                    <li><strong>Outlook clássico:</strong> Ficheiro → Definições da Conta → separador <em>Calendários da Internet</em> → <em>Novo…</em> → colar o URL.</li>
                    <li>O Outlook atualiza o feed por si (1h–24h, conforme a versão). Alterações urgentes chegam aos técnicos por convite, não pelo feed.</li>
                </ol>
            </div>
        </div>
    </main>
</div>
