#!/usr/bin/env bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
backup_root="${1:-backups}"
mkdir -p "$backup_root"
backup_root="$(realpath "$backup_root")"
backup_work="$(mktemp -d "$backup_root/.contracts-backup.XXXXXX")"
trap 'rm -rf "$backup_work"' EXIT
# InnoDB snapshot; no passwords in arguments or console output.
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u "$MYSQL_USER" --single-transaction --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' > "$backup_work/database.sql"
docker compose exec -T app tar -czf - -C /var/www/html storage > "$backup_work/storage.tar.gz"
cp .env "$backup_work/environment.env"
(cd "$backup_work" && sha256sum database.sql storage.tar.gz environment.env > SHA256SUMS)
backup_file="$backup_root/contracts-$(date -u +%Y%m%dT%H%M%SZ)-$RANDOM.tar.gz"
tar -czf "$backup_file" -C "$backup_work" .
chmod 600 "$backup_file"
printf '%s\n' "$backup_file"
# Only archives created by this script are subject to the configured retention.
find "$backup_root" -maxdepth 1 -type f -name 'contracts-*.tar.gz' -mtime "+${BACKUP_RETENTION_DAYS:-30}" -delete
