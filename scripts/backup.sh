#!/usr/bin/env bash
# Backup do Nexus Infra (18.ª análise de segurança → melhoria #2).
# O QUÊ: base de dados (pg_dump), anexos/assinaturas (storage/app) e .env.
# ONDE: /var/backups/nexus-ops (local) e, se BACKUP_OFFSITE_DIR estiver definido,
#       uma cópia para lá (montagem de NAS, segundo disco, rclone mount…).
# QUANDO: cron diário (ver scripts/RESTAURO.md para a instalação e o ensaio de restauro).
# MARCADOR: no fim de um backup BEM SUCEDIDO toca storage/app/backups/.ultimo-backup —
#           a vigia da app (ALERTAS_BACKUP_VIGIA=true) alerta se ele faltar/envelhecer.
set -euo pipefail

# Ficheiros do backup só para o dono (root): o dump da BD tem hashes de password, códigos
# MFA e todos os dados dos clientes — nunca world-readable (19.ª revisão de segurança).
umask 077

APP_DIR="${APP_DIR:-/var/www/nexus-ops}"
DEST="${BACKUP_DIR:-/var/backups/nexus-ops}"
OFFSITE="${BACKUP_OFFSITE_DIR:-}"          # opcional: segundo destino (NAS/disco/rclone)
RETENCAO_DIAS="${BACKUP_RETENCAO_DIAS:-14}" # apaga backups locais mais velhos que isto
CARIMBO="$(date +%Y%m%d-%H%M%S)"
PASTA="${DEST}/${CARIMBO}"

# Credenciais da BD lidas do .env da app (nunca hardcoded aqui).
env_val() { grep -E "^$1=" "${APP_DIR}/.env" | head -1 | cut -d= -f2- | tr -d '"'; }
DB_HOST="$(env_val DB_HOST)"; DB_PORT="$(env_val DB_PORT)"; DB_NAME="$(env_val DB_DATABASE)"
DB_USER="$(env_val DB_USERNAME)"; export PGPASSWORD="$(env_val DB_PASSWORD)"

install -d -m 700 "${DEST}" "${PASTA}"

# 1) Base de dados — formato custom (permite pg_restore seletivo), comprimido.
pg_dump -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "${DB_USER}" -Fc \
    -f "${PASTA}/bd.dump" "${DB_NAME}"

# 2) Anexos, assinaturas, PDFs — tudo o que vive em storage/app (exclui caches e o marcador).
tar -czf "${PASTA}/storage-app.tar.gz" -C "${APP_DIR}/storage" \
    --exclude='app/backups' --exclude='app/livewire-tmp' app

# 3) Configuração (o .env tem as credenciais — o destino do backup TEM de ser restrito a root).
cp "${APP_DIR}/.env" "${PASTA}/env"
chmod 600 "${PASTA}/env"

# 4) Verificação mínima: o dump abre e tem tabelas; o tar não está vazio.
pg_restore --list "${PASTA}/bd.dump" > /dev/null
tar -tzf "${PASTA}/storage-app.tar.gz" > /dev/null

# 5) Cópia offsite (se configurada) — a única defesa real contra ransomware na VM.
if [ -n "${OFFSITE}" ]; then
    install -d -m 700 "${OFFSITE}/${CARIMBO}"
    cp "${PASTA}/bd.dump" "${PASTA}/storage-app.tar.gz" "${PASTA}/env" "${OFFSITE}/${CARIMBO}/"
fi

# 6) Retenção local.
find "${DEST}" -maxdepth 1 -mindepth 1 -type d -mtime "+${RETENCAO_DIAS}" -exec rm -rf {} +

# 7) Marcador de sucesso (só chega aqui se TUDO acima passou) — alimenta a vigia da app.
mkdir -p "${APP_DIR}/storage/app/backups"
touch "${APP_DIR}/storage/app/backups/.ultimo-backup"
chown www-data:www-data "${APP_DIR}/storage/app/backups/.ultimo-backup" 2>/dev/null || true

echo "Backup OK: ${PASTA} ($(du -sh "${PASTA}" | cut -f1))"
