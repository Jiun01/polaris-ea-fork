-- Migration 061: tenant_id + tenant_branch_id on all multi-tenant tables

ALTER TABLE ea_appointments
  ADD COLUMN tenant_id VARCHAR(36) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN tenant_branch_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id,
  ADD INDEX idx_appointments_tenant_id (tenant_id, id),
  ADD INDEX idx_appointments_tenant_start (tenant_id, start_datetime),
  ADD INDEX idx_appointments_tenant_provider (tenant_id, id_users_provider);

ALTER TABLE ea_services
  ADD COLUMN tenant_id VARCHAR(36) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN tenant_branch_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id,
  ADD INDEX idx_services_tenant_id (tenant_id, id),
  ADD INDEX idx_services_tenant_private (tenant_id, is_private);

ALTER TABLE ea_service_categories
  ADD COLUMN tenant_id VARCHAR(36) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN tenant_branch_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id,
  ADD INDEX idx_service_categories_tenant_id (tenant_id, id);

ALTER TABLE ea_users
  ADD COLUMN tenant_id VARCHAR(36) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN tenant_branch_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id,
  ADD INDEX idx_users_tenant_id (tenant_id, id),
  ADD UNIQUE INDEX uq_users_tenant_email (tenant_id, email);

ALTER TABLE ea_settings
  ADD COLUMN tenant_id VARCHAR(36) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN tenant_branch_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id,
  ADD INDEX idx_settings_tenant_id (tenant_id, id);

ALTER TABLE ea_user_settings
  ADD COLUMN tenant_id VARCHAR(36) NOT NULL DEFAULT '' AFTER id_users,
  ADD COLUMN tenant_branch_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id,
  ADD INDEX idx_user_settings_tenant_id (tenant_id, id_users);

ALTER TABLE ea_secretaries_providers
  ADD COLUMN tenant_id VARCHAR(36) NOT NULL DEFAULT '' AFTER id_users_secretary,
  ADD COLUMN tenant_branch_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id,
  ADD INDEX idx_secretaries_providers_tenant_id (tenant_id, id_users_secretary);

ALTER TABLE ea_blocked_periods
  ADD COLUMN tenant_id VARCHAR(36) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN tenant_branch_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id,
  ADD INDEX idx_blocked_periods_tenant_id (tenant_id, id);

ALTER TABLE ea_webhooks
  ADD COLUMN tenant_id VARCHAR(36) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN tenant_branch_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id,
  ADD INDEX idx_webhooks_tenant_id (tenant_id, id);

INSERT INTO ea_migrations (version) VALUES (61) ON DUPLICATE KEY UPDATE version = version;

-- Migration 062: ea_polaris_tenants shadow table

CREATE TABLE ea_polaris_tenants (
  tenant_id  VARCHAR(36)                      NOT NULL,
  slug       VARCHAR(100)                     NOT NULL,
  status     ENUM('active','suspended')       NOT NULL DEFAULT 'active',
  synced_at  DATETIME                         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id),
  UNIQUE INDEX uq_polaris_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ea_migrations (version) VALUES (62) ON DUPLICATE KEY UPDATE version = version;

SELECT 'Migrations 061+062 complete' AS status;
