# Nexus Infra

Aplicação web de CMMS (gestão e manutenção de equipamentos) com field service e portal de cliente: gestão de ativos (UPS, geradores, PDUs, sistemas compostos), contratos de manutenção com saldo de visitas, agenda por técnico, intervenções em campo com fichas de medição e fotos, e relatórios PDF enviados ao cliente.

A cadeia central do domínio:

```
Contrato → visita agendada → Intervenção → Relatório → Cliente
```

> O guia completo do domínio, modelo de dados e regras de negócio está em [CLAUDE.md](CLAUDE.md). O histórico de alterações está em [CHANGELOG.md](CHANGELOG.md).

## Stack

- **Backend:** Laravel 12 + Livewire 3 (PHP 8.3)
- **Frontend:** Tailwind CSS + Alpine.js, FullCalendar (agenda), Chart.js (dashboards), Vite
- **BD:** PostgreSQL (JSONB para atributos de equipamento e fichas)
- **PDF:** DomPDF (relatórios gerados em job assíncrono)
- **ERP:** sync read-only do PHC (SQL Server via dblib/FreeTDS) — clientes, faturação e equipamentos; a app nunca escreve no ERP
- **Email:** Microsoft Graph (MFA, convites, envio de relatórios)

## Ambiente de desenvolvimento

Requisitos: Docker Desktop + Node.js.

```bash
# 1. Levantar os serviços (app PHP, PostgreSQL, Caddy, scheduler)
docker compose up -d

# 2. Preparar a aplicação
cp .env.example .env          # preencher as variáveis (BD, ERP_*, MS_GRAPH_*)
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

# 3. Frontend
npm install
npm run build                 # ou `npm run dev` para hot-reload
```

## Testes

```bash
docker compose exec app php artisan test
```

A suite cobre os fluxos principais (relatórios, fichas de medição, contratos, agenda, sync ERP, isolamento por cliente, MFA). Correr sempre antes de qualquer commit.

## Comandos úteis

| Comando | O quê |
|---|---|
| `php artisan erp:sincronizar-clientes` | Sync de clientes do PHC (agendado 3x/dia) |
| `php artisan erp:sincronizar-equipamentos` | Sync de equipamentos Riello do PHC |
| `php artisan erp:sincronizar-faturacao` | Sync das linhas de fatura do PHC |
| `php artisan relatorio:gerar {intervencao}` | Gerar o relatório de uma intervenção à mão |
| `php artisan mail:teste {email}` | Testar o envio de email via Graph |

## Produção

Deploy por `git pull` + `php artisan migrate --force` + `npm run build` + `php artisan optimize` no servidor (runbook interno). Produção usa caches agressivas — o `optimize` no fim do deploy é obrigatório.

## Convenções

- Domínio (tabelas, entidades, campos) em **português**, snake_case
- Dados do ERP são **read-only** na aplicação (correlação por `id_erp`)
- Anexos em object storage/filesystem — nunca blobs na BD
- Operações pesadas (PDF, email) sempre em jobs assíncronos
- Isolamento por cliente imposto na camada de dados e coberto por testes
- Atualizar o [CHANGELOG.md](CHANGELOG.md) em cada alteração
