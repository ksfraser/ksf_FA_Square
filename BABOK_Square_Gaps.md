BABOK — Square API Gap Implementation
======================================
Business Requirements (BR):
- FR-04 Customer Management (bi-directional sync, staging, groups, attributes, conflict resolution)
- FR-05 Refund Processing (full lifecycle)
- FR-02 Advanced Orders (full CRUD, status sync)
- FR-05 Webhook Enhancement (reliability, retries)
- FR-05 Reporting & Analytics (direct 0_ksf_square_analytics)
- FR-08 Inventory Management (advanced tracking, multi-location)
- FR-07 Gift Cards & Loyalty
- FR-09 Staff Management (transaction links, analytics)
- FR-10 Bookings API
- FR-10 Invoices API (B2B billing)
- FR-11 Disputes Management (CRM-linked)
- FR-12 Multi-location Support

Use Cases:
- UC-04.01 Sync customer from Square to FA staging
- UC-06.01 Process refund with staging
- UC-02.01 Create/update order with lifecycle events
- UC-05.01 Handle webhook event with reliability
- UC-05.02 Generate sales/customer/product analytics (direct table)
- UC-08.01 Adjust inventory via hook
- UC-07.01 Track gift card/loyalty event
- UC-09.01 Log staff-transaction analytics
- UC-10.01 Handle booking event
- UC-10.02 Process B2B invoice event
- UC-11.01 Track dispute evidence for CRM
- UC-12.01 Log multi-location transfer event

Functional Requirements (FR): All covered by custom hooks (stage_customer_data, stage_customer_attributes, stage_refund, stage_order_lifecycle, stage_webhook_event, stage_inventory_adjustment, stage_gift_card, stage_loyalty_program, stage_payment_type, stage_sales_report/direct analytics, stage_location_transfer, log_dispute_crm).

Test Cases:
- Unit tests: CustomerService (309), Staging (128), Woocommerce (712)
- Integration: ImportStaging, SquareIntegration
- E2E: Playwright import-flow
