# Changelog

Registo de alterações do **Nexus Infra**. Mais recente no topo.
Categorias: 🔒 Segurança · 🧰 Funcionalidade · 🎨 UI/Marca · 🧹 Limpeza · 🛠️ Infra
_(itens de infra vivem no servidor e não têm commit)._

---

## 2026-07-17

- 🧰 **Fotos do relatório: seleções acumulam (bug)** — adicionar uma foto e depois outra apagava a anterior (o `wire:model` do Livewire substitui o array a cada seleção). Agora cada seleção **acrescenta** (acumula em `$fotosNovas` via `updatedFotos`), e cada foto por gravar tem o seu botão de remover na pré-visualização. +1 teste (274 no total).
- 🛠️ **Scheduler: removido o `schedule:run` duplicado do crontab do root** — corria em paralelo com o do `www-data` (redundante; a versão root podia criar ficheiros de cache/log com dono errado). Fica só o do `www-data`, que já era quem fazia os syncs. Confirmado no log do cron: pós-remoção só o www-data dispara, a cada minuto. Backup em `/root/crontab.bak-pre-limpeza-schedule`. Infra, sem commit de código.

- 🔒 **Cabeçalhos de segurança versionados (middleware)** — CSP + `nosniff`/`X-Frame`/`Referrer` passam a ser emitidos por um middleware Laravel (`CabecalhosSeguranca`), com a CSP cópia exata da de produção e um teste que garante que nunca desaparecem. Antes viviam só na config do Apache (fora do repo, não revisável, podia derivar). HSTS fica no Apache (é da camada TLS). Esses 4 headers foram removidos da config do Apache (backup `security-headers.conf.bak-pre-middleware`), ficando cada um a sair uma só vez — infra, sem commit. `c1ef8ae`
- 🔒 **`rel="noopener noreferrer"` nos links `target="_blank"`** — 4.ª revisão de segurança (PWA/frontend/geral) sem falhas críticas/altas/médias; único item de código corrigido: os 4 links que abrem em nova aba (PDFs de relatório, convite) ganham `rel="noopener"` (higiene anti reverse-tabnabbing; eram same-origin, risco já mínimo). `5715b1e`
- 🧹 **Limpeza: 3 imports `Carbon` sem uso** (Portal/Dashboard, GeradorEventoDeRelatorio, ServicoAlertas) — restos de refactorings; varredura completa não encontrou mais nada morto (imports de `app/` todos usados, assets PWA todos referenciados). 272 testes verdes.
- 🎨 **Ícone da app: logótipo NEXUS** — o ícone da PWA passa a ser o wordmark NEXUS a branco sobre o gradiente verde (substitui o raio genérico da primeira versão). Nota: gerado do `nexus-1.png` (150×28); com um logótipo em alta resolução/SVG pode regenerar-se mais nítido. `9fc165b`
- 🧰 **App instalável no telemóvel (PWA)** — `manifest.json` + ícones próprios (192/512/maskable + apple-touch para iOS) e metadados PWA nos layouts. Passa a poder-se "Adicionar ao ecrã principal" e abrir como app (ecrã cheio, sem barra do browser). Servido same-origin, coberto pela CSP existente. `64a20f8`
- 🎨 **Alvos de toque maiores nos modais da agenda** — os botões "✕" de fechar passam de ~20px para ~36px (mais fáceis de acertar no telemóvel).
- 🎨 **Apagar fotos no telemóvel (relatórios)** — o botão de remover uma foto já carregada só aparecia com `hover`, que não existe em ecrã de toque; os técnicos não conseguiam apagar uma foto no campo. Passa a estar sempre visível, com alvo de toque maior e confirmação. +1 teste (272 no total). `4586865`
- 🎨 **Arrastar eventos por toque (mudar a hora) no telemóvel** — o arrasto vertical de um evento competia com o scroll e ficava aos saltos. Corrigido com `touch-action: none` nos eventos + toque longo mais curto (250 ms para arrastar, 500 ms para criar por seleção). Mudar o dia já funcionava; mudar a hora passa a ser fluido. Alternativa precisa: abrir o evento → Editar → hora exacta. `fc0f1ad`
- 🎨 **Modais da agenda com scroll em ecrã pequeno** — os modais (novo/editar evento, marcar ausência, detalhes) ganham altura máxima (90% do ecrã) com scroll interno. Antes, em telemóvel/ecrã baixo, o formulário passava para fora do ecrã e o botão "Guardar" ficava inalcançável. `168c58d`
- 🔒 **Endurecimento de segurança (3.ª revisão)** — revisão sem falhas críticas/altas; fechados 3 pontos de defesa em profundidade: (1) o trait `ApenasEquipa` passa a cobrir **todos** os 23 componentes de equipa (antes só 5) — um teste descobre-os automaticamente e falha se algum novo ficar sem o guard; (2) `reagendar()` na agenda ganha o seu próprio `abort_if(ehCliente)` (deixa de depender só do trait); (3) o 2.º passo do MFA volta a verificar `ativo` — uma conta desativada durante a janela do código já não completa o login. +2 testes (271 no total). `32c0134`

