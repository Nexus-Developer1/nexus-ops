# Changelog

Registo de alteraÃ§Ãµes do **Nexus Infra**. Mais recente no topo.
Categorias: ðŸ”’ SeguranÃ§a Â· ðŸ§° Funcionalidade Â· ðŸŽ¨ UI/Marca Â· ðŸ§¹ Limpeza Â· ðŸ› ï¸ Infra
_(itens de infra vivem no servidor e nÃ£o tÃªm commit)._

---

## 2026-07-17

- ðŸ”’ **RevisÃ£o de seguranÃ§a (5.Âª) + endurecimento** â€” revisÃ£o Ã s mudanÃ§as recentes (fotos por equipamento, tÃ©cnico por conta, multi-tÃ©cnico, middleware de headers) e varredura geral: **sem falhas crÃ­ticas/altas/mÃ©dias**. Ãšnico item de cÃ³digo: a resoluÃ§Ã£o das contas de tÃ©cnico ao gravar um evento passa a re-filtrar `papel=tÃ©cnico + ativo` (defesa em profundidade, para alÃ©m da validaÃ§Ã£o que jÃ¡ bloqueava).
- ðŸ§° **Eventos com vÃ¡rios tÃ©cnicos** â€” o campo TÃ©cnico passa a lista de checkboxes (1 ou mais, como os colaboradores do relatÃ³rio): o 1.Âº fica como principal (cor do evento) e os restantes na pivot `evento_tecnicos`. **Todos** contam para conflitos/ausÃªncias, entram no feed iCal de cada um e sÃ£o notificados; o rascunho gerado pela agenda herda os colaboradores. Requer migraÃ§Ã£o. +2 testes (278 no total).
- ðŸ§° **TÃ©cnico dos eventos passa a ser a conta (nÃ£o texto livre)** â€” o campo "TÃ©cnico" ao criar/editar eventos Ã© agora a lista de contas de tÃ©cnico (igual ao relatÃ³rio). Com a conta ligada: **ausÃªncias/fÃ©rias passam a ser detetadas** ao agendar, o evento **entra no feed iCal** do tÃ©cnico, o tÃ©cnico Ã© **notificado**, e as cores ficam estÃ¡veis. Eventos legados (nome em texto): conflito detetado por nome, e ao editar o nome Ã© casado automaticamente com a conta. +3 testes (276 no total). `2ec1f6e`
- ðŸ§° **Fotos do relatÃ³rio por equipamento** â€” o carregamento de fotos deixa de ser um bloco Ãºnico e passa para **dentro da ficha de cada equipamento**; cada foto guarda o `equipamento_id`. No PDF, as fotos aparecem **junto das mediÃ§Ãµes** do equipamento respetivo (e um bloco no fim para equipamentos sem ficha + fotos gerais de relatÃ³rios antigos). Requer migraÃ§Ã£o (`anexos.equipamento_id`). +1 teste (275 no total). `490f47e`
- ðŸ§° **Fotos do relatÃ³rio: seleÃ§Ãµes acumulam (bug)** â€” adicionar uma foto e depois outra apagava a anterior (o `wire:model` do Livewire substitui o array a cada seleÃ§Ã£o). Agora cada seleÃ§Ã£o **acrescenta** (acumula em `$fotosNovas` via `updatedFotos`), e cada foto por gravar tem o seu botÃ£o de remover na prÃ©-visualizaÃ§Ã£o. +1 teste (274 no total). `a371621`
- ðŸ› ï¸ **Scheduler: removido o `schedule:run` duplicado do crontab do root** â€” corria em paralelo com o do `www-data` (redundante; a versÃ£o root podia criar ficheiros de cache/log com dono errado). Fica sÃ³ o do `www-data`, que jÃ¡ era quem fazia os syncs. Confirmado no log do cron: pÃ³s-remoÃ§Ã£o sÃ³ o www-data dispara, a cada minuto. Backup em `/root/crontab.bak-pre-limpeza-schedule`. Infra, sem commit de cÃ³digo.

