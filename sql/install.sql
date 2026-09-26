-- ksf_FA_Square Database Tables
-- These tables are created by StagingTableManager but kept here for reference/manual install.

-- Config table (existing, extended)
-- Note: name column enlarged from char(15) to varchar(50) to fit sandbox_access_token etc.
-- If upgrading from an older version, run: ALTER TABLE 0_square MODIFY name varchar(50) NOT NULL default '';
CREATE TABLE IF NOT EXISTS 0_square (
    `name` varchar(50) NOT NULL default '',
    `value` varchar(100) NOT NULL default '',
    `type` varchar(16) DEFAULT NULL,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=MyISAM;

-- Square Customer mappings table
-- Maps FA debtors to Square Customers (required for Square Invoices).
CREATE TABLE IF NOT EXISTS 0_square_customer_mappings (
    fa_debtor_no INT(11) NOT NULL,
    square_customer_id VARCHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fa_debtor_no),
    KEY idx_square_customer_id (square_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Square Analytics Table (direct storage, not native FA insertion, not staging)
CREATE TABLE IF NOT EXISTS 0_ksf_square_analytics (
    id INT(11) NOT NULL AUTO_INCREMENT,
    source VARCHAR(32) NOT NULL DEFAULT 'square',
    metric_type VARCHAR(32) NOT NULL DEFAULT 'sales',
    metric_value DECIMAL(15,2) DEFAULT 0.00,
    extra LONGTEXT DEFAULT NULL COMMENT 'JSON details: links, filters, comparison data',
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_source (source),
    KEY idx_metric_type (metric_type),
    KEY idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
