CopyToCRM — Customer Analytics / Reports for CRM Integration
==========================================
Intended for later integration with ksf_FA_CRM module.
Contains BABOK requirements, report definitions, and data mappings.

Data source: 0_ksf_square_analytics (Square module analytics tables).
Not inserted into native FA tables (per architecture decision).

Contents:
- BABOK requirements (FR-05 reporting gaps)
- Report definitions (sales comparison, customer analytics, product performance)
- Mapping specs for CRM integration

Gift Cards & Loyalty
--------------------
- Gift Cards: Square allows GC sales + registration. Payment type tracking needed in Staging (stage_payment_type hook) so future GC module can decrement balance immediately (prevent double-spend online/store).
- Loyalty: Resides in CRM (not native FA tables). Data source for analytics: 0_ksf_square_analytics. CopyToCRM/README contains mapping specs.

Disputes Management
-------------------
- Square disputes (chargebacks) tracked via hook log_dispute_crm.
- Not native FA insertion; resides in CRM module (CopyToCRM mapping specs included).
- Evidence tracking and dispute workflow coordinated with CRM.

Banking (GL) Actions
--------------------
- Square payments/staging tracked via stage_payment_type hook.
- Direct analytics in 0_ksf_square_analytics for sales comparison.
- GL account mappings managed via Square module admin (config.php -> Import Settings / GL Accounts).
