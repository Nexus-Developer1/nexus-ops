# CLAUDE.md — Plataforma CMMS (Gestão e Manutenção de Equipamentos)

> Documento de contexto para desenvolvimento assistido com Claude Code.
> Fonte: Guia de Desenvolvimento v1.0 (09-06-2026). Projeto greenfield — não assumir stack ou infraestrutura prévia.

---

## 1. O que é este projeto

Aplicação web de CMMS (Computerized Maintenance Management System) com componente de Field Service e portal de cliente, para gestão de equipamentos (UPS, geradores, PDUs), planeamento e registo de manutenções, gestão de contratos, agendamento de intervenções e emissão de relatórios PDF para clientes com anexos (fotos/documentos).

A aplicação tem **base de dados própria (PostgreSQL)** e **lê dados de uma BD SQL interna onde reside o ERP** (read-only).

### A cadeia central do domínio (interiorizar antes de escrever código)

```
Contrato (nº de visitas incluídas + equipamentos cobertos)
   → técnico agenda as visitas à mão na Agenda (ligadas ao contrato)
   → técnico executa → vira Intervenção
   → da Intervenção sai um Relatório
   → enviado ao Cliente
```

**Regra:** se este fluxo estiver bem modelado, a ferramenta é coerente. Cada módulo NÃO é uma ilha — todas as features devem reforçar esta cadeia.

### Os 5 módulos

1. **Equipamentos / Ativos** — registo central
2. **Contratos de manutenção** — âmbito, nº de visitas incluídas, SLA
3. **Agenda / Calendário** — vista temporal por técnico e cliente
4. **Intervenções (ordens de trabalho)** — execução: trabalho, medições, checklist, fotos
5. **Relatórios** — documento gerado da intervenção, enviado ao cliente

---

## 2. Decisões de arquitetura (invariantes)

1. **Direção da integração ERP:**
   - ERP = fonte de verdade para **clientes** (e possivelmente contratos/faturação)
   - Aplicação = fonte de verdade para **equipamentos, agenda, intervenções, relatórios**
   - Dados oriundos do ERP são **read-only** na aplicação
2. **Anexos NUNCA na base de dados.** Object storage (MinIO/S3) ou filesystem estruturado; a BD guarda apenas metadados + `storage_key`.
3. **Isolamento entre clientes imposto na camada de dados**, não apenas na UI. Cada query do portal filtra obrigatoriamente por `cliente_id` do utilizador autenticado.

---

## 3. Stack tecnológica

> ⚠️ DECISÃO PENDENTE — confirmar antes de gerar código (ver secção 10).

| Opção | Backend | Quando |
|---|---|---|
| **A (recomendada — mais rápida)** | Laravel + Filament (PHP) | Prioridade em entregar depressa; admin quase gratuito, CRUD declarativo, bom PDF |
| B | Django + DRF (Python) | Equipa de perfil Python |
| C | NestJS + React/Next (TS) | Capacidade frontend dedicada |

### Componentes comuns (independentes da opção)

- **BD da aplicação:** PostgreSQL (JSON, tipos de data/recorrência)
- **Object storage:** MinIO (S3-compatible) ou filesystem estruturado
- **Reverse proxy / TLS:** nginx ou Caddy
- **PDF:** HTML→PDF via Chromium/Puppeteer (fidelidade com fotos/tabelas) ou WeasyPrint/DomPDF (mais leve)
- **Jobs assíncronos:** fila (Redis + worker) — geração de PDF, envio de emails
- **Calendário frontend:** FullCalendar ou equivalente (dia/semana/mês, recursos por técnico, drag-and-drop)

---

## 4. Modelo de dados

**Convenções:** nomes de tabelas/colunas em **português**, snake_case, consistência desde o início. Dados do ERP correlacionados por `id_erp` e read-only na aplicação.

### Mestres

- `clientes` — id, id_erp, nome, nif, contactos, morada *(origem: ERP)*
- `locais` — id, cliente_id, designacao, morada, coordenadas, notas_acesso *(origem: aplicação)*
- `utilizadores` — id, nome, email, papel (admin/tecnico/cliente), cliente_id (só para clientes), ativo

### Equipamentos

- `equipamentos` — id, local_id, tipo (UPS/gerador/PDU…), fabricante, modelo, numero_serie, data_instalacao, fim_garantia, estado, qr_code
- Atributos UPS (tabela filha ou JSONB) — potencia_kva, topologia, num_baterias, data_baterias, **proxima_troca_baterias**, firmware, autonomia_min
- `componentes` — equipamento_id, tipo, data_instalacao, data_substituicao, numero_serie *(histórico de baterias e peças)*

### Contratos

