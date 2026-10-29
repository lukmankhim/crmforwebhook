-- Fase 3: integrasi pihak ketiga per tenant (opsional). Tenant tanpa baris integrations tidak disinkron.

CREATE TABLE integrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  provider ENUM('ga4','gsc','gads','meta') NOT NULL,
  account_ref VARCHAR(255) NOT NULL,
  credential_ref VARCHAR(64) NOT NULL,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  last_sync_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  consecutive_failures INT NOT NULL DEFAULT 0,
  sync_requested_at DATETIME NULL,
  backfilled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_integration (tenant_id, provider),
  CONSTRAINT fk_integrations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sync_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL,
  date_from DATE NULL,
  date_to DATE NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  `rows` INT NOT NULL DEFAULT 0,
  error VARCHAR(500) NULL,
  KEY ix_sync_runs_tenant (tenant_id, provider, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GA4 harian per source/medium/campaign/landing page.
-- dim_hash = sha1 dari dimensi, supaya kunci unik tidak melewati batas panjang index.
CREATE TABLE site_daily (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  source VARCHAR(150) NOT NULL DEFAULT '',
  medium VARCHAR(100) NOT NULL DEFAULT '',
  campaign VARCHAR(200) NOT NULL DEFAULT '',
  landing_page VARCHAR(1000) NOT NULL DEFAULT '',
  dim_hash CHAR(40) NOT NULL,
  sessions INT UNSIGNED NOT NULL DEFAULT 0,
  users INT UNSIGNED NOT NULL DEFAULT 0,
  conversions DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_site_daily (tenant_id, date, dim_hash),
  KEY ix_site_daily_date (tenant_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Search Console harian per halaman + query. Dipisah dari site_daily karena dimensinya beda
-- (GSC tidak punya source/medium, GA4 tidak punya query).
CREATE TABLE search_daily (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  page VARCHAR(1000) NOT NULL DEFAULT '',
  query VARCHAR(500) NOT NULL DEFAULT '',
  dim_hash CHAR(40) NOT NULL,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  position DECIMAL(8,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_search_daily (tenant_id, date, dim_hash),
  KEY ix_search_daily_date (tenant_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
