# Agenda no Outlook — manual interno

A agenda do Nexus Infra chega ao Outlook por duas vias. **Cada pessoa usa uma, não as duas** — o feed de cada um já exclui os eventos em que essa pessoa é convidada, por isso ninguém vê nada a dobrar.

| Via | Para quem | Como chega | Quando |
|---|---|---|---|
| **Convites por email** | Técnicos associados a eventos | Email com convite iCalendar (Aceitar / Recusar) — o evento entra no calendário; alterações atualizam-no; remoções cancelam-no | Imediato |
| **Feed de subscrição** | Quem quer ver a agenda geral (coordenação, ecrã, chefia) | Calendário "Nexus Infra" subscrito no Outlook, só leitura | Atualiza de hora a hora (o Outlook decide, 1h–24h) |

## 1. Convites por email (técnicos)

Não há nada a configurar. Quando alguém cria um evento na agenda com a opção **"Avisar os técnicos por email"** ligada (é o predefinido), cada técnico marcado recebe um email de `Suporte@nxs.pt` com o convite. No Outlook:

- **Aceitar** mete o evento no calendário (a resposta vai para a caixa Suporte — é informativa, não muda nada na agenda).
- Se o evento for **alterado** (hora, dia, cliente, técnicos), chega um novo convite que **atualiza** o que já lá está — não cria um segundo.
- Se o evento for **removido** (ou o técnico for tirado dele), chega um **cancelamento** que o tira do calendário.

Se um convite não atualizar o evento existente, o mais provável é o Outlook estar a mostrar uma versão antiga: abra o email mais recente e aceite — a versão mais recente ganha sempre.

## 2. Feed de subscrição (só leitura)

### Obter o URL

1. Peça a um administrador do Nexus Infra para ir a **Feeds da agenda** (menu lateral, só admin) e carregar em **Gerar feed** ao lado do seu nome.
2. Copie o URL (botão **Copiar**). Trate-o como uma palavra-passe — quem o tiver vê a agenda.

### Outlook novo (Windows/Mac) e Outlook na Web

1. Abra o **Calendário**.
2. **Adicionar calendário** → **Subscrever a partir da Web**.
3. Cole o URL do feed.
4. Nome do calendário: **Nexus Infra**. Escolha uma cor. **Importar**.

### Outlook clássico (Windows)

1. **Ficheiro** → **Definições da Conta** → **Definições da Conta…**
2. Separador **Calendários da Internet** → **Novo…**
3. Cole o URL do feed → **Adicionar** → nome **Nexus Infra** → **OK**.

### O que aparece no feed

- Eventos dos **últimos 30 dias** e dos **próximos 90** (não o histórico todo).
- Título = tipo de evento · cliente; local; técnicos; estado.
- Eventos **removidos** ficam riscados durante 30 dias e depois desaparecem.
- **Não** aparecem: notas internas, contactos, dados de faturação — nem os eventos em que o próprio subscritor é convidado (esses chegam por convite).

### Se deixar de atualizar

- O Outlook atualiza feeds subscritos por si, mas pode demorar até 24 h. Para forçar: botão direito no calendário → **Atualizar**.
- Se o URL foi **regenerado ou revogado** na página Feeds da agenda, o antigo deixa de funcionar de imediato: remova o calendário no Outlook e subscreva o URL novo.
- Se perder o URL, peça ao administrador para **Regenerar** — o anterior é invalidado.

## 3. Para administradores

- **Feeds da agenda** (menu lateral): lista da equipa com o URL de cada um, **Gerar feed**, **Regenerar** (invalida o URL antigo) e **Revogar** (deixa de haver feed). Cada ação fica na auditoria.
- Quem sai da empresa: **Revogar** o feed (a desativação da conta também o desliga — o endpoint só responde a contas ativas).
- O feed é servido em `https://infra.nexus-solutions.pt:9443/agenda/feed/<token>.ics` — hostname público de propósito: o Outlook novo e o Web fazem o pedido a partir dos servidores da Microsoft, não do PC. **Enquanto a porta 443 externa não estiver aberta na firewall, o Outlook novo/Web e o Exchange Online não conseguem chegar ao feed** — usar a via 4.

## 4. Calendário partilhado no Microsoft 365 (a via sem porta aberta)

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