- `contratos` — numero, cliente_id, data_inicio, data_fim, **visitas_incluidas** (nº fixo de visitas pela vida do contrato; null = sem controlo de saldo), estado (rascunho/ativo/suspenso/expirado/renovado), tipo (preventiva/corretiva/full-service), modelo de faturação (avença/por visita/T&M), valor, periodo_faturacao, coberturas/exclusões, renovacao_automatica, periodo_aviso_dias
- `contrato_equipamentos` — N:M contrato↔equipamento
- `contrato_slas` — por prioridade (crítica/alta/normal): tempo_resposta, tempo_resolucao, horário de cobertura (8x5/24x7) → medidos contra timestamps das intervenções corretivas

### Agenda

- `eventos_agenda` — id, tipo (visita_preventiva/intervencao/ausencia/outro), titulo, inicio, fim, tecnico_id, cliente_id, local_id, equipamento_id, intervencao_id, contrato_id, **cobertura** (incluida/extra/null — liga uma visita manual ao saldo do contrato), estado (planeado/confirmado/em curso/concluído/cancelado)
- `tecnico_disponibilidade` — horários, férias, ausências → deteção de conflitos e capacidade

### Intervenções e relatórios

- `intervencoes` — id, equipamento_id, contrato_id, evento_agenda_id, tipo (preventiva/corretiva/instalação), estado, tecnico_id, data_inicio, data_fim, descricao_problema, trabalho_realizado, observacoes
- `checklists` / `checklist_itens`, `medicoes`
- `relatorios` — id, intervencao_id, numero, data, pdf_path, estado, enviado_em, enviado_para
- `anexos` — id, entidade_tipo, entidade_id, nome_ficheiro, storage_key, mime, tamanho, criado_por, criado_em *(polimórfico)*
- `auditoria` — registo de ações relevantes

---

## 5. Integração com o ERP (SQL)

Padrão obrigatório:

1. **Utilizador SQL read-only**, com acesso apenas a **views dedicadas** (nunca tabelas brutas do ERP)
2. **Sincronização agendada** (cron/serviço) que lê das views e faz **upsert** nas tabelas da aplicação, correlacionando por `id_erp`
3. A aplicação trabalha **sempre contra a própria BD** — o ERP nunca está no caminho crítico de um pedido do utilizador
4. Para volumes maiores: passar de sync completo a incremental (por data de modificação)

**Escrita no ERP: evitar no MVP.** Se vier a ser necessária (ex.: lançar horas/consumíveis para faturação), usar apenas mecanismo suportado pelo ERP (stored procedures, API, tabela de interface). **NUNCA INSERT/UPDATE diretos nas tabelas do ERP.**

---

## 6. Regras de negócio chave

### Contratos
- **Modelo de saldo de visitas:** cada contrato define um **nº fixo de visitas incluídas** (`visitas_incluidas`) — total pela **vida do contrato** (não por ano, não renova). Vazio (null) = sem controlo de saldo.
- **Ativar** um contrato apenas o põe em estado ativo — **NÃO gera visitas automaticamente** (exige ≥1 equipamento). As visitas são agendadas **à mão** na agenda.
- Ao agendar uma visita, liga-se a um contrato e marca-se como **incluída** (gasta uma do saldo) ou **extra** (faturável à parte), via `eventos_agenda.cobertura`.
- **Saldo** (incluídas / usadas / restantes) na **ficha do contrato**: `usadas` = eventos do contrato com `cobertura='incluida'` e estado ≠ cancelado; `restantes` = `visitas_incluidas` − usadas (nunca negativo; se excedido, mostra aviso). Determinístico — conta eventos pela cobertura, não por periodicidade.
- `intervencoes.contrato_id` determina se o trabalho está **incluído no contrato** ou é **faturável à parte** — essencial para faturação correta
- Alertas: contratos a expirar (dentro de `periodo_aviso_dias`), SLA em risco
- Relatórios de gestão: cumprimento de SLA, renovações próximas, equipamentos sem visitas recentes; gráfico mensal de visitas de contrato (planeadas vs. realizadas, conta eventos com cobertura)

