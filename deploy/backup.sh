#!/usr/bin/env bash
# Backup harian database + config ke luar VPS.
# Cron root: 23 2 * * * /var/www/crm/deploy/backup.sh >> /var/log/crm-backup.log 2>&1
#
# Kebutuhan:
#   /root/.crm-backup.cnf (chmod 600) berisi:
#     [client]
#     user=crm_backup
#     password=...
#   rclone sudah dikonfigurasi dengan remote tujuan (Backblaze B2, S3, Google Drive, dll).
set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/crm}
DB_NAME=${DB_NAME:-crm}
LOCAL_DIR=${LOCAL_DIR:-/var/backups/crm}
REMOTE=${REMOTE:-backup:crm}      # nama remote rclone, wajib di luar VPS
KEEP_DAYS=${KEEP_DAYS:-7}
STAMP=$(date -u +%Y%m%d-%H%M%S)

mkdir -p "$LOCAL_DIR"
chmod 700 "$LOCAL_DIR"

DUMP="$LOCAL_DIR/db-$STAMP.sql.gz"
mysqldump --defaults-extra-file=/root/.crm-backup.cnf --single-transaction --routines --no-tablespaces "$DB_NAME" | gzip -9 > "$DUMP"

# config.php berisi app_key. Tanpa app_key, secret 2FA dan webhook tidak bisa dibaca.
CONF="$LOCAL_DIR/config-$STAMP.tar.gz"
tar -czf "$CONF" -C "$APP_DIR" config/config.php

# Cek dump tidak kosong dan bisa dibaca.
gzip -t "$DUMP"
if [ "$(gzip -dc "$DUMP" | head -c 100000 | grep -c 'CREATE TABLE')" -lt 1 ]; then
  echo "$(date -u) dump terlihat kosong: $DUMP" >&2
  exit 1
fi

rclone copy "$DUMP" "$REMOTE/"
rclone copy "$CONF" "$REMOTE/"

find "$LOCAL_DIR" -type f -mtime +"$KEEP_DAYS" -delete
rclone delete "$REMOTE/" --min-age "${KEEP_DAYS}d" || true

echo "$(date -u) backup ok: $DUMP"
