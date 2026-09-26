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
    source VARCHAR(32) NOT NULL DEFAULT 'square' COMMENT 'Data source: square, crm, inventory, staff, disputes, gift_card, loyalty, multi_location',
    source_customer_id VARCHAR(64) DEFAULT NULL COMMENT 'Customer/order/item ID from source system',
    customer_id VARCHAR(64) DEFAULT NULL COMMENT 'Matched Square/FA customer ID',
    order_id VARCHAR(64) DEFAULT NULL COMMENT 'Square/FA order reference',
    item_code VARCHAR(64) DEFAULT NULL COMMENT 'Product/item code',
    category VARCHAR(32) DEFAULT NULL COMMENT 'Product category',
    location_id VARCHAR(32) DEFAULT NULL COMMENT 'Location for multi-location tracking',
    metric_type VARCHAR(32) NOT NULL DEFAULT 'sales' COMMENT 'sales, customer, inventory, staff, gift_card, loyalty, disputes, multi_location, reporting',
    metric_subtype VARCHAR(32) DEFAULT NULL COMMENT 'Sub-category or event type',
    metric_value DECIMAL(15,2) DEFAULT 0.00,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    discount_amount DECIMAL(15,2) DEFAULT 0.00,
    fees DECIMAL(15,2) DEFAULT 0.00,
    currency VARCHAR(8) DEFAULT 'CAD',
    status VARCHAR(16) DEFAULT NULL COMMENT 'Record status',
    comparison_source VARCHAR(32) DEFAULT NULL COMMENT 'Source for comparison analytics: fa_vs_square',
    raw_json LONGTEXT DEFAULT NULL COMMENT 'Full source record or comparison payload',
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_source (source),
    KEY idx_customer (customer_id),
    KEY idx_order (order_id),
    KEY idx_metric_type (metric_type),
    KEY idx_location (location_id),
    KEY idx_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
