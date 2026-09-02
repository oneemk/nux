-- Ispluka legacy settlement ledger.
-- Additive only. This table prevents the same payment intent from being
-- posted to legacy billing more than once, including concurrent requests.

CREATE TABLE IF NOT EXISTS `tbl_ispluka_payment_settlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `payment_intent_id` BIGINT UNSIGNED NOT NULL,
  `legacy_transaction_id` BIGINT UNSIGNED NULL,
  `invoice` VARCHAR(25) NOT NULL,
  `gateway_trx_id` VARCHAR(191) NULL,
  `status` ENUM('posting','posted','failed') NOT NULL DEFAULT 'posting',
  `error_message` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ispluka_settlement_tenant_intent` (`tenant_id`, `payment_intent_id`),
  UNIQUE KEY `uq_ispluka_settlement_tenant_invoice` (`tenant_id`, `invoice`),
  KEY `idx_ispluka_settlement_gateway` (`tenant_id`, `gateway_trx_id`),
  KEY `idx_ispluka_settlement_status` (`tenant_id`, `status`),
  CONSTRAINT `fk_ispluka_settlement_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tbl_ispluka_tenants` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_ispluka_settlement_intent`
    FOREIGN KEY (`payment_intent_id`) REFERENCES `tbl_ispluka_payment_intents` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
