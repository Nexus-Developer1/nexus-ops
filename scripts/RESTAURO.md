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

O ensaio está **automatizado** e é **não destrutivo**: restaura o backup mais recente para
uma base de dados temporária, confirma que os dados lá estão (contagens), verifica que o
arquivo do storage abre e traz anexos/assinaturas, e apaga a base temporária no fim. Nunca
toca na base de dados nem no storage de produção.

```bash
# Instalar (uma vez, como root):
install -m 700 /var/www/nexus-ops/scripts/ensaio-restauro.sh /usr/local/sbin/ensaio-restauro-nexus.sh

# Correr o ensaio (usa o backup mais recente; ou passar a pasta como argumento):
/usr/local/sbin/ensaio-restauro-nexus.sh
```

Termina com **ENSAIO PASSOU** (código 0) ou **ENSAIO FALHOU** com a lista dos problemas.
Registar a data em baixo a cada corrida.

| Data do ensaio | Quem | Resultado |
|---|---|---|
| 2026-08-14 | Davide | **PASSOU** — 3061 clientes, 17818 equipamentos, 200695 dossiês, 4 relatórios, 2 fichas; arquivo com 23 anexos, 5 assinaturas e 4 PDFs; `.env` a 600. Próximo ensaio: novembro/2026. |

## 4. Nota de manutenção do servidor

O **Node.js** está na versão 18 e o Vite (usado no `npm run build` de cada deploy) já avisa
que exige 20.19+. Compila à mesma por agora, mas uma atualização futura do Vite pode
recusar. Ir direto ao **22 (LTS)** — o 20 sai de suporte em abril de 2026, seria trocar um
fim-de-vida por outro. Quando houver janela (como root):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -y nodejs
node -v   # deve dizer v22.x
cd /var/www/nexus-ops && sudo -u www-data env HOME=/var/www npm_config_cache=/tmp/npm-cache npm run build
```
