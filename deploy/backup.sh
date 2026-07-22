#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Backup diário do Nexus Infra — base de dados + storage (fotos/PDFs).
# Corre NO SERVIDOR (via cron). Lê as credenciais do .env; não as guarda.
# Guarda em /var/backups/nexus-ops com rotação (RETENCAO_DIAS).
#
# Protege contra: BD apagada por engano, migração que corre mal, corrupção,
# erro humano. NÃO é off-site — para o catastrófico (disco morre, servidor
# arde) é preciso copiar estes ficheiros para fora do servidor.
# ---------------------------------------------------------------------------
set -euo pipefail

APP="/var/www/nexus-ops"
DEST="/var/backups/nexus-ops"
RETENCAO_DIAS=14
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$DEST"

# Lê uma variável do .env (última ocorrência vence; tira aspas se houver).
env_val() { grep -E "^$1=" "$APP/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_DATABASE="$(env_val DB_DATABASE)"
DB_USERNAME="$(env_val DB_USERNAME)"
DB_HOST="$(env_val DB_HOST)"
DB_PORT="$(env_val DB_PORT)"
DB_PASSWORD="$(env_val DB_PASSWORD)"

# 1. Base de dados — formato custom do pg_dump (comprimido, restaurável com pg_restore).
PGPASSWORD="$DB_PASSWORD" pg_dump -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" \
    -U "$DB_USERNAME" -Fc "$DB_DATABASE" > "$DEST/db-$STAMP.dump"

# 2. Storage — fotos e PDFs dos relatórios.
tar -czf "$DEST/storage-$STAMP.tar.gz" -C "$APP" storage/app

# 3. Rotação — apaga backups com mais de RETENCAO_DIAS dias.
find "$DEST" -maxdepth 1 -name 'db-*.dump' -mtime +"$RETENCAO_DIAS" -delete
find "$DEST" -maxdepth 1 -name 'storage-*.tar.gz' -mtime +"$RETENCAO_DIAS" -delete

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup OK: db-$STAMP.dump ($(du -h "$DEST/db-$STAMP.dump" | cut -f1)), storage-$STAMP.tar.gz ($(du -h "$DEST/storage-$STAMP.tar.gz" | cut -f1)); retenção ${RETENCAO_DIAS}d em $DEST"