- ðŸ”’ **CabeÃ§alhos de seguranÃ§a versionados (middleware)** â€” CSP + `nosniff`/`X-Frame`/`Referrer` passam a ser emitidos por um middleware Laravel (`CabecalhosSeguranca`), com a CSP cÃ³pia exata da de produÃ§Ã£o e um teste que garante que nunca desaparecem. Antes viviam sÃ³ na config do Apache (fora do repo, nÃ£o revisÃ¡vel, podia derivar). HSTS fica no Apache (Ã© da camada TLS). Esses 4 headers foram removidos da config do Apache (backup `security-headers.conf.bak-pre-middleware`), ficando cada um a sair uma sÃ³ vez â€” infra, sem commit. `c1ef8ae`
- ðŸ”’ **`rel="noopener noreferrer"` nos links `target="_blank"`** â€” 4.Âª revisÃ£o de seguranÃ§a (PWA/frontend/geral) sem falhas crÃ­ticas/altas/mÃ©dias; Ãºnico item de cÃ³digo corrigido: os 4 links que abrem em nova aba (PDFs de relatÃ³rio, convite) ganham `rel="noopener"` (higiene anti reverse-tabnabbing; eram same-origin, risco jÃ¡ mÃ­nimo). `5715b1e`
- ðŸ§¹ **Limpeza: 3 imports `Carbon` sem uso** (Portal/Dashboard, GeradorEventoDeRelatorio, ServicoAlertas) â€” restos de refactorings; varredura completa nÃ£o encontrou mais nada morto (imports de `app/` todos usados, assets PWA todos referenciados). 272 testes verdes.
- ðŸŽ¨ **Ãcone da app: logÃ³tipo NEXUS** â€” o Ã­cone da PWA passa a ser o wordmark NEXUS a branco sobre o gradiente verde (substitui o raio genÃ©rico da primeira versÃ£o). Nota: gerado do `nexus-1.png` (150Ã—28); com um logÃ³tipo em alta resoluÃ§Ã£o/SVG pode regenerar-se mais nÃ­tido. `9fc165b`
- ðŸ§° **App instalÃ¡vel no telemÃ³vel (PWA)** â€” `manifest.json` + Ã­cones prÃ³prios (192/512/maskable + apple-touch para iOS) e metadados PWA nos layouts. Passa a poder-se "Adicionar ao ecrÃ£ principal" e abrir como app (ecrÃ£ cheio, sem barra do browser). Servido same-origin, coberto pela CSP existente. `64a20f8`
- ðŸŽ¨ **Alvos de toque maiores nos modais da agenda** â€” os botÃµes "âœ•" de fechar passam de ~20px para ~36px (mais fÃ¡ceis de acertar no telemÃ³vel).
- ðŸŽ¨ **Apagar fotos no telemÃ³vel (relatÃ³rios)** â€” o botÃ£o de remover uma foto jÃ¡ carregada sÃ³ aparecia com `hover`, que nÃ£o existe em ecrÃ£ de toque; os tÃ©cnicos nÃ£o conseguiam apagar uma foto no campo. Passa a estar sempre visÃ­vel, com alvo de toque maior e confirmaÃ§Ã£o. +1 teste (272 no total). `4586865`
- ðŸŽ¨ **Arrastar eventos por toque (mudar a hora) no telemÃ³vel** â€” o arrasto vertical de um evento competia com o scroll e ficava aos saltos. Corrigido com `touch-action: none` nos eventos + toque longo mais curto (250 ms para arrastar, 500 ms para criar por seleÃ§Ã£o). Mudar o dia jÃ¡ funcionava; mudar a hora passa a ser fluido. Alternativa precisa: abrir o evento â†’ Editar â†’ hora exacta. `fc0f1ad`
- ðŸŽ¨ **Modais da agenda com scroll em ecrÃ£ pequeno** â€” os modais (novo/editar evento, marcar ausÃªncia, detalhes) ganham altura mÃ¡xima (90% do ecrÃ£) com scroll interno. Antes, em telemÃ³vel/ecrÃ£ baixo, o formulÃ¡rio passava para fora do ecrÃ£ e o botÃ£o "Guardar" ficava inalcanÃ§Ã¡vel. `168c58d`
- ðŸ”’ **Endurecimento de seguranÃ§a (3.Âª revisÃ£o)** â€” revisÃ£o sem falhas crÃ­ticas/altas; fechados 3 pontos de defesa em profundidade: (1) o trait `ApenasEquipa` passa a cobrir **todos** os 23 componentes de equipa (antes sÃ³ 5) â€” um teste descobre-os automaticamente e falha se algum novo ficar sem o guard; (2) `reagendar()` na agenda ganha o seu prÃ³prio `abort_if(ehCliente)` (deixa de depender sÃ³ do trait); (3) o 2.Âº passo do MFA volta a verificar `ativo` â€” uma conta desativada durante a janela do cÃ³digo jÃ¡ nÃ£o completa o login. +2 testes (271 no total). `32c0134`

