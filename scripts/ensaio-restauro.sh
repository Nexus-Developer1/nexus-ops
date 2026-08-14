#!/usr/bin/env bash
# Ensaio de restauro do backup — AUTOMÁTICO e NÃO DESTRUTIVO.
# Restaura o backup mais recente para uma base de dados TEMPORÁRIA, verifica que os dados
# lá estão (contagens das tabelas que interessam), confirma que o tar do storage abre e tem
# ficheiros, e apaga tudo o que criou no fim. Nunca toca na BD nem no storage de produção.
#
# Um backup que nunca foi restaurado não é um backup — é uma esperança. Correr na
# instalação e depois a cada trimestre (registar a data no RESTAURO.md).
#
# CORRER COMO ROOT: o backup é 700/root (o dump tem hashes de password e todos os dados) e
# a criação da BD de ensaio usa o superutilizador local `postgres` — o utilizador da APP não
# tem (nem deve ter) permissão CREATEDB.
#
#   Uso:  sudo /usr/local/sbin/ensaio-restauro-nexus.sh [pasta-do-backup]
set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/nexus-ops}"
DEST="${BACKUP_DIR:-/var/backups/nexus-ops}"
BD_ENSAIO="${BD_ENSAIO:-nexus_ensaio_restauro}"
PASTA="${1:-$(ls -1d "${DEST}"/*/ 2>/dev/null | tail -1)}"
TMP=""
# As operações de base de dados correm como o superutilizador local `postgres` (peer): o
# utilizador da app não tem CREATEDB, e ainda bem — a app não deve poder criar bases.
# (Definido aqui em cima porque a limpeza do trap também o usa.)
PG="sudo -n -u postgres"

falhas=0
ok()    { echo "  [ OK ]  $1"; }
falha() { echo "  [FALHA] $1"; falhas=$((falhas + 1)); }

# Limpeza garantida (BD temporária + cópia temporária do dump), aconteça o que acontecer.
limpar() {
    [ -n "${TMP}" ] && rm -rf "${TMP}"
    ${PG} dropdb --if-exists "${BD_ENSAIO}" > /dev/null 2>&1
}
trap limpar EXIT

echo "== Ensaio de restauro =="
if [ -z "${PASTA}" ] || [ ! -d "${PASTA}" ]; then
    echo "  [FALHA] Não há backups em ${DEST}. O cron alguma vez correu?"
    exit 1
fi
if [ "$(id -u)" -ne 0 ]; then
    echo "  [FALHA] Correr como root (o backup é restrito a root)."
    exit 1
fi
echo "Backup: ${PASTA}"
echo "Idade:  $(( ( $(date +%s) - $(stat -c %Y "${PASTA}") ) / 3600 )) horas"

if ! ${PG} psql -tAc 'select 1' > /dev/null 2>&1; then
    echo "  [FALHA] Não foi possível usar o superutilizador local 'postgres'."
    echo "          (o ensaio precisa dele para criar a base de dados temporária)"
    exit 1
fi
echo

# O backup é 700/root: o postgres não lhe chega. Copia-se o dump para uma pasta temporária
# que só o postgres lê (ele já tem acesso a todos os dados da BD, portanto não alarga nada).
TMP="$(mktemp -d /tmp/ensaio-nexus.XXXXXX)"
chmod 700 "${TMP}"; chown postgres:postgres "${TMP}"
cp "${PASTA}/bd.dump" "${TMP}/bd.dump"
chown postgres:postgres "${TMP}/bd.dump"; chmod 600 "${TMP}/bd.dump"

# 1) Restaurar para uma BD TEMPORÁRIA (nunca a de produção).
echo "1. Restauro para a base de dados temporária '${BD_ENSAIO}'..."
${PG} dropdb --if-exists "${BD_ENSAIO}" > /dev/null 2>&1
if erro=$(${PG} createdb "${BD_ENSAIO}" 2>&1); then
    ok "base de dados temporária criada"
else
    falha "não foi possível criar a base de dados temporária:"
    echo "          ${erro}"
    exit 1
fi
# O pg_restore avisa sobre owners/extensões — o que conta é o conteúdo, verificado a seguir.
${PG} pg_restore -d "${BD_ENSAIO}" --no-owner --no-privileges "${TMP}/bd.dump" > /dev/null 2>&1
ok "dump restaurado"

# 2) Os dados estão lá? (contagens das tabelas que doem se faltarem)
echo
echo "2. Verificação do conteúdo:"
conta() {
    local tabela="$1" minimo="$2"
    local n
    n=$(${PG} psql -d "${BD_ENSAIO}" -tAc "select count(*) from ${tabela}" 2>/dev/null | tr -d ' ')
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
if tar -tzf "${PASTA}/storage-app.tar.gz" > "${TMP}/lista.txt" 2>/dev/null; then
    total=$(wc -l < "${TMP}/lista.txt")
    anexos=$(grep -c 'app/anexos/' "${TMP}/lista.txt" || true)
    assin=$(grep -c 'app/assinaturas/' "${TMP}/lista.txt" || true)
    ok "arquivo íntegro (${total} entradas)"
    if [ "${anexos}" -gt 0 ]; then ok "anexos: ${anexos} ficheiros"; else falha "sem anexos no arquivo"; fi
    if [ "${assin}" -gt 0 ]; then ok "assinaturas: ${assin} ficheiros"; else echo "  [ -- ]  sem assinaturas (normal se ainda não houver fichas assinadas)"; fi
else
    falha "o arquivo do storage não abre (corrompido?)"
fi

# 4) O .env foi guardado (e com permissões restritas)?
echo
echo "4. Configuração:"
if [ -f "${PASTA}/env" ]; then
    perm=$(stat -c %a "${PASTA}/env")
    ok ".env presente"
    if [ "${perm}" = "600" ]; then ok "permissões 600 (só root)"; else falha ".env com permissões ${perm} (deviam ser 600)"; fi
else
    falha ".env não está no backup"
fi

echo
echo "Limpeza: base de dados temporária e cópias apagadas."
echo
if [ "${falhas}" -eq 0 ]; then
    echo "== ENSAIO PASSOU — o backup restaura e tem os dados. =="
    echo "   Registe a data no scripts/RESTAURO.md."
    exit 0
fi
echo "== ENSAIO FALHOU (${falhas} problema(s) acima) — o backup NÃO é de confiança. =="
exit 1
