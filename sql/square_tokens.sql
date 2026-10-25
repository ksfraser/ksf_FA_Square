-- square_tokens table
-- Maps FA stock items to Square catalog objects, environment-isolated.
CREATE TABLE IF NOT EXISTS `0_square_tokens` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `stock_id` VARCHAR(20) NOT NULL,
    `sku` VARCHAR(255) NOT NULL,
    `square_catalog_object_id` VARCHAR(255) NOT NULL,
    `square_variation_id` VARCHAR(255) NULL,
    `environment` VARCHAR(20) NOT NULL DEFAULT 'sandbox',
    `fa_last_updated` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_stock_id_env` (`stock_id`, `environment`),
    KEY `idx_sku` (`sku`),
    KEY `idx_environment` (`environment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