### Agenda
- A agenda é em larga medida uma **projeção** das intervenções e visitas planeadas + eventos próprios (reuniões, ausências)
- Drag-and-drop para reagendar com **deteção de conflitos** (técnico sobreposto, em ausência). **Não há horário de cobertura nem dias úteis**: os técnicos não têm horário fixo — qualquer hora, qualquer dia, serviços que atravessam dias (decisão da equipa, 2026-08-29)
- Visitas agendadas à mão; ao criar, pode ligar-se a um contrato e marcar a cobertura (incluída/extra) → alimenta o saldo do contrato
- Conversão evento → intervenção quando o técnico inicia a visita, mantendo `evento_agenda_id` para rastreio
- **Regra de ouro:** intervenção e evento de agenda partilham os mesmos factos (técnico, datas, equipamento). Há **uma única fonte de verdade** (ver decisão pendente #4) — nunca duplicar/dessincronizar
- Notificações ao técnico (e opcionalmente ao cliente); feed iCal para calendários externos

### Domínio UPS
- `proxima_troca_baterias` (ciclo típico 3–5 anos): campo calculável, usado para alertas e sugestão de visitas — item de manutenção mais valioso de antecipar
- Checklists por tipo de equipamento (ex.: preventiva UPS: leituras entrada/saída, carga, teste de autonomia, estado das baterias)
- QR code por equipamento → abre a ficha e cria intervenção em campo

### Anexos e fotos
- Thumbnails gerados no upload (listagens e relatórios usam versões reduzidas)
- Upload direto do telemóvel durante a intervenção, com compressão no cliente
- Guardar timestamp e, com consentimento, geolocalização (prova de presença / disputas de SLA)
- Retenção e backup definidos desde o início

### Relatórios
- Fluxo: intervenção concluída → HTML a partir de template (logótipo, cliente, equipamento, trabalho, fotos, próximas ações) → **conversão HTML→PDF em job assíncrono** → validação → envio por email e/ou link no portal → registar `enviado_em` / `enviado_para`
- Numeração sequencial (ex.: `2026/0042`)
- **Template versionado** — relatórios antigos renderizam exatamente como foram emitidos
- Assinatura do cliente no local (captura no ecrã) como prova de conclusão

---

## 7. Autenticação, RBAC e portal de cliente

| Papel | Acesso |
|---|---|
| `admin` | Tudo |
| `tecnico` | As suas intervenções, agenda e relatórios |
| `cliente` | Só leitura do que lhe pertence, filtrado por `cliente_id` |

- Isolamento por cliente imposto **na camada de consulta** (middleware/scope global), verificado em testes
- Clientes: login local com convite por email no início; SSO interno fica para fase posterior

---

## 8. Mobile / campo

- **PWA responsiva** — instalável, sem app stores
- Checklists, captura de fotos e assinatura têm de funcionar bem em ecrã pequeno
- **MVP assume conetividade.** Offline-first só se o feedback dos técnicos o justificar (acrescenta complexidade de sync)

---

## 9. Roadmap por fases

> Entrega incremental: cada fase em produção e em uso antes de avançar.

### Fase 0 — Fundações (1–2 semanas)
- Esqueleto da aplicação, BD, object storage, autenticação e papéis, reverse proxy/TLS
- Utilizador SQL read-only no ERP e primeiro sync de clientes

### Fase 1 — MVP utilizável (4–5 semanas)
- Registo de equipamentos (com campos UPS), intervenções, upload de fotos, checklist simples
- Geração de PDF e envio ao cliente → já demonstra valor

### Fase 2 — Contratos + Agenda (5–7 semanas)
- Módulo de contratos (âmbito, nº de visitas incluídas, SLA, faturação, renovações)
- Módulo de agenda (vistas, drag-and-drop, conflitos)
- **Agendamento manual de visitas ligadas ao contrato, com saldo (incluídas/usadas/restantes)** — aqui a ferramenta passa a governar a operação

### Fase 3 — Portal e automação (4–6 semanas)
- Portal do cliente, alertas proativos (renovações, SLA, baterias)
- Dashboards de gestão, polimento PWA, offline se justificado

---

## 10. Decisões pendentes (fechar ANTES de gerar código)

> Claude Code: se alguma destas não estiver respondida, perguntar antes de assumir.

1. **Backend:** Laravel+Filament, Django, ou NestJS+React?
2. **Origem dos dados:** clientes/contratos vêm do ERP, ou parte nasce na aplicação? (define o âmbito do sync)
3. **Escrita no ERP:** há necessidade real (ex.: faturação de intervenções)? Por que mecanismo suportado?
4. **Fonte de verdade agenda↔intervenção:** a agenda gera intervenções, ou as intervenções geram eventos?
5. **Offline em campo:** requisito ou nice-to-have?

---

## 11. Segurança e operação (checklist permanente)

- [ ] TLS obrigatório em todos os ambientes
- [ ] Utilizador SQL do ERP estritamente read-only, limitado a views, credenciais em secret (nunca em código/repositório)
- [ ] Backups: BD da aplicação + object storage + configuração
- [ ] Auditoria de emissões/envios de relatórios e mudanças de estado
- [ ] Isolamento por cliente coberto por **testes automatizados**
- [ ] Ambientes separados dev/staging/produção (o sync toca no ERP de produção)

---

## 12. Convenções de desenvolvimento

- Nomes de domínio (tabelas, entidades, campos) em **português**, snake_case
- Migrações versionadas desde o primeiro commit
- Nenhum blob na BD; nenhum acesso direto às tabelas do ERP
- Operações pesadas (PDF, email) sempre em **jobs assíncronos**
- Cada feature deve responder: "onde encaixa na cadeia Contrato → Visita → Agenda → Intervenção → Relatório → Cliente?"