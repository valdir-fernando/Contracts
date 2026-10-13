#!/usr/bin/env bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
backup_archive="$(realpath "${1:?Informe o arquivo de backup}")"
backup_work="$(mktemp -d)"
restore_container="contracts-backup-verify-$(date -u +%Y%m%d%H%M%S)-$RANDOM"
container_started=false
cleanup() {
    if [ "$container_started" = true ]; then
        docker rm -f "$restore_container" >/dev/null 2>&1 || true
    fi
    rm -rf "$backup_work"
}
trap cleanup EXIT
# Reject traversal and unsafe links before extracting an archive supplied by an operator.
python3 - "$backup_archive" "$backup_work" <<'PY'
import sys, tarfile
with tarfile.open(sys.argv[1]) as archive:
    archive.extractall(sys.argv[2], filter='data')
PY
(cd "$backup_work" && sha256sum -c SHA256SUMS)
# SQL is executable input. Never restore it as root on the application's server.
# No network, host mounts or persistent volumes are exposed to this disposable MySQL.
python3 - "$backup_work/mysql.env" <<'PYENV'
import secrets, sys
with open(sys.argv[1], 'w') as environment:
    environment.write('MYSQL_ROOT_PASSWORD=' + secrets.token_hex(32) + '\n')
    environment.write('MYSQL_DATABASE=contracts_verification\n')
PYENV
container_started=true
docker run --detach --name "$restore_container" --network none \
    --tmpfs /var/lib/mysql:rw,noexec,nosuid,size=1g \
    --env-file "$backup_work/mysql.env" mysql:8.4 >/dev/null
ready=false
for attempt in $(seq 1 60); do
    if docker exec "$restore_container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root -D "$MYSQL_DATABASE" -e "SELECT 1"' >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
if [ "$ready" != true ]; then
    printf 'MySQL descartável não ficou disponível.\n' >&2
    exit 1
fi
docker exec -i "$restore_container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root "$MYSQL_DATABASE"' < "$backup_work/database.sql"
docker exec "$restore_container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root "$MYSQL_DATABASE" -e "SELECT COUNT(*) AS contracts FROM contracts; SELECT COUNT(*) AS users FROM users; SELECT COUNT(*) AS history FROM contract_history_records; SELECT COUNT(*) AS audits FROM contract_audits;"'
# Compare every restored INSERT against the snapshot, including audit and history values.
docker exec "$restore_container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --no-create-info --skip-comments --no-tablespaces "$MYSQL_DATABASE"' > "$backup_work/restored-data.sql"
awk '/^INSERT INTO /' "$backup_work/database.sql" > "$backup_work/source-inserts.sql"
awk '/^INSERT INTO /' "$backup_work/restored-data.sql" > "$backup_work/restored-inserts.sql"
diff -u "$backup_work/source-inserts.sql" "$backup_work/restored-inserts.sql"
mkdir "$backup_work/restored-storage"
python3 - "$backup_work/storage.tar.gz" "$backup_work/restored-storage" <<'PY'
import sys, tarfile
with tarfile.open(sys.argv[1]) as archive:
    archive.extractall(sys.argv[2], filter='data')
PY
[ -d "$backup_work/restored-storage/storage" ]
[ -s "$backup_work/environment.env" ]
printf 'Backup verificado em servidor MySQL descartável e sem rede; storage e configuração recuperados e checksums válidos.\n'
