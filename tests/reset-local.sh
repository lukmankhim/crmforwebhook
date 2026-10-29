#!/usr/bin/env bash
# HANYA untuk DB lokal/testing: hapus semua tabel, migrasi ulang, buat superadmin.
# Pakai: SUPER_PASS=... tests/reset-local.sh
set -euo pipefail
cd "$(dirname "$0")/.."
DB=${DB:-crm}
if [ "${CRM_ALLOW_RESET:-}" != "yes" ]; then
  echo "Set CRM_ALLOW_RESET=yes untuk konfirmasi. Semua data di database '$DB' akan dihapus." >&2
  exit 1
fi
mysql -uroot -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
rm -f storage/sessions/sess_* storage/logs/notify.log storage/cache/google-token-*
php bin/migrate.php
CRM_SUPERADMIN_PASSWORD="$SUPER_PASS" php bin/create-superadmin.php "${SUPER_USER:-admin}" "Superadmin"
