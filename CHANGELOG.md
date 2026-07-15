# Changelog

Registo de alterações do **Nexus Infra**. Mais recente no topo.
Categorias: 🔒 Segurança · 🧰 Funcionalidade · 🎨 UI/Marca · 🧹 Limpeza · 🛠️ Infra
_(itens de infra vivem no servidor e não têm commit)._

---

## 2026-07-15

- 🧰 **Sistema composto / deteção de incêndio** — novo tipo de equipamento "Deteção de incêndio" + **lista de componentes** (designação + quantidade) no equipamento. Permite criar contratos/relatórios para sistemas sem nº de série e compostos por várias peças (registo + ficha + PDF).
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
