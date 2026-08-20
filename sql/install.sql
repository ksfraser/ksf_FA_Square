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

-- Token mapping table (FA stock_id <-> Square catalog_object_id)
-- Note: environment column allows sandbox and production mappings to coexist
CREATE TABLE IF NOT EXISTS 0_square_tokens (
    id INT(11) NOT NULL AUTO_INCREMENT,
    stock_id VARCHAR(20) NOT NULL,
    sku VARCHAR(64) DEFAULT NULL,
    square_catalog_object_id VARCHAR(32) NOT NULL,
    square_variation_id VARCHAR(32) DEFAULT NULL,
    environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
    fa_last_updated TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_stock_id_env (stock_id, environment),
    UNIQUE KEY idx_square_object_env (square_catalog_object_id, environment),
    KEY idx_sku (sku),
    KEY idx_environment (environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrade notes for existing installations:
-- ALTER TABLE 0_square_tokens ADD COLUMN environment VARCHAR(20) NOT NULL DEFAULT 'sandbox';
-- ALTER TABLE 0_square_tokens DROP KEY idx_stock_id;
-- ALTER TABLE 0_square_tokens ADD UNIQUE KEY idx_stock_id_env (stock_id, environment);
-- ALTER TABLE 0_square_tokens DROP KEY idx_square_object;
-- ALTER TABLE 0_square_tokens ADD UNIQUE KEY idx_square_object_env (square_catalog_object_id, environment);
-- ALTER TABLE 0_square_tokens ADD KEY idx_environment (environment);
-- ALTER TABLE 0_square_tokens MODIFY COLUMN square_variation_id VARCHAR(32) NULL;

-- Location mapping table (FA loc_code <-> Square location_id)
-- Supports many-to-one: multiple FA locations can map to one Square location
-- Special fa_loc_code = '*ALL*' means "sum all FA locations to this Square location"
CREATE TABLE IF NOT EXISTS 0_square_location_mappings (
    id INT(11) NOT NULL AUTO_INCREMENT,
    fa_loc_code VARCHAR(5) NOT NULL,
    square_location_id VARCHAR(32) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_fa_loc_code (fa_loc_code),
    KEY idx_square_location (square_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- ksf_import_square_* tables - Backward compatible with FA_ImportSquareUp
-- These tables exist in production with matched transaction data.
-- Used for both CSV imports (legacy) and API imports (new).
-- ============================================================================

-- Unified staging transactions table (ksf_import_square_transactions)
-- Combines:
--   - Original CSV fields from FA_ImportSquareUp
--   - API-specific fields (raw_json, environment, status tracking)
-- Used by both CSV import and API import flows.
CREATE TABLE IF NOT EXISTS 0_ksf_import_square_transactions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    Date DATE NOT NULL,
    Time VARCHAR(8) NOT NULL DEFAULT '',
    Timezone VARCHAR(64) NOT NULL DEFAULT '',
    gross_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discounts DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    service_charges DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    gift_card_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tip DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    partial_refunds DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_collected DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    source VARCHAR(16) NOT NULL DEFAULT 'api',
    card DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    card_entry_methods VARCHAR(16) NOT NULL DEFAULT '',
    cash DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    square_gift_card DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    other_tender DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    other_tender_type VARCHAR(16) NOT NULL DEFAULT '',
    other_tender_note VARCHAR(32) NOT NULL DEFAULT '',
    fees DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    transaction_id VARCHAR(32) NOT NULL,
    payment_id VARCHAR(32) NOT NULL DEFAULT '',
    card_brand VARCHAR(16) NOT NULL DEFAULT '',
    PAN_suffix INT(11) NOT NULL DEFAULT 0,
    device_name VARCHAR(32) NOT NULL DEFAULT '',
    staff_name VARCHAR(16) NOT NULL DEFAULT '',
    staff_id VARCHAR(16) NOT NULL DEFAULT '',
    description VARCHAR(64) NOT NULL DEFAULT '',
    details VARCHAR(64) NOT NULL DEFAULT '',
    event_type VARCHAR(32) NOT NULL DEFAULT '',
    location VARCHAR(32) NOT NULL DEFAULT '',
    Dining_option VARCHAR(16) NOT NULL DEFAULT '',
    Customer_id INT(11) NOT NULL DEFAULT 0,
    customer_name VARCHAR(64) NOT NULL DEFAULT '',
    customer_reference_id VARCHAR(16) NOT NULL DEFAULT '',
    device_nickname VARCHAR(16) NOT NULL DEFAULT '',
    third_party_fees DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    deposit_id VARCHAR(32) NOT NULL DEFAULT '',
    deposit_date DATE DEFAULT NULL,
    deposit_details VARCHAR(64) NOT NULL DEFAULT '',
    fee_percentage_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    fee_fixed_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    refund_reason VARCHAR(64) NOT NULL DEFAULT '',
    discount_name VARCHAR(16) NOT NULL DEFAULT '',
    transaction_status VARCHAR(16) NOT NULL DEFAULT '',
    order_reference_id VARCHAR(16) NOT NULL DEFAULT '',
    fulfillment_note VARCHAR(32) NOT NULL DEFAULT '',
    free_processing_applied DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    channel VARCHAR(32) NOT NULL DEFAULT '',
    unattributed_tips DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    square_order_id VARCHAR(32) DEFAULT NULL,
    square_location_id VARCHAR(32) DEFAULT NULL,
    square_customer_id VARCHAR(32) DEFAULT NULL,
    environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
    status VARCHAR(16) NOT NULL DEFAULT 'staged',
    raw_json LONGTEXT DEFAULT NULL,
    error_log TEXT DEFAULT NULL,
    fa_invoice_no INT(11) DEFAULT NULL,
    fa_debtor_no INT(11) DEFAULT NULL,
    fa_branch_code INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_transaction_id (transaction_id),
    UNIQUE KEY idx_payment_id (payment_id),
    KEY idx_date (Date),
    KEY idx_status (status),
    KEY idx_environment (environment),
    KEY idx_source (source),
    KEY idx_deposit_id (deposit_id),
    KEY idx_fa_invoice (fa_invoice_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Staging line items table (ksf_import_square_items)
-- Line items for each staged transaction.
CREATE TABLE IF NOT EXISTS 0_ksf_import_square_items (
    id INT(11) NOT NULL AUTO_INCREMENT,
    Date DATE NOT NULL,
    Time VARCHAR(8) NOT NULL DEFAULT '',
    Timezone VARCHAR(64) NOT NULL DEFAULT '',
    Category VARCHAR(32) NOT NULL DEFAULT '',
    Item VARCHAR(64) NOT NULL DEFAULT '',
    Price_Point_Name VARCHAR(32) NOT NULL DEFAULT '',
    stock_id VARCHAR(32) NOT NULL DEFAULT '',
    modifiers_applied VARCHAR(32) NOT NULL DEFAULT '',
    quantity INT(11) NOT NULL DEFAULT 0,
    gross_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discounts DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    transaction_id VARCHAR(32) NOT NULL,
    payment_id VARCHAR(32) NOT NULL DEFAULT '',
    device_name VARCHAR(32) NOT NULL DEFAULT '',
    notes VARCHAR(64) NOT NULL DEFAULT '',
    details VARCHAR(64) NOT NULL DEFAULT '',
    event_type VARCHAR(32) NOT NULL DEFAULT '',
    location VARCHAR(32) NOT NULL DEFAULT '',
    dining_option VARCHAR(16) NOT NULL DEFAULT '',
    Customer_id INT(11) NOT NULL DEFAULT 0,
    customer_name VARCHAR(64) NOT NULL DEFAULT '',
    customer_reference_id VARCHAR(16) NOT NULL DEFAULT '',
    unit VARCHAR(16) NOT NULL DEFAULT '',
    count DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    itemization_type VARCHAR(16) NOT NULL DEFAULT '',
    fulfillment_note VARCHAR(32) NOT NULL DEFAULT '',
    sku VARCHAR(64) DEFAULT NULL,
    name VARCHAR(256) DEFAULT NULL,
    unit_price DECIMAL(15,2) DEFAULT 0.00,
    total_amount DECIMAL(15,2) DEFAULT 0.00,
    discount_amount DECIMAL(15,2) DEFAULT 0.00,
    square_catalog_object_id VARCHAR(32) DEFAULT NULL,
    square_variation_id VARCHAR(32) DEFAULT NULL,
    raw_json LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transaction_id (transaction_id),
    KEY idx_payment_id (payment_id),
    KEY idx_stock_id (stock_id),
    KEY idx_sku (sku),
    KEY idx_date (Date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment matching table (ksf_import_square_payments)
-- Tracks matches between Square payment IDs and FA transactions.
-- Existing production data should be preserved.
CREATE TABLE IF NOT EXISTS 0_ksf_import_square_payments (
    square_import_payments_id INT(11) NOT NULL AUTO_INCREMENT,
    square_payment_id VARCHAR(32) NOT NULL,
    total_collected DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    trans_type INT(11) NOT NULL DEFAULT 0,
    trans_no VARCHAR(32) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (square_import_payments_id),
    UNIQUE KEY idx_square_payment_id (square_payment_id),
    KEY idx_fa_trans (trans_type, trans_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales matching table (ksf_import_square_sales)
-- Tracks matches between Square transaction IDs and FA sales documents.
-- Existing production data should be preserved.
CREATE TABLE IF NOT EXISTS 0_ksf_import_square_sales (
    ksf_import_square_sales_id INT(11) NOT NULL AUTO_INCREMENT,
    square_transaction_id VARCHAR(32) NOT NULL,
    sales_order_no VARCHAR(32) NOT NULL DEFAULT '',
    sales_delivery_no VARCHAR(32) NOT NULL DEFAULT '',
    sales_invoice_no VARCHAR(32) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ksf_import_square_sales_id),
    UNIQUE KEY idx_square_transaction_id (square_transaction_id),
    KEY idx_sales_invoice (sales_invoice_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Upgrade notes for existing FA_ImportSquareUp installations
-- ============================================================================
-- These columns need to be added to existing production tables:
--
-- For ksf_import_square_transactions:
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN square_order_id VARCHAR(32) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN square_location_id VARCHAR(32) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN square_customer_id VARCHAR(32) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN environment VARCHAR(20) NOT NULL DEFAULT 'sandbox';
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'staged';
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN raw_json LONGTEXT DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN error_log TEXT DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN fa_invoice_no INT(11) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN fa_debtor_no INT(11) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_transactions ADD COLUMN fa_branch_code INT(11) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_transactions ADD KEY idx_status (status);
--   ALTER TABLE 0_ksf_import_square_transactions ADD KEY idx_environment (environment);
--   ALTER TABLE 0_ksf_import_square_transactions ADD KEY idx_fa_invoice (fa_invoice_no);
--
-- For ksf_import_square_items:
--   ALTER TABLE 0_ksf_import_square_items ADD COLUMN sku VARCHAR(64) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_items ADD COLUMN name VARCHAR(256) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_items ADD COLUMN unit_price DECIMAL(15,2) DEFAULT 0.00;
--   ALTER TABLE 0_ksf_import_square_items ADD COLUMN total_amount DECIMAL(15,2) DEFAULT 0.00;
--   ALTER TABLE 0_ksf_import_square_items ADD COLUMN discount_amount DECIMAL(15,2) DEFAULT 0.00;
--   ALTER TABLE 0_ksf_import_square_items ADD COLUMN square_catalog_object_id VARCHAR(32) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_items ADD COLUMN square_variation_id VARCHAR(32) DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_items ADD COLUMN raw_json LONGTEXT DEFAULT NULL;
--   ALTER TABLE 0_ksf_import_square_items ADD KEY idx_sku (sku);
--
-- TransactionStagingDAO::ensureTableExists() and ItemStagingDAO::ensureTableExists()
-- will automatically add these columns if they don't exist (safe for production).

-- Square Invoice mapping table
-- Links FA sales invoices to Square Invoices for payment tracking.
-- When a Square-Invoice destination triggers, the Square Invoice ID is stored here.
-- When the Square transaction is imported, it matches back to the FA invoice.
CREATE TABLE IF NOT EXISTS 0_square_invoice_map (
    fa_invoice_no INT(11) NOT NULL,
    square_invoice_id VARCHAR(64) NOT NULL DEFAULT '',
    square_order_id VARCHAR(64) NOT NULL DEFAULT '',
    square_customer_id VARCHAR(64) NOT NULL DEFAULT '',
    amount_cents INT(11) NOT NULL DEFAULT 0,
    currency VARCHAR(3) NOT NULL DEFAULT 'CAD',
    destination VARCHAR(32) NOT NULL DEFAULT 'square_invoice',
    status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    public_url VARCHAR(512) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (fa_invoice_no),
    KEY idx_square_invoice_id (square_invoice_id),
    KEY idx_square_order_id (square_order_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Square Customer mappings table
-- Maps FA debtors to Square Customers (required for Square Invoices).
CREATE TABLE IF NOT EXISTS 0_square_customer_mappings (
    fa_debtor_no INT(11) NOT NULL,
    square_customer_id VARCHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fa_debtor_no),
    KEY idx_square_customer_id (square_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
