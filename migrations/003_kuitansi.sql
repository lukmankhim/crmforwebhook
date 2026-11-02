-- Kuitansi pembayaran, status email, dan konversi offline (rencana di docs/KUITANSI.md).

ALTER TABLE leads
  ADD COLUMN email VARCHAR(190) NULL AFTER name,
  ADD COLUMN receipt_optin_at DATETIME NULL AFTER email,
  ADD COLUMN email_bounced_at DATETIME NULL AFTER receipt_optin_at,
  ADD COLUMN fbp VARCHAR(255) NULL AFTER fbclid,
  ADD COLUMN fbc VARCHAR(512) NULL AFTER fbp,
  ADD COLUMN lead_event_id VARCHAR(64) NULL AFTER fbc;

-- Pengaturan per tenant (kuitansi, email, Meta). Nilai rahasia disimpan terenkripsi (app_key).
CREATE TABLE tenant_settings (
  tenant_id INT UNSIGNED NOT NULL,
  setting_key VARCHAR(64) NOT NULL,
  value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, setting_key),
  CONSTRAINT fk_tenant_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  lead_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('dp','pelunasan','lainnya') NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  paid_on DATE NOT NULL,
  method VARCHAR(60) NOT NULL,
  description VARCHAR(255) NULL,
  note VARCHAR(255) NULL,
  receipt_no VARCHAR(40) NOT NULL,
  public_token CHAR(64) NOT NULL,
  created_by INT UNSIGNED NULL,
  voided_at DATETIME NULL,
  void_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payments_receipt (tenant_id, receipt_no),
  UNIQUE KEY uq_payments_token (public_token),
  KEY ix_payments_lead (tenant_id, lead_id),
  CONSTRAINT fk_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_payments_lead FOREIGN KEY (lead_id) REFERENCES leads(id),
  CONSTRAINT fk_payments_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nomor kuitansi berurutan per tenant per bulan (YYYYMM).
CREATE TABLE receipt_counters (
  tenant_id INT UNSIGNED NOT NULL,
  period CHAR(6) NOT NULL,
  last_no INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, period),
  CONSTRAINT fk_receipt_counters_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  to_email VARCHAR(190) NOT NULL,
  provider_msg_id VARCHAR(255) NULL,
  status ENUM('queued','sent','delivered','opened','bounced','spam','blocked','failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_try_at DATETIME NULL,
  last_event_at DATETIME NULL,
  error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_email_queue (status, next_try_at),
  KEY ix_email_payment (tenant_id, payment_id),
  KEY ix_email_provider (provider_msg_id),
  CONSTRAINT fk_email_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_email_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Konversi offline ke platform iklan. Disimpan walau tenant belum mengisi dataset (status waiting_config).
CREATE TABLE conversion_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  lead_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  platform ENUM('meta') NOT NULL DEFAULT 'meta',
  event_name VARCHAR(40) NOT NULL,
  event_id VARCHAR(64) NOT NULL,
  event_time DATETIME NOT NULL,
  value DECIMAL(15,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'IDR',
  status ENUM('waiting_config','pending','sent','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_try_at DATETIME NULL,
  response VARCHAR(500) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conversion_event (tenant_id, platform, event_id),
  KEY ix_conversion_queue (status, next_try_at),
  KEY ix_conversion_lead (tenant_id, lead_id),
  CONSTRAINT fk_conversion_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_conversion_lead FOREIGN KEY (lead_id) REFERENCES leads(id),
  CONSTRAINT fk_conversion_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
