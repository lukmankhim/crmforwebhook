-- Jalankan sekali sebagai root MySQL. Ganti kedua password sebelum dipakai.
-- crm_app: dipakai aplikasi, tanpa hak DDL.
-- crm_migrate: hanya untuk bin/migrate.php.
CREATE DATABASE IF NOT EXISTS crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'crm_app'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_APP';
GRANT SELECT, INSERT, UPDATE, DELETE ON crm.* TO 'crm_app'@'localhost';

CREATE USER IF NOT EXISTS 'crm_migrate'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_MIGRATE';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON crm.* TO 'crm_migrate'@'localhost';

-- Untuk backup (mysqldump --single-transaction).
CREATE USER IF NOT EXISTS 'crm_backup'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_BACKUP';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES ON crm.* TO 'crm_backup'@'localhost';

FLUSH PRIVILEGES;