## 2026-07-16

- ðŸŽ¨ **Cores distintas por tÃ©cnico na agenda** â€” a cor passa a ser atribuÃ­da pela posiÃ§Ã£o na lista ordenada de tÃ©cnicos (1.Âº nome â†’ 1.Âª cor), garantindo cores diferentes atÃ© 6 tÃ©cnicos. O esquema anterior (hash do nome) colidia â€” "Davide Fonseca" e "Rui Moreira" ficavam ambos a azul. +1 teste (269 no total). `ba77462`
- ðŸ§° **Editar eventos convertidos (relatÃ³rio em rascunho)** â€” o "Editar" passa a aparecer tambÃ©m nos eventos que jÃ¡ tÃªm intervenÃ§Ã£o, desde que o relatÃ³rio ainda seja **rascunho** (o caso normal: eventos criados com equipamento convertem logo). Datas/horas propagam-se Ã  intervenÃ§Ã£o (fonte Ãºnica de verdade); equipamento e contrato ficam trancados no formulÃ¡rio (gerem-se no relatÃ³rio). RelatÃ³rios finalizados/enviados continuam a trancar o evento. +1 teste (268 no total). `d7a4c39`
- ðŸ§° **Editar eventos da agenda** â€” novo botÃ£o "Editar" no detalhe do evento: reutiliza o formulÃ¡rio de criaÃ§Ã£o (tipo, tÃ©cnico, equipamento, contrato/cobertura, inÃ­cio/fim) prÃ©-preenchido. A deteÃ§Ã£o de conflitos exclui o prÃ³prio evento. EditÃ¡veis: eventos ainda nÃ£o convertidos em intervenÃ§Ã£o e nÃ£o-preventivos (esses editam-se no relatÃ³rio / sÃ£o geridos pelo contrato). +4 testes (267 no total). `10063c5`
- ðŸ”’ **Endurecimento de seguranÃ§a (revisÃ£o)** â€” sem falhas crÃ­ticas encontradas; corrigidos 5 pontos de defesa em profundidade: (1) o "esqueci password" deixa de revelar se um email existe (mensagem sempre neutra, mesmo em throttle); (2) o login corre sempre um `Hash::check` (contra hash dummy) para nÃ£o vazar a existÃªncia de contas por timing; (3) novo trait `ApenasEquipa` que barra o papel cliente em **todas** as requisiÃ§Ãµes aos componentes de equipa (Agenda, Equipamentos, Contratos), nÃ£o sÃ³ via middleware da rota; (4) `anexos.ver` passa a enviar `X-Content-Type-Options: nosniff` + `Content-Disposition` com nome sanitizado; (5) `SESSION_SECURE_COOKIE=true` documentado no `.env.example` e posto em produÃ§Ã£o. +3 testes (263 no total). `7fa96a4`
- ðŸ§¹ **Limpeza: enum cases inalcanÃ§Ã¡veis** â€” remove `EstadoIntervencao::Cancelada` e `TipoEvento::Ausencia`: nenhum fluxo da app os produz (as ausÃªncias vivem em `tecnico_disponibilidade`, nÃ£o na agenda) e confirmou-se em produÃ§Ã£o que nÃ£o existe nenhuma linha com esses valores (`cancelada=0`, `ausencia=0`). Zero mudanÃ§as de comportamento â€” 260 testes verdes. `79a0c5d`
- ðŸ§¹ **Limpeza: preview/, rotulo() e README** â€” apaga a pasta `preview/` (28 protÃ³tipos HTML/screenshots de junho, prÃ©-implementaÃ§Ã£o; recuperÃ¡veis no histÃ³rico do git), remove `PapelUtilizador::rotulo()` (nunca chamado) e substitui o README boilerplate do Laravel por um README real do projeto (stack, setup dev, testes, comandos, convenÃ§Ãµes). Zero mudanÃ§as de comportamento â€” 260 testes verdes. `3408a31`
- ðŸ§¹ **Limpeza de cÃ³digo morto (2.Âª ronda)** â€” remove a dependÃªncia npm `sortablejs` (nunca importada), o mÃ©todo `Equipamento::cliente()` (0 chamadas), a config morta `erp.sync_hora`/`erp.connections` (+ `ERP_SYNC_HORA` do `.env.example`; a ligaÃ§Ã£o real vive em `config/database.php`) e o plumbing do campo Ã³rfÃ£o `intervencoes.diagnostico` (fillable/cast â€” a coluna e os dados legados ficam na BD, nada os lÃª/escreve). Apagado tambÃ©m o diretÃ³rio local `tools/phc_sync` (sync Python antigo, substituÃ­do pelo `erp:sincronizar-equipamentos`; nÃ£o estava no git). Zero mudanÃ§as de comportamento â€” 260 testes verdes. `b2598b6`

