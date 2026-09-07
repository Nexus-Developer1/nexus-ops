# Agenda no Outlook — manual interno

A agenda do Nexus Infra chega ao Outlook por duas vias, que se completam.

| Via | Para quem | Como chega | Quando |
|---|---|---|---|
| **Convites por email** | Técnicos associados a eventos | Email com convite iCalendar (Aceitar / Recusar) — o evento entra no calendário; alterações atualizam-no; remoções cancelam-no | Imediato |
| **Calendário partilhado** | Toda a equipa | Calendário "Agenda Nexus Infra" partilhado a partir da mailbox `Suporte@nxs.pt` (Microsoft Graph), só de leitura | Segundos |

> O **feed ICS de subscrição** (URLs por token, página "Feeds da agenda") foi **removido em 2026-09-04**: exigia a porta 443 aberta ao exterior, nunca chegou a ser usado e o calendário partilhado faz o mesmo melhor.

## 1. Convites por email (técnicos)

Não há nada a configurar. Quando alguém cria um evento na agenda com a opção **"Avisar os técnicos por email"** ligada (é o predefinido), cada técnico marcado recebe um email de `Suporte@nxs.pt` com o convite. No Outlook:

- **Aceitar** mete o evento no calendário (a resposta vai para a caixa Suporte — é informativa, não muda nada na agenda).
- Se o evento for **alterado** (hora, dia, cliente, técnicos), chega um novo convite que **atualiza** o que já lá está — não cria um segundo.
- Se o evento for **removido** (ou o técnico for tirado dele), chega um **cancelamento** que o tira do calendário.

Se um convite não atualizar o evento existente, o mais provável é o Outlook estar a mostrar uma versão antiga: abra o email mais recente e aceite — a versão mais recente ganha sempre.

## 2. Calendário partilhado no Microsoft 365

Em vez de o Outlook ir buscar um feed, **a app escreve os eventos num calendário "Agenda Nexus Infra" na mailbox `Suporte@nxs.pt`** e partilha-o (leitura) com a equipa. Aparece no Outlook de todos como calendário partilhado normal, **em tempo real**, sem configurar nada nos PCs. A ligação é do servidor para a Microsoft (como o email) — não precisa de porta aberta.

### Ativação (uma vez, administrador do M365 + servidor)

1. No **Entra ID → Registos de aplicações → (a app do Nexus Infra) → Permissões de API**: adicionar **Microsoft Graph → Permissões de aplicação → `Calendars.ReadWrite`** e carregar em **Conceder consentimento de administrador**. (Tem de ser *de aplicação*, não *delegada* — a app corre sem utilizador.)
2. No servidor: `MS_GRAPH_CALENDARIO_ATIVO=true` no `.env` + `php artisan optimize`.
3. `php artisan agenda:graph --verificar` → tem de dizer que a permissão existe e que o calendário está OK (cria-o se não existir).
4. `php artisan agenda:graph` → carga inicial (eventos dos últimos 30 e próximos 90 dias).
5. `php artisan agenda:graph --partilhar` → partilha o calendário com toda a equipa ativa.

A partir daí é automático: criar, alterar, arrastar ou remover um evento na agenda reflete-se no calendário partilhado em segundos.

### Para o utilizador

Nada a fazer: o calendário **"Agenda Nexus Infra"** aparece no Outlook (novo, Web e clássico) em **Calendários partilhados** / "Calendários de pessoas". Se não aparecer, no Outlook: **Adicionar calendário → Adicionar a partir do diretório → Suporte@nxs.pt → Agenda Nexus Infra**.

- Eventos cancelados/removidos desaparecem do calendário partilhado.
- É só de leitura — as alterações fazem-se na agenda do Nexus Infra, nunca no Outlook.
- Os técnicos continuam a receber os **convites** dos seus eventos (via 1); o calendário partilhado é a vista geral.
