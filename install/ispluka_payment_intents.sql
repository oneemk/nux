-- Ispluka payment intent/idempotency layer.
-- Additive only: does not alter or rename any legacy table/column.
-- Apply only after backup and validation on the target MySQL database.

CREATE TABLE IF NOT EXISTS `tbl_ispluka_payment_intents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(191) NOT NULL,
  `provider` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'BDT',
  `customer_legacy_id` BIGINT UNSIGNED NULL,
  `status` ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `gateway_trx_id` VARCHAR(191) NULL,
  `metadata` JSON NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ispluka_payment_intent_tenant_key` (`tenant_id`, `idempotency_key`),
  KEY `idx_ispluka_payment_intent_tenant_status` (`tenant_id`, `status`),
  KEY `idx_ispluka_payment_intent_customer` (`tenant_id`, `customer_legacy_id`),
  CONSTRAINT `fk_ispluka_payment_intent_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tbl_ispluka_tenants` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