## 2026-07-15

- ðŸ§° **RecomendaÃ§Ãµes por equipamento** â€” o campo "RecomendaÃ§Ãµes e prÃ³ximos passos" (+ prioridade) deixa de ser Ãºnico no relatÃ³rio e passa para **cada ficha de equipamento**; sai na pÃ¡gina da ficha respetiva no PDF. Uma recomendaÃ§Ã£o, por si sÃ³, jÃ¡ faz a ficha persistir. RelatÃ³rios legados mantÃªm a recomendaÃ§Ã£o antiga ("ObservaÃ§Ãµes"). Requer migraÃ§Ã£o. `ff5f90a`
- ðŸŽ¨ **Filtros dos Ativos reorganizados** â€” os separadores de tipo passam para uma linha prÃ³pria; a pesquisa e o filtro de famÃ­lia ficam alinhados por baixo. Mais limpo com muitos tipos/famÃ­lias. `4e81bb6`
- ðŸ§° **Filtro por famÃ­lia nos Ativos** â€” traz a **famÃ­lia** do artigo do PHC (`st.familia`/`st.faminome`, via `ma.ref = st.ref` no sync) e acrescenta um filtro por famÃ­lia na listagem â€” para separar equipamentos (ex.: UPS) de artigos que nÃ£o sÃ£o equipamento (ex.: peÃ§as/reparaÃ§Ã£o). Requer re-sync para popular os existentes. `b25944a`
- ðŸ§° **Tipo de equipamento "Sistema"** â€” tipo genÃ©rico para soluÃ§Ãµes compostas (ex.: WiFi por escola do MunicÃ­pio do Barreiro). Aproveita a lista de componentes, o "sem nÂº de sÃ©rie" e a localizaÃ§Ã£o (a escola) jÃ¡ existentes. `65b3cc6`
- ðŸ§° **Sistema composto / deteÃ§Ã£o de incÃªndio** â€” novo tipo de equipamento "DeteÃ§Ã£o de incÃªndio" + **lista de componentes** (designaÃ§Ã£o + quantidade) no equipamento. Permite criar contratos/relatÃ³rios para sistemas sem nÂº de sÃ©rie e compostos por vÃ¡rias peÃ§as (registo + ficha + PDF). `93a4854`
- ðŸ§° **Banco de baterias no equipamento** â€” secÃ§Ã£o prÃ³pria (nÂº de sÃ©rie, modelo/fabricante, capacidade, nÂº de baterias, data de instalaÃ§Ã£o, prÃ³xima troca); parte do mesmo UPS. EditÃ¡vel na ficha. `6c89fb3`
- ðŸ§° **Cliente final + localizaÃ§Ã£o da instalaÃ§Ã£o** â€” campos de texto livre no equipamento (registo + ficha + PDF do relatÃ³rio, com prioridade sobre a lÃ³gica derivada). `266793f`
- ðŸ§° **Mover equipamento + criar local na hora** â€” no "Alterar local", modo "Novo local" para transferir um equipamento para um cliente sem locais (mudanÃ§a de titularidade). `ee88d21`
- ðŸ§° **Registo manual de equipamento** â€” equipamentos nÃ£o vendidos por nÃ³s (`id_erp` nulo); ficam logo disponÃ­veis em contratos/relatÃ³rios e o sync do ERP nÃ£o lhes toca. `ece9149`
- ðŸ§¹ **Remove a entidade `Componente`** (nunca implementada) + tabela `componentes` (estava vazia). `4fb4456`