## 2026-07-16

- 🎨 **Cores distintas por técnico na agenda** — a cor passa a ser atribuída pela posição na lista ordenada de técnicos (1.º nome → 1.ª cor), garantindo cores diferentes até 6 técnicos. O esquema anterior (hash do nome) colidia — "Davide Fonseca" e "Rui Moreira" ficavam ambos a azul. +1 teste (269 no total). `ba77462`
- 🧰 **Editar eventos convertidos (relatório em rascunho)** — o "Editar" passa a aparecer também nos eventos que já têm intervenção, desde que o relatório ainda seja **rascunho** (o caso normal: eventos criados com equipamento convertem logo). Datas/horas propagam-se à intervenção (fonte única de verdade); equipamento e contrato ficam trancados no formulário (gerem-se no relatório). Relatórios finalizados/enviados continuam a trancar o evento. +1 teste (268 no total). `d7a4c39`
- 🧰 **Editar eventos da agenda** — novo botão "Editar" no detalhe do evento: reutiliza o formulário de criação (tipo, técnico, equipamento, contrato/cobertura, início/fim) pré-preenchido. A deteção de conflitos exclui o próprio evento. Editáveis: eventos ainda não convertidos em intervenção e não-preventivos (esses editam-se no relatório / são geridos pelo contrato). +4 testes (267 no total). `10063c5`
- 🔒 **Endurecimento de segurança (revisão)** — sem falhas críticas encontradas; corrigidos 5 pontos de defesa em profundidade: (1) o "esqueci password" deixa de revelar se um email existe (mensagem sempre neutra, mesmo em throttle); (2) o login corre sempre um `Hash::check` (contra hash dummy) para não vazar a existência de contas por timing; (3) novo trait `ApenasEquipa` que barra o papel cliente em **todas** as requisições aos componentes de equipa (Agenda, Equipamentos, Contratos), não só via middleware da rota; (4) `anexos.ver` passa a enviar `X-Content-Type-Options: nosniff` + `Content-Disposition` com nome sanitizado; (5) `SESSION_SECURE_COOKIE=true` documentado no `.env.example` e posto em produção. +3 testes (263 no total). `7fa96a4`
- 🧹 **Limpeza: enum cases inalcançáveis** — remove `EstadoIntervencao::Cancelada` e `TipoEvento::Ausencia`: nenhum fluxo da app os produz (as ausências vivem em `tecnico_disponibilidade`, não na agenda) e confirmou-se em produção que não existe nenhuma linha com esses valores (`cancelada=0`, `ausencia=0`). Zero mudanças de comportamento — 260 testes verdes. `79a0c5d`
- 🧹 **Limpeza: preview/, rotulo() e README** — apaga a pasta `preview/` (28 protótipos HTML/screenshots de junho, pré-implementação; recuperáveis no histórico do git), remove `PapelUtilizador::rotulo()` (nunca chamado) e substitui o README boilerplate do Laravel por um README real do projeto (stack, setup dev, testes, comandos, convenções). Zero mudanças de comportamento — 260 testes verdes. `3408a31`
- 🧹 **Limpeza de código morto (2.ª ronda)** — remove a dependência npm `sortablejs` (nunca importada), o método `Equipamento::cliente()` (0 chamadas), a config morta `erp.sync_hora`/`erp.connections` (+ `ERP_SYNC_HORA` do `.env.example`; a ligação real vive em `config/database.php`) e o plumbing do campo órfão `intervencoes.diagnostico` (fillable/cast — a coluna e os dados legados ficam na BD, nada os lê/escreve). Apagado também o diretório local `tools/phc_sync` (sync Python antigo, substituído pelo `erp:sincronizar-equipamentos`; não estava no git). Zero mudanças de comportamento — 260 testes verdes. `b2598b6`

## 2026-07-15

