#!/usr/bin/env bash
# Ensaio de restauro do backup — AUTOMÁTICO e NÃO DESTRUTIVO.
# Restaura o backup mais recente para uma base de dados TEMPORÁRIA, verifica que os dados
# lá estão (contagens das tabelas que interessam), confirma que o tar do storage abre e tem
# ficheiros, e apaga a BD temporária no fim. Nunca toca na BD nem no storage de produção.
#
# Um backup que nunca foi restaurado não é um backup — é uma esperança. Correr na
# instalação e depois a cada trimestre (registar a data no RESTAURO.md).
#
#   Uso:  sudo /usr/local/sbin/ensaio-restauro-nexus.sh [pasta-do-backup]
set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/nexus-ops}"
DEST="${BACKUP_DIR:-/var/backups/nexus-ops}"
BD_ENSAIO="${BD_ENSAIO:-nexus_ensaio_restauro}"
PASTA="${1:-$(ls -1d "${DEST}"/*/ 2>/dev/null | tail -1)}"

falhas=0
ok()    { echo "  [ OK ]  $1"; }
falha() { echo "  [FALHA] $1"; falhas=$((falhas + 1)); }

echo "== Ensaio de restauro =="
if [ -z "${PASTA}" ] || [ ! -d "${PASTA}" ]; then
    echo "  [FALHA] Não há backups em ${DEST}. O cron alguma vez correu?"
    exit 1
fi
echo "Backup: ${PASTA}"
echo "Idade:  $(( ( $(date +%s) - $(stat -c %Y "${PASTA}") ) / 3600 )) horas"
echo

# Credenciais do .env da app (as mesmas que o backup usou).
env_val() { grep -E "^$1=" "${APP_DIR}/.env" | head -1 | cut -d= -f2- | tr -d '"'; }
DB_HOST="$(env_val DB_HOST)"; DB_PORT="$(env_val DB_PORT)"
DB_USER="$(env_val DB_USERNAME)"; export PGPASSWORD="$(env_val DB_PASSWORD)"
PSQL_BASE=(-h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "${DB_USER}")

# 1) Restaurar para uma BD TEMPORÁRIA (nunca a de produção).
echo "1. Restauro para a base de dados temporária '${BD_ENSAIO}'..."
dropdb "${PSQL_BASE[@]}" --if-exists "${BD_ENSAIO}" 2>/dev/null
if createdb "${PSQL_BASE[@]}" "${BD_ENSAIO}" 2>/dev/null; then
    ok "base de dados temporária criada"
else
    falha "não foi possível criar a base de dados temporária"; exit 1
fi
# O pg_restore avisa sobre owners/extensões — o que conta é o conteúdo, verificado a seguir.
pg_restore "${PSQL_BASE[@]}" -d "${BD_ENSAIO}" --no-owner --no-privileges "${PASTA}/bd.dump" > /dev/null 2>&1
ok "dump restaurado"

# 2) Os dados estão lá? (contagens das tabelas que doem se faltarem)
echo
echo "2. Verificação do conteúdo:"
conta() {
    local tabela="$1" minimo="$2"
    local n
    n=$(psql "${PSQL_BASE[@]}" -d "${BD_ENSAIO}" -tAc "select count(*) from ${tabela}" 2>/dev/null)
    if [ -z "${n}" ]; then
        falha "${tabela}: tabela ausente no backup"
    elif [ "${n}" -lt "${minimo}" ]; then
        falha "${tabela}: só ${n} registos (esperado >= ${minimo})"
    else
        ok "${tabela}: ${n} registos"
    fi
}
conta clientes 100
conta equipamentos 1000
conta relatorios 0
conta fichas_medicao 0
conta dossiers 1000
conta auditoria 0

# 3) O tar do storage abre e tem os anexos/assinaturas?
echo
echo "3. Verificação do storage (anexos e assinaturas):"
if tar -tzf "${PASTA}/storage-app.tar.gz" > /tmp/ensaio-tar.txt 2>/dev/null; then
    total=$(wc -l < /tmp/ensaio-tar.txt)
    anexos=$(grep -c 'app/anexos/' /tmp/ensaio-tar.txt || true)
    assin=$(grep -c 'app/assinaturas/' /tmp/ensaio-tar.txt || true)
    ok "arquivo íntegro (${total} entradas)"
    [ "${anexos}" -gt 0 ] && ok "anexos: ${anexos} ficheiros" || falha "sem anexos no arquivo"
    [ "${assin}" -gt 0 ] && ok "assinaturas: ${assin} ficheiros" || echo "  [ -- ]  sem assinaturas (normal se ainda não houver fichas assinadas)"
else
    falha "o arquivo do storage não abre (corrompido?)"
fi
rm -f /tmp/ensaio-tar.txt

# 4) O .env foi guardado (e com permissões restritas)?
echo
echo "4. Configuração:"
if [ -f "${PASTA}/env" ]; then
    perm=$(stat -c %a "${PASTA}/env")
    ok ".env presente"
    [ "${perm}" = "600" ] && ok "permissões 600 (só root)" || falha ".env com permissões ${perm} (deviam ser 600)"
else
    falha ".env não está no backup"
fi

# 5) Limpeza — a BD temporária nunca fica para trás.
echo
dropdb "${PSQL_BASE[@]}" --if-exists "${BD_ENSAIO}" 2>/dev/null && echo "Base de dados temporária apagada."

echo
if [ "${falhas}" -eq 0 ]; then
    echo "== ENSAIO PASSOU — o backup restaura e tem os dados. =="
    echo "   Registe a data no scripts/RESTAURO.md."
    exit 0
fi
echo "== ENSAIO FALHOU (${falhas} problema(s) acima) — o backup NÃO é de confiança. =="
exit 1
