-- Ispluka Foundation — additive MySQL schema
--
-- SAFETY RULES:
-- 1. This file only creates new Ispluka tables.
-- 2. It does NOT alter, rename, or delete any existing Nux table/column.
-- 3. It does NOT change config.php or MySQL credentials.
-- 4. Run explicitly against the existing database after backup/validation.
-- 5. Legacy Nux login, billing, MikroTik, RADIUS and cron remain untouched.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_ispluka_tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_key VARCHAR(64) NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    status ENUM('active','suspended','trial','closed') NOT NULL DEFAULT 'trial',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Dhaka',
    currency CHAR(3) NOT NULL DEFAULT 'BDT',
    settings JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ispluka_tenant_key (tenant_key),
    UNIQUE KEY uq_ispluka_tenant_slug (slug),
    KEY idx_ispluka_tenant_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_ispluka_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NULL,
    name VARCHAR(50) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ispluka_role_tenant_name (tenant_id, name),
    KEY idx_ispluka_role_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_ispluka_permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    permission_key VARCHAR(120) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ispluka_permission_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_ispluka_role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_ispluka_rp_permission (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_ispluka_tenant_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    legacy_user_id INT UNSIGNED NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','inactive','invited','suspended') NOT NULL DEFAULT 'active',
    is_owner TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ispluka_tenant_legacy_user (tenant_id, legacy_user_id),
    KEY idx_ispluka_tu_tenant (tenant_id),
    KEY idx_ispluka_tu_role (role_id),
    KEY idx_ispluka_tu_legacy_user (legacy_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_ispluka_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    plan_code VARCHAR(50) NOT NULL,
    plan_name VARCHAR(100) NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    billing_cycle ENUM('monthly','yearly','custom') NOT NULL DEFAULT 'monthly',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    status ENUM('trial','active','past_due','suspended','cancelled','expired') NOT NULL DEFAULT 'trial',
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ispluka_sub_tenant_status (tenant_id, status),
    KEY idx_ispluka_sub_end (ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_ispluka_legacy_mapping (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    legacy_table VARCHAR(100) NOT NULL,
    legacy_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ispluka_legacy_mapping (tenant_id, legacy_table, legacy_id),
    KEY idx_ispluka_mapping_entity (tenant_id, entity_type),
    KEY idx_ispluka_mapping_legacy (legacy_table, legacy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_ispluka_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NULL,
    legacy_user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ispluka_audit_tenant_created (tenant_id, created_at),
    KEY idx_ispluka_audit_entity (entity_type, entity_id),
    KEY idx_ispluka_audit_user (legacy_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed global/system permissions. Idempotent.
INSERT IGNORE INTO tbl_ispluka_permissions (permission_key, display_name, description) VALUES
('platform.tenants.manage', 'Manage tenants', 'Create, update, suspend and manage ISP tenants'),
('platform.subscriptions.manage', 'Manage subscriptions', 'Manage SaaS subscriptions and limits'),
('customers.view', 'View customers', 'View tenant customers'),
('customers.manage', 'Manage customers', 'Create and update tenant customers'),
('billing.view', 'View billing', 'View invoices, payments and balances'),
('billing.manage', 'Manage billing', 'Manage invoices, payments and billing operations'),
('routers.view', 'View routers', 'View tenant MikroTik routers'),
('routers.manage', 'Manage routers', 'Manage routers and network settings'),
('network.provision', 'Provision network users', 'Provision or update PPPoE/Hotspot users'),
('reports.view', 'View reports', 'View operational and billing reports'),
('employees.manage', 'Manage employees', 'Manage tenant employees and access'),
('resellers.manage', 'Manage resellers', 'Manage tenant reseller access'),
('settings.manage', 'Manage settings', 'Manage tenant settings');

-- Seed system role templates. tenant_id NULL means platform/system template.
INSERT IGNORE INTO tbl_ispluka_roles (tenant_id, name, display_name, description, is_system) VALUES
(NULL, 'master_admin', 'Master Admin', 'Platform owner and SaaS administrator', 1),
(NULL, 'admin', 'Admin', 'ISP tenant administrator', 1),
(NULL, 'reseller', 'Reseller', 'ISP reseller', 1),
(NULL, 'employee', 'Employee', 'Tenant employee with scoped permissions', 1),
(NULL, 'customer', 'Customer', 'Customer self-service role', 1);

-- Default system-role permissions. Also idempotent.
INSERT IGNORE INTO tbl_ispluka_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM tbl_ispluka_roles r
JOIN tbl_ispluka_permissions p
WHERE r.tenant_id IS NULL
  AND (
      (r.name = 'master_admin')
      OR (r.name = 'admin' AND p.permission_key NOT LIKE 'platform.%')
      OR (r.name = 'reseller' AND p.permission_key IN ('customers.view','billing.view','routers.view','reports.view'))
      OR (r.name = 'employee' AND p.permission_key IN ('customers.view','billing.view','routers.view','reports.view','network.provision'))
      OR (r.name = 'customer' AND p.permission_key = 'billing.view')
  );