## 2026-07-14

- ðŸ§¹ **Remove mÃ©todos/relaÃ§Ãµes de model sem uso** (7 itens confirmados com 0 usos). `04d7c16`
- ðŸ§° **Despesa ligada a intervenÃ§Ã£o** â€” pesquisa por nÂº de relatÃ³rio/sÃ©rie/cliente; herda cliente/equipamento/contrato. `c015bd1`
- ðŸŽ¨ **Logo NEXUS** no menu lateral (app + portal), em vez do texto da marca. `dbdcf37`
- ðŸŽ¨ **Breadcrumb clicÃ¡vel** â€” navegar para as secÃ§Ãµes anteriores pelo topo. `ad4783d`
- ðŸ§° **RelatÃ³rio individual por nÂº de sÃ©rie** â€” escreves o SN e o cliente Ã© resolvido automaticamente. `b5e29b4`
- ðŸ§° **RelatÃ³rios com vÃ¡rios tÃ©cnicos** â€” principal (quem cria) + colaboradores; o PDF lista todos. `6118bb8`
- ðŸ”’ **Atualiza `guzzlehttp/guzzle` + `psr7`** â€” corrige 3 CVE. `0f97109`
- ðŸ”’ **Isolamento por cliente fail-closed** â€” cliente sem `cliente_id` deixa de ver tudo. `54cd99c`
- ðŸ”’ **Rate limiting no login** â€” 5 tentativas/60s por email+IP (anti brute-force). `337cc21`
- ðŸŽ¨ **Marca renomeada** "Nexus Ops" â†’ "Nexus Infra". `04352b9`
- ðŸ”’ **Login em duas etapas (MFA por email)** â€” cÃ³digo de 6 dÃ­gitos, validade 10 min, 5 tentativas, cooldown de reenvio. `cdee9bf`
- ðŸ› ï¸ **Hardening de produÃ§Ã£o** â€” headers HSTS/X-Frame/nosniff/Referrer, **CSP ativo**, `ServerTokens Prod`, `.env` 640, `MAIL_MAILER` duplicado removido, TRACE bloqueado.

## 2026-07-13

- ðŸ› ï¸ **HTTPS em produÃ§Ã£o** â€” certificado Let's Encrypt (`infra.nexus-solutions.pt:9443`), redirect HTTPâ†’HTTPS e bloqueio de acesso por IP (403).
- ðŸŽ¨ **CorreÃ§Ã£o do layout mobile** no editor de contratos. `881edcf`

---

## Pendente (aÃ§Ã£o do utilizador)
- ðŸ”’ SSH: rotar a password do servidor + desativar login por password/root + fail2ban.
- ðŸ› ï¸ RenovaÃ§Ã£o do certificado Let's Encrypt (~outubro, manual â€” DNS-01).