- 🧰 **Recomendações por equipamento** — o campo "Recomendações e próximos passos" (+ prioridade) deixa de ser único no relatório e passa para **cada ficha de equipamento**; sai na página da ficha respetiva no PDF. Uma recomendação, por si só, já faz a ficha persistir. Relatórios legados mantêm a recomendação antiga ("Observações"). Requer migração. `ff5f90a`
- 🎨 **Filtros dos Ativos reorganizados** — os separadores de tipo passam para uma linha própria; a pesquisa e o filtro de família ficam alinhados por baixo. Mais limpo com muitos tipos/famílias. `4e81bb6`
- 🧰 **Filtro por família nos Ativos** — traz a **família** do artigo do PHC (`st.familia`/`st.faminome`, via `ma.ref = st.ref` no sync) e acrescenta um filtro por família na listagem — para separar equipamentos (ex.: UPS) de artigos que não são equipamento (ex.: peças/reparação). Requer re-sync para popular os existentes. `b25944a`
- 🧰 **Tipo de equipamento "Sistema"** — tipo genérico para soluções compostas (ex.: WiFi por escola do Município do Barreiro). Aproveita a lista de componentes, o "sem nº de série" e a localização (a escola) já existentes. `65b3cc6`
- 🧰 **Sistema composto / deteção de incêndio** — novo tipo de equipamento "Deteção de incêndio" + **lista de componentes** (designação + quantidade) no equipamento. Permite criar contratos/relatórios para sistemas sem nº de série e compostos por várias peças (registo + ficha + PDF). `93a4854`
- 🧰 **Banco de baterias no equipamento** — secção própria (nº de série, modelo/fabricante, capacidade, nº de baterias, data de instalação, próxima troca); parte do mesmo UPS. Editável na ficha. `6c89fb3`
- 🧰 **Cliente final + localização da instalação** — campos de texto livre no equipamento (registo + ficha + PDF do relatório, com prioridade sobre a lógica derivada). `266793f`
- 🧰 **Mover equipamento + criar local na hora** — no "Alterar local", modo "Novo local" para transferir um equipamento para um cliente sem locais (mudança de titularidade). `ee88d21`
- 🧰 **Registo manual de equipamento** — equipamentos não vendidos por nós (`id_erp` nulo); ficam logo disponíveis em contratos/relatórios e o sync do ERP não lhes toca. `ece9149`
- 🧹 **Remove a entidade `Componente`** (nunca implementada) + tabela `componentes` (estava vazia). `4fb4456`

## 2026-07-14

- 🧹 **Remove métodos/relações de model sem uso** (7 itens confirmados com 0 usos). `04d7c16`
- 🧰 **Despesa ligada a intervenção** — pesquisa por nº de relatório/série/cliente; herda cliente/equipamento/contrato. `c015bd1`
- 🎨 **Logo NEXUS** no menu lateral (app + portal), em vez do texto da marca. `dbdcf37`
- 🎨 **Breadcrumb clicável** — navegar para as secções anteriores pelo topo. `ad4783d`
- 🧰 **Relatório individual por nº de série** — escreves o SN e o cliente é resolvido automaticamente. `b5e29b4`
- 🧰 **Relatórios com vários técnicos** — principal (quem cria) + colaboradores; o PDF lista todos. `6118bb8`
- 🔒 **Atualiza `guzzlehttp/guzzle` + `psr7`** — corrige 3 CVE. `0f97109`
- 🔒 **Isolamento por cliente fail-closed** — cliente sem `cliente_id` deixa de ver tudo. `54cd99c`
- 🔒 **Rate limiting no login** — 5 tentativas/60s por email+IP (anti brute-force). `337cc21`
- 🎨 **Marca renomeada** "Nexus Ops" → "Nexus Infra". `04352b9`
- 🔒 **Login em duas etapas (MFA por email)** — código de 6 dígitos, validade 10 min, 5 tentativas, cooldown de reenvio. `cdee9bf`
- 🛠️ **Hardening de produção** — headers HSTS/X-Frame/nosniff/Referrer, **CSP ativo**, `ServerTokens Prod`, `.env` 640, `MAIL_MAILER` duplicado removido, TRACE bloqueado.

## 2026-07-13

- 🛠️ **HTTPS em produção** — certificado Let's Encrypt (`infra.nexus-solutions.pt:9443`), redirect HTTP→HTTPS e bloqueio de acesso por IP (403).
- 🎨 **Correção do layout mobile** no editor de contratos. `881edcf`

---

## Pendente (ação do utilizador)
- 🔒 SSH: rotar a password do servidor + desativar login por password/root + fail2ban.
- 🛠️ Renovação do certificado Let's Encrypt (~outubro, manual — DNS-01).
