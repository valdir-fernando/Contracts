#!/usr/bin/env bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
backup_archive="$(realpath "${1:?Informe o arquivo de backup}")"
backup_work="$(mktemp -d)"
restore_database="contracts_verify_$(date -u +%Y%m%d%H%M%S)_$RANDOM"
cleanup() {
    docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root -e "DROP DATABASE IF EXISTS $1"' sh "$restore_database" >/dev/null 2>&1 || true
    rm -rf "$backup_work"
}
trap cleanup EXIT
# Reject traversal and links before extracting an archive supplied by an operator.
python3 - "$backup_archive" "$backup_work" <<'PY'
import sys, tarfile
with tarfile.open(sys.argv[1]) as archive:
    archive.extractall(sys.argv[2], filter='data')
PY
(cd "$backup_work" && sha256sum -c SHA256SUMS)
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root -e "CREATE DATABASE $1 CHARACTER SET utf8mb4"' sh "$restore_database"
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root "$1"' sh "$restore_database" < "$backup_work/database.sql"
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root "$1" -e "SELECT COUNT(*) AS contracts FROM contracts; SELECT COUNT(*) AS users FROM users; SELECT COUNT(*) AS history FROM contract_history_records; SELECT COUNT(*) AS audits FROM contract_audits;"' sh "$restore_database"
# Compare every restored INSERT against the snapshot, including audit and history values.
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --no-create-info --skip-comments --no-tablespaces "$1"' sh "$restore_database" > "$backup_work/restored-data.sql"
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
printf 'Banco restaurado em schema isolado; storage e configuração recuperados e checksums válidos.\n'
