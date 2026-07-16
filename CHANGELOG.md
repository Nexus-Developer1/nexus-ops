# Changelog

Registo de alterações do **Nexus Infra**. Mais recente no topo.
Categorias: 🔒 Segurança · 🧰 Funcionalidade · 🎨 UI/Marca · 🧹 Limpeza · 🛠️ Infra
_(itens de infra vivem no servidor e não têm commit)._

---

## 2026-07-16

- 🧹 **Limpeza: enum cases inalcançáveis** — remove `EstadoIntervencao::Cancelada` e `TipoEvento::Ausencia`: nenhum fluxo da app os produz (as ausências vivem em `tecnico_disponibilidade`, não na agenda) e confirmou-se em produção que não existe nenhuma linha com esses valores (`cancelada=0`, `ausencia=0`). Zero mudanças de comportamento — 260 testes verdes.
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
