-- ksf_FA_Square Database Tables
-- These tables are created by StagingTableManager but kept here for reference/manual install.

-- Config table (existing, extended)
CREATE TABLE IF NOT EXISTS 0_square (
    `name` char(15) NOT NULL default '',
    `value` varchar(100) NOT NULL default '',
    `type` varchar(16) DEFAULT NULL,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=MyISAM;

-- Transaction staging table
CREATE TABLE IF NOT EXISTS 0_square_staging_transactions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    source VARCHAR(16) NOT NULL DEFAULT 'api',
    square_transaction_id VARCHAR(32) NOT NULL,
    square_order_id VARCHAR(32) DEFAULT NULL,
    square_payment_id VARCHAR(32) DEFAULT NULL,
    location_id VARCHAR(32) DEFAULT NULL,
    customer_id VARCHAR(32) DEFAULT NULL,
    customer_name VARCHAR(128) DEFAULT NULL,
    transaction_date DATE DEFAULT NULL,
    total_amount DECIMAL(15,2) DEFAULT 0.00,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    tip_amount DECIMAL(15,2) DEFAULT 0.00,
    discount_amount DECIMAL(15,2) DEFAULT 0.00,
    currency VARCHAR(8) DEFAULT 'CAD',
    raw_json LONGTEXT DEFAULT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'staged',
    error_log TEXT DEFAULT NULL,
    fa_invoice_no INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_square_transaction (square_transaction_id),
    KEY idx_status (status),
    KEY idx_date (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Item staging table
CREATE TABLE IF NOT EXISTS 0_square_staging_items (
    id INT(11) NOT NULL AUTO_INCREMENT,
    staging_transaction_id INT(11) DEFAULT NULL,
    sku VARCHAR(64) DEFAULT NULL,
    name VARCHAR(256) DEFAULT NULL,
    quantity INT(11) DEFAULT 0,
    unit_price DECIMAL(15,2) DEFAULT 0.00,
    total DECIMAL(15,2) DEFAULT 0.00,
    tax DECIMAL(15,2) DEFAULT 0.00,
    discount DECIMAL(15,2) DEFAULT 0.00,
    raw_json LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staging_transaction (staging_transaction_id),
    KEY idx_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer mapping table
CREATE TABLE IF NOT EXISTS 0_square_customer_mappings (
    id INT(11) NOT NULL AUTO_INCREMENT,
    square_customer_id VARCHAR(32) NOT NULL,
    fa_debtor_no INT(11) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_square_customer (square_customer_id),
    KEY idx_fa_debtor (fa_debtor_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Import log table
CREATE TABLE IF NOT EXISTS 0_square_import_log (
    id INT(11) NOT NULL AUTO_INCREMENT,
    run_date DATETIME NOT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'api',
    orders_imported INT(11) DEFAULT 0,
    orders_skipped INT(11) DEFAULT 0,
    orders_failed INT(11) DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'completed',
    error_log TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_run_date (run_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
