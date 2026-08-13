# Backups do Nexus Infra — instalação, restauro e ensaio

> Par do `scripts/backup.sh`. O backup cobre: **base de dados** (pg_dump), **anexos e
> assinaturas** (`storage/app` — são prova contratual) e **.env**. A vigia na app
> (`ALERTAS_BACKUP_VIGIA=true`) dispara um alerta ALTA se o backup deixar de correr.

## 1. Instalação no servidor (uma vez, como root)

```bash
install -d -m 700 /var/backups/nexus-ops
install -m 700 /var/www/nexus-ops/scripts/backup.sh /usr/local/sbin/backup-nexus.sh

# Cron diário às 02h30 (root — o destino é restrito a root porque inclui o .env):
echo '30 2 * * * root /usr/local/sbin/backup-nexus.sh >> /var/log/backup-nexus.log 2>&1' \
  > /etc/cron.d/backup-nexus

# Primeira corrida manual (valida credenciais e permissões):
/usr/local/sbin/backup-nexus.sh
```

Depois da primeira corrida com sucesso, ativar a vigia na app: acrescentar
`ALERTAS_BACKUP_VIGIA=true` ao `.env` de produção e correr `php artisan optimize`.

**Offsite (fortemente recomendado):** definir `BACKUP_OFFSITE_DIR` no ambiente do cron
(ex.: montagem do NAS). Um atacante com acesso ao servidor leva a app **e** o backup
local — a cópia fora da máquina é a única defesa real contra ransomware.

## 2. Restauro (desastre real ou ensaio)

```bash
# Escolher o backup (mais recente):
PASTA=$(ls -1d /var/backups/nexus-ops/*/ | tail -1)

# 1) Base de dados (para uma BD vazia ou de ensaio):
createdb -U postgres nexus_restauro
pg_restore -U postgres -d nexus_restauro --no-owner "${PASTA}/bd.dump"

# 2) Storage (anexos/assinaturas):
tar -xzf "${PASTA}/storage-app.tar.gz" -C /var/www/nexus-ops/storage/

# 3) Configuração (só em desastre total — confirmar antes de sobrepor):
# cp "${PASTA}/env" /var/www/nexus-ops/.env && php artisan optimize
```

## 3. Ensaio de restauro (fazer JÁ na instalação e depois a cada trimestre)

Um backup que nunca foi restaurado não é um backup — é uma esperança.

- [ ] `pg_restore` do dump mais recente para uma BD `nexus_restauro` (comando acima)
- [ ] `psql -d nexus_restauro -c "select count(*) from equipamentos;"` — número plausível (~17k)?
- [ ] `psql -d nexus_restauro -c "select count(*) from relatorios;"` — bate com a app?
- [ ] Extrair o tar para uma pasta temporária e abrir 2–3 PDFs/fotos/assinaturas
- [ ] Registar a data do ensaio aqui em baixo
- [ ] `dropdb nexus_restauro` no fim

| Data do ensaio | Quem | Resultado |
|---|---|---|
| _(por preencher)_ | | |
