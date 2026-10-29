-- Fase 1: fondasi. Semua waktu disimpan UTC (koneksi diset time_zone '+00:00').

CREATE TABLE tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(64) NOT NULL,
  name VARCHAR(150) NOT NULL,
  kind ENUM('own','client') NOT NULL DEFAULT 'own',
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Jakarta',
  brand_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL,
  pass_hash VARCHAR(255) NOT NULL,
  totp_secret TEXT NULL,
  totp_last_step BIGINT NULL,
  is_super TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('admin','sales','viewer') NOT NULL,
  UNIQUE KEY uq_tenant_user (tenant_id, user_id),
  KEY ix_tu_user (user_id),
  CONSTRAINT fk_tu_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_tu_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pipelines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_pipelines_tenant (tenant_id),
  CONSTRAINT fk_pipelines_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pipeline_stages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  pipeline_id INT UNSIGNED NOT NULL,
  stage_key VARCHAR(40) NOT NULL,
  label VARCHAR(80) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  kind ENUM('open','won','lost') NOT NULL DEFAULT 'open',
  UNIQUE KEY uq_stage (tenant_id, pipeline_id, stage_key),
  CONSTRAINT fk_stages_pipeline FOREIGN KEY (pipeline_id) REFERENCES pipelines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  pipeline_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  name VARCHAR(150) NULL,
  source ENUM('ctwa','lp','gads','organik','manual','import') NOT NULL DEFAULT 'manual',
  src_tag VARCHAR(150) NULL,
  ctwa_clid VARCHAR(512) NULL,
  ad_id VARCHAR(64) NULL,
  fbclid VARCHAR(512) NULL,
  gclid VARCHAR(512) NULL,
  gbraid VARCHAR(512) NULL,
  wbraid VARCHAR(512) NULL,
  ttclid VARCHAR(512) NULL,
  landing_url TEXT NULL,
  referrer TEXT NULL,
  user_agent VARCHAR(512) NULL,
  stage VARCHAR(40) NOT NULL,
  stage_at DATETIME NULL,
  qualified_at DATETIME NULL,
  deal_at DATETIME NULL,
  touched_at DATETIME NULL,
  assigned_to INT UNSIGNED NULL,
  product VARCHAR(150) NULL,
  city VARCHAR(100) NULL,
  quote_value DECIMAL(15,2) NULL,
  deal_value DECIMAL(15,2) NULL,
  lost_reason VARCHAR(255) NULL,
  next_followup DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leads_phone (tenant_id, phone),
  KEY ix_leads_stage (tenant_id, stage),
  KEY ix_leads_created (tenant_id, created_at),
  KEY ix_leads_assigned (tenant_id, assigned_to),
  CONSTRAINT fk_leads_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_leads_assigned FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_activity (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  lead_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  type VARCHAR(40) NOT NULL,
  detail TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_activity_lead (tenant_id, lead_id, created_at),
  CONSTRAINT fk_activity_lead FOREIGN KEY (lead_id) REFERENCES leads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  object VARCHAR(40) NULL,
  object_id VARCHAR(64) NULL,
  detail TEXT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_tenant (tenant_id, created_at),
  KEY ix_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  key_prefix VARCHAR(16) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  scopes VARCHAR(255) NOT NULL DEFAULT 'ingest',
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_api_keys_hash (key_hash),
  KEY ix_api_keys_tenant (tenant_id),
  CONSTRAINT fk_api_keys_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ingest_sources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  slug VARCHAR(64) NOT NULL,
  label VARCHAR(100) NOT NULL,
  adapter VARCHAR(32) NOT NULL DEFAULT 'generic',
  auth_type ENUM('bearer','query','google_key') NOT NULL DEFAULT 'bearer',
  signature_type ENUM('none','hmac_sha256') NOT NULL DEFAULT 'none',
  secret_enc TEXT NULL,
  mapping_json JSON NOT NULL,
  lead_source ENUM('ctwa','lp','gads','organik','manual','import') NOT NULL DEFAULT 'lp',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ingest_source (tenant_id, slug),
  CONSTRAINT fk_ingest_sources_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ingest_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  source VARCHAR(64) NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  external_id VARCHAR(191) NULL,
  payload_hash CHAR(64) NOT NULL,
  payload MEDIUMTEXT NULL,
  status ENUM('received','retry','processed','failed','rejected') NOT NULL DEFAULT 'received',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at DATETIME NULL,
  error VARCHAR(500) NULL,
  lead_id BIGINT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_ingest_external (tenant_id, source, external_id),
  KEY ix_ingest_queue (status, next_retry_at),
  KEY ix_ingest_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel teknis, bukan data bisnis: tanpa tenant_id.
CREATE TABLE rate_limits (
  k VARCHAR(191) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at INT UNSIGNED NOT NULL,
  KEY ix_rate_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
