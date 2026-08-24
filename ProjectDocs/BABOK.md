# BABOK Alignment — ksf_FA_Square

## Overview

This document maps the ksf_FA_Square project to the **Business Analysis Body of Knowledge (BABOK 3.0)** knowledge areas, tasks, and techniques. Each requirement and design decision is traced to BABOK tasks to ensure complete coverage of the business analysis process.

---

## Knowledge Area 1: Business Analysis Planning and Monitoring

### Task: Plan Business Analysis Approach
| Element | Application |
|---------|-------------|
| Approach | Agile BA — iterative discovery with working software as primary deliverable. Requirements refined through working module demos. |
| Techniques | Stakeholder List, Business Process Modeling, Scope Modeling |
| Outputs | This BABOK document, Requirements.md, RTM.md, UML.md |

### Task: Plan Stakeholder Engagement
| Stakeholder | BABOK Role | Engagement Strategy |
|-------------|------------|---------------------|
| FA Administrator | Domain SME | Weekly demos, Configuration UI reviews |
| Sales Operator | End User | Terminal workflow testing, feedback sessions |
| Finance/Accounting | Business Sponsor | Requirements sign-off, reconciliation reports |
| Developer | Implementation | AGENTS.md conventions, peer reviews |

### Task: Plan Business Analysis Governance
| Decision | Authority | Process |
|----------|-----------|---------|
| Requirement priority | Product Owner (Kevin) | MoSCoW: Must/Should/Could/Won't |
| Scope changes | Change Control Board | Impact analysis -> RTM update -> Approval |
| Acceptance criteria | FA Administrator | Tested in UAT (PHP 7.4) before prod (PHP 7.3) |

### Task: Plan BA Information Management
| Artifact | Repository | Access |
|----------|------------|--------|
| Requirements | ProjectDocs/Requirements.md | Read-only for stakeholders |
| RTM | ProjectDocs/RTM.md | All team members |
| Code | GitHub | Developers |
| Test cases | tests/ directory | QA team |

---

## Knowledge Area 2: Elicitation and Collaboration

### Task: Prepare for Elicitation
| Source | Technique | Outcome |
|--------|-----------|---------|
| Square SDK v40 API docs | Document Analysis | Catalog of relevant API endpoints |
| Existing Marty's square.php (legacy) | Interface Analysis | Current capability map |
| New refactored src/ classes | Code Analysis | Current state of refactoring |
| Square Developer Docs | Document Analysis | API capability inventory |
| Export_Woocommerce repo | Interface Analysis | WooCommerce staging approach |
| FA_ImportSquareUp repo | Interface Analysis | CSV import approach |

### Task: Conduct Elicitation
| Session | Technique | Result |
|---------|-----------|--------|
| API Capability Review | Document Analysis | Mapped Square SDK endpoints -> Business needs |
| Code Review | Interface Analysis | Identified gaps: tests, DI, staging unification |
| Unified Import Staging Plan | Stakeholder Interview | Shared staging library across Square + WooCommmerce |

### Task: Confirm Elicitation Results
Requirements confirmed via:
- Cross-reference of Square SDK capabilities against FA business processes
- Mapping each Square endpoint to a specific FA transaction type
- Stakeholder review of Requirements.md

---

## Knowledge Area 3: Requirements Life Cycle Management

### Task: Trace Requirements
See **RTM.md** for the full Requirements Traceability Matrix. Every functional requirement maps to:
- A Square API endpoint (from SDK v40)
- An FA code file (existing or new)
- A test case

### Task: Maintain Requirements
| Change Driver | Impact | Process |
|---------------|--------|---------|
| Square SDK version update | Potential API breakage | Update RTM, re-test affected UCs |
| FA version upgrade | Table/API changes | Update mapping layer |
| New Square API endpoints | Additional capabilities | Elicit -> FR -> RTM -> Code -> Test |

### Task: Prioritize Requirements
Using MoSCoW:

**Must Have (Export Priority)**
- FR-01.01 through FR-01.13: Product catalog export (SKU, description, category, price, QoH, logging, test limit)
- FR-07.01 through FR-07.06: Configuration
- FR-08.01 through FR-08.08: Location mapping with N:1 / 1:1 QOH aggregation
- Wire `pushInventory()` into export flow
- SquareClientFactory extraction (DRY) — **DONE**

**Should Have (This weekend — Import Priority)**
- FR-03.01 through FR-03.11: Sales import from Square API
- FR-04.01 through FR-04.05: Customer matching
- FR-06.01 through FR-06.05: Unified import staging foundation

**Could Have (v2.0+)**
- FR-02.01 through FR-02.06: Terminal payments
- FR-05.01 through FR-05.07: CSV import merging

**Won't Have (v3.0+)**
- Loyalty, gift cards, subscriptions

---

## Knowledge Area 4: Strategy Analysis

### Task: Analyze Current State
| Element | Current State |
|---------|---------------|
| Business Process | Manual: Products entered in both FA and Square. Sales reconciled manually from Square Dashboard CSV export. |
| Technology | Square SDK v40, PHP 7.3/7.4/8.1, FA 2.4.3, PSR-4 modules |
| Pain Points | Dual data entry, manual reconciliation, no inventory sync (QOH not pushed), no location mapping, no integrated card payments |
| Legacy Code | Marty's square.php (6+ years old, SquareConnect SDK v3, not used in production). New src/ classes cover same functionality. |
| Export Progress (2026-05-21) | Catalog export working with per-item logging, 10-item test limit, UTF-8 sanitization. Categories create in Square. QOH push not yet wired into export flow. |

### Task: Define Future State
| Element | Future State |
|---------|--------------|
| Product Sync | One-click push of FA inventory to Square catalog + stock counts with location-aware QOH aggregation |
| Location Mapping | Configurable N:1 and 1:1 mapping of FA stock locations to Square locations for inventory push |
| Payments | FA invoice -> Square Terminal checkout -> FA payment recording |
| Sales Import | Automated/scheduled pull of Square sales -> staging -> FA invoices |
| Customer Sync | Bi-directional customer matching and creation |
| Import Staging | Unified schema for Square API, Square CSV, and WooCommerce imports |
| Technology | Modular PSR-4 PHP, SOLID/DRY/DI, shared libraries |

### Task: Assess Risks
| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Square API breaking changes | Medium | High | Keep SDK version pinned, use adapter pattern |
| PHP 7.3 EOL/no security updates | High | Medium | Plan PHP 8.x upgrade path for FA |
| Idempotency failures -> duplicate transactions | Low | High | Use idempotency keys on all write operations |
| Rate limiting on batch operations | Medium | Medium | Implement throttling + retry logic |

### Task: Define Change Strategy
| Phase | Scope | Timeline |
|-------|-------|----------|
| Phase 1: Export Stabilize (DONE) | SquareClientFactory, UTF-8 sanitization, per-item logging, test limit, env switcher with Go button | Completed 2026-05-21 |
| Phase 2: Location Mapping (NEXT) | `square_location_mappings` table, LocationMapper service, mapping UI, wire pushInventory() with aggregation | Current |
| Phase 3: Import | Wire OrderImporter into import.php properly, add unit tests | After Phase 2 |
| Phase 4: Unified Staging | Extract ksf_ImportStaging shared library | After Phase 3 |
| Phase 5: Terminal Payments | Add Terminal API integration | Future |
| Phase 6: CSV Merge | Integrate FA_ImportSquareUp into unified staging | Future |

---

## Knowledge Area 5: Requirements Analysis and Design Definition

### Task: Specify and Model Requirements
| Artifact | Technique | Content |
|----------|-----------|---------|
| Requirements.md | FR Specification | Functional + non-functional requirements |
| RTM.md | Traceability Matrix | FR -> UC -> Code -> Test |
| UML.md | UML diagrams | Class, sequence, component diagrams |

### Task: Define Design Options
| Option | Description | Recommendation |
|--------|-------------|----------------|
| Monolithic (Marty's legacy) | Single square.php handles all actions | Rejected — violates SRP |
| Modular (current) | PSR-4 src/ classes per domain | **Implemented** — aligns with AGENTS.md |
| SquareClientFactory (planned) | Extract duplicate ::create() methods | **Next** — DRY |

### Task: Recommend Solution
**Modular re-architecture** following AGENTS.md:
- `src/Push/` — Catalog export and Terminal payment services
- `src/Pull/` — Order/payment import from Square API
- `src/Staging/` — FA database staging and processing
- `src/Config/` — Settings DTO
- `src/Contracts/` — Service interfaces
- `src/Exceptions/` — Custom exceptions
- `pages/` — FA UI pages (thin controllers)
- Future: `ksf_ImportStaging` shared library

---

## Knowledge Area 6: Solution Evaluation

### Task: Measure Solution Performance
| Metric | Target | Measurement |
|--------|--------|-------------|
| Export success rate | >99% | Success vs failed API calls |
| Import accuracy | 100% | Spot-check imported orders |
| Sync latency | <5 min | Time from FA save to Square update |

### Task: Analyze Performance Gaps
Performance gaps will be identified through:
- Square API error logs
- FA audit trail
- User-reported discrepancies

### Task: Assess Solution Limitations
| Limitation | Impact | Workaround | Enhancement Plan |
|------------|--------|------------|------------------|
| Square sandbox vs prod differences | Test coverage gaps | Use sandbox + manual prod verification | Implement webhook testing environment |
| FA stock moves don't trigger auto-sync | Stale inventory | Manual re-export trigger | Implement FA stock event listeners |
| PHP 7.3 limits SDK version to v40 | Miss v41+ improvements | Plan PHP upgrade | Phase 1: PHP 8.x upgrade planning |
| Customer sync is one-directional only | Limited data consistency | Manual customer updates | Phase 2: Bi-directional customer sync |
| No real-time webhook synchronization | Sync delays, data drift | Current polling approach | Phase 1: Webhook subscription implementation |
| Refund processing not implemented | Incomplete payment lifecycle | Manual refund processing | Phase 1: Refund API implementation |
| Custom invoice creation vs FA sales orders | Limited FA integration potential | Current custom implementation | Phase 2: Replace with FA sales service |

---

## BABOK Techniques Applied

| Technique | BABOK Ref | Application |
|-----------|-----------|-------------|
| Business Process Modeling | 10.4 | FA invoice-to-payment flow |
| Data Flow Diagrams | 10.14 | Square <-> FA data movement |
| Data Modeling | 10.15 | staging tables, mapping tables |
| Document Analysis | 10.18 | Square SDK API reference |
| Functional Decomposition | 10.20 | Requirements -> Use Cases -> Code |
| Interface Analysis | 10.25 | Legacy square.php -> new src/ classes |
| Metrics and KPIs | 10.28 | success rate, accuracy, latency |
| Process Modeling | 10.35 | Export, Import, Payment workflows |
| Risk Analysis | 10.39 | API changes, PHP EOL, idempotency |
| Scope Modeling | 10.42 | In-scope / out-of-scope matrix |
| Stakeholder List | 10.44 | FA Admin, Sales, Finance, Developer |
| Traceability Matrix | 10.47 | RTM.md |
| UML Diagrams | 10.48 | UML.md |

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-05-20 | KSFraser | Initial BABOK alignment for Square SDK v40 |
| 0.2 | 2026-05-21 | KSFraser | Updated to reflect current state, refactoring progress, and unified staging plan |
| 0.3 | 2026-05-21 | KSFraser | Updated current/future state, prioritization, and change strategy with Phase 1 completion and Phase 2 (Location Mapping FR-08) |
| 0.4 | 2026-05-22 | KSFraser | Added DAO layer, Service layer, and inter-module communication via hook_invoke pattern |
| 0.5 | 2026-05-23 | KSFraser | Updated with comprehensive Square API coverage analysis and FA integration opportunities |
| 0.6 | 2026-08-20 | KSFraser | Added BABOK alignment for ISU config absorption and staging ownership refactor |

---

## Recent Updates (2026-05-23)

### 🚀 **Comprehensive Square API Coverage Analysis**

Based on current implementation analysis, the following Square API capabilities have been evaluated:

#### ✅ **Currently Implemented (API Coverage: ~60%)**

| Category | Endpoints | Status | Implementation Details |
|----------|-----------|--------|------------------------|
| **Catalog Management** | `upsertCatalogObject` | ✅ Complete | Create/update products, batch operations, image upload |
| | `listCatalog`, `searchCatalogObjects` | ✅ Complete | Product discovery by SKU/name |
| | `retrieveCatalogObject`, `deleteCatalogObject` | ✅ Complete | Product lifecycle management |
| **Inventory Management** | `batchChangeInventory` | ✅ Complete | Stock level updates with location mapping |
| | `retrieveInventoryCount` | ✅ Complete | QOH retrieval |
| **Orders & Terminal** | `createOrder`, `createTerminalCheckout` | ✅ Complete | Order creation and payment initiation |
| | `getTerminalCheckout`, `cancelTerminalCheckout` | ✅ Complete | Payment status management |
| **Payments Processing** | `listPayments`, `getPayment` | ✅ Complete | Payment retrieval with order details |
| **Data Management** | Comprehensive staging system | ✅ Complete | Unified staging tables for transaction processing |
| **Configuration** | Environment management | ✅ Complete | Sandbox/production token management |
| **Import/Export Logging** | Detailed audit trails | ✅ Complete | Date range tracking, gap detection |

#### 🚫 **Missing Critical API Coverage (40% Gap)**

| Category | Endpoints | Impact | Priority | Implementation Strategy |
|----------|-----------|--------|----------|--------------------------|
| **Customer Management** | `CustomersApi::createCustomer` | ⚠️ High | **HIGH** | Implement bi-directional customer sync |
| | `CustomersApi::updateCustomer` | ⚠️ High | **HIGH** | Integrate with ksf_FA_CRM if available |
| | `CustomersApi::searchCustomers` | ⚠️ High | **HIGH** | Customer deduplication and matching |
| | `CustomersApi::listCustomers` | ⚠️ High | **HIGH** | Customer data import/export |
| **Webhook Management** | `WebhookSubscriptionsApi` | ⚠️ High | **HIGH** | Real-time synchronization (essential) |
| | Event handling | ⚠️ High | **HIGH** | Replace polling with webhook-driven sync |
| **Refunds & Voids** | `RefundsApi::createRefund` | ⚠️ Medium | **HIGH** | Complete payment lifecycle |
| | `PaymentsApi::cancelPayment` | ⚠️ Medium | **HIGH** | Payment reversal capabilities |
| **Locations Management** | `LocationsApi::createLocation` | ⚠️ Medium | **MEDIUM** | Automated location setup |
| | `LocationsApi::updateLocation` | ⚠️ Medium | **MEDIUM** | Location metadata management |
| **Advanced Order Features** | `OrdersApi::calculateOrder` | ⚠️ Medium | **MEDIUM** | Order preview and validation |
| | `OrdersApi::cloneOrder` | ⚠️ Medium | **MEDIUM** | Order duplication |
| **Reporting & Analytics** | All reporting endpoints | ⚠️ Medium | **MEDIUM** | Business intelligence integration |
| **Gift Cards & Loyalty** | `GiftCardsApi`, `LoyaltyApi` | ⚠️ Low | **LOW** | Future enhancement |
| **Employees & Labor** | `TeamApi`, `LaborApi` | ⚠️ Low | **LOW** | HR/payroll integration |

#### 🔄 **FrontAccounting Native Integration Opportunities**

| FA Capability | Current Implementation | Enhancement Opportunity | Impact |
|---------------|------------------------|------------------------|--------|
| **Customer Management** | Basic customer staging tables | Replace with ksf_FA_CRM integration | 🔥 High |
| **Inventory Management** | QOH push with location mapping | Integrate with FA stock events, supplier management | 🔥 High |
| **Sales Processing** | Custom invoice creation | Use FA sales orders, delivery notes, credit notes | 🔥 High |
| **Tax Management** | Square tax object creation | Use FA tax groups, tax authority integration | 🔥 Medium |
| **Payment Processing** | Basic payment matching | Use FA payment allocations, bank reconciliation | 🔥 Medium |
| **Configuration** | Custom configuration tables | Use FA system preferences, user settings | 🔥 Medium |
| **Reporting** | Basic import logs | Extend FA reporting with Square analytics | 🔥 Medium |

#### 🎯 **Recommended Implementation Phases**

**Phase 1: Critical Missing APIs (Next 30 days)**
1. **Webhook Subscriptions** - Real-time synchronization foundation
2. **Customer Management** - Bi-directional customer sync
3. **Refund Processing** - Complete payment lifecycle

**Phase 2: FA Native Integration (Next 90 days)**
1. **CustomerService** - Replace staging with ksf_FA_CRM integration
2. **InventoryService** - Enhanced stock events integration
3. **SalesService** - Use FA sales orders instead of custom invoices
4. **TaxService** - FA tax system integration

**Phase 3: Enhanced Features (Next 6 months)**
1. **Advanced Order Management** - Calculate, clone, update orders
2. **Locations Management** - Automated location setup
3. **Reporting & Analytics** - Business intelligence integration
4. **Configuration Management** - FA preferences system integration

#### 📊 **API Coverage Progress Tracking**

| Category | Coverage | Target | Status |
|----------|----------|--------|--------|
| Core Catalog/Inventory | 100% | 100% | ✅ Complete |
| Payment Processing | 60% | 100% | 🔄 In Progress |
| Customer Management | 0% | 100% | 🚨 Missing |
| Webhook Management | 0% | 100% | 🚨 Missing |
| Refunds & Voids | 20% | 100% | 🔄 In Progress |
| Advanced Features | 0% | 100% | 🚨 Missing |
| FA Native Integration | 30% | 100% | 🔄 In Progress |

**Overall Coverage**: 42% → **Target**: 85% (after Phase 1-2)

---

## Capabilities Provided by This Module

For inter-module communication, this module provides the following capabilities:

| Capability | Description | Methods |
|------------|-------------|---------|
| `export` | Export products from FrontAccounting to Square | `exportProducts`, `syncInventory` |
| `import` | Import orders from Square to FrontAccounting | `importOrders` |
| `payments` | Collect payments via Square Terminal | `createTerminalCheckout` |
| `config` | Configure Square API settings | `getSettings`, `saveSettings` |

**Constants Defined**:
- `KSF_SQUARE_MODULE_NAME` = `'ksf_FA_Square'`
- `KSF_SQUARE_CAPABILITIES` = `'export,import,payments,config'`

---

## For Other KSF Modules: Adopting This Pattern

To enable inter-module communication with other ksf modules, add these methods to your hooks class:

```php
<?php
class hooks_ksf_yourmodule extends hooks {

    // ... your existing methods ...

    // =========================================================================
    // INTER-MODULE COMMUNICATION METHODS
    // These allow other ksf modules to discover your module's capabilities
    // using FrontAccounting's built-in hook_invoke function.
    // =========================================================================

    /**
     * Gets all constants defined by this module.
     * 
     * Call from other modules:
     * hook_invoke('ksf_yourmodule', 'getModuleConstants', $data)
     */
    public function getModuleConstants(&$data, $opts = null) {
        $constants = [
            // 'KSF_YOURMODULE_PREFS' => KSF_YOURMODULE_PREFS,
            // Add your module's constants here
        ];
        $data['constants'] = $constants;
        return $constants;
    }

    /**
     * Gets all capabilities provided by this module.
     * 
     * Call from other modules:
     * hook_invoke('ksf_yourmodule', 'getModuleCapabilities', $data)
     */
    public function getModuleCapabilities(&$data, $opts = null) {
        $capabilities = [
            // 'your_capability' => [
            //     'description' => 'What this capability does',
            //     'methods' => ['method1', 'method2'],
            // ],
        ];
        $data['capabilities'] = $capabilities;
        return $capabilities;
    }

    /**
     * Checks if this module provides a specific capability.
     * 
     * Call from other modules:
     * hook_invoke('ksf_yourmodule', 'hasCapability', $data, ['capability' => 'export'])
     */
    public function hasCapability(&$data, $opts = null) {
        $capability = $opts['capability'] ?? $data['capability'] ?? null;
        if ($capability === null) {
            $data['has_capability'] = false;
            $data['error'] = 'No capability specified';
            return false;
        }

        $capabilities = []; // List your capability names here
        $hasCapability = in_array($capability, $capabilities);
        $data['has_capability'] = $hasCapability;
        $data['capability_checked'] = $capability;
        return $hasCapability;
    }

    /**
     * Generic responder for capability requests.
     * 
     * Call from other modules:
     * hook_invoke('ksf_yourmodule', 'respondToCapabilityRequest', $data, ['request' => 'constants|capabilities|has:export'])
     */
    public function respondToCapabilityRequest(&$data, $opts = null) {
        $request = $opts['request'] ?? $data['request'] ?? 'capabilities';
        $data['request'] = $request;
        $data['module'] = $this->module_name;

        switch ($request) {
            case 'capabilities':
                return $this->getModuleCapabilities($data, $opts);
            case 'constants':
                return $this->getModuleConstants($data, $opts);
            case (strpos($request, 'has:') === 0):
                $capability = substr($request, 4);
                return $this->hasCapability($data, ['capability' => $capability]);
            default:
                $data['error'] = 'Unknown request type: ' . $request;
                return null;
        }
    }
}
```

See `AGENTS_MODULE_COMMUNICATION_ADDENDUM.md` for the complete pattern that can be shared with other modules.

---

## BABOK Alignment: Square-Invoice Feature (v2.4.4)

### Business Requirements

| ID | Business Requirement | BABOK Task | Rationale |
|----|---------------------|------------|-----------|
| BR-SQ-001 | Enable remote payment collection via Square Invoices | Define Needs | Customers need to pay invoices remotely without visiting POS |
| BR-SQ-002 | Suppress FA auto-payment for Square-Invoice terms | Define Change Requirements | Prevents double-payment (FA auto-record + Square payment) |
| BR-SQ-003 | Auto-create Square Customers from FA debtors | Assess Risk | Eliminates manual customer setup in Square |
| BR-SQ-004 | Store FA↔Square mapping for payment matching | Define Requirements | Enables payment reconciliation on import |
| BR-SQ-005 | Support email/SMS delivery of Square Invoices | Assess Risk | Multiple delivery channels improve collection rates |

### Functional Requirements Traceability

| FR | Business Requirement | Stakeholder Need | Solution |
|----|---------------------|-----------------|----------|
| FR-SQ-007-001 | BR-SQ-001 | Remote payment collection | Hook-based interception of payment terms |
| FR-SQ-007-002 | BR-SQ-002 | Prevent double-payment | Set cash_sale=0 in db_prewrite |
| FR-SQ-007-003-006 | BR-SQ-001 | Automated invoice creation | SquareInvoiceService orchestrates API calls |
| FR-SQ-007-004 | BR-SQ-003 | Zero manual Square setup | Auto-create from FA debtor data |
| FR-SQ-007-007-008 | BR-SQ-004 | Payment matching | DAO stores bidirectional mappings |
| FR-SQ-007-009 | BR-SQ-005 | Multiple delivery channels | Configurable delivery method per term |
| FR-SQ-007-010 | BR-SQ-001 | Recurring payments | Card-on-file automatic charging |

### Stakeholder Analysis

| Stakeholder | Need | How Addressed | Risk |
|-------------|------|---------------|------|
| Sales Operator | Send invoices from FA | Payment term triggers Square Invoice | Medium: API failures after FA commit |
| Customer | Pay remotely | Email/SMS with payment link | Low: Square handles payment UX |
| Accountant | Reconcile payments | Mapping table enables matching on import | High: must prevent double-payment |
| Administrator | Configure destinations | FA_PaymentDestinations admin UI | Low: simple CRUD |

### Risk Analysis

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Square API fails after FA commit | Invoice posted but no Square Invoice | Low | Error logged, manual retry via CRM page |
| Customer not created in Square | Cannot publish Invoice | Low | Auto-create from FA debtor data |
| Double-refund (in-store + CC chargeback) | Financial loss | Medium | Dispute tracking in CRM, refund config toggle |
| Idempotency failure (duplicate Square Invoices) | Customer confused, reconciliation issues | Low | Idempotent mapping lookup before creation |

### Design Decisions

| Decision | Rationale | Alternatives Considered |
|----------|-----------|------------------------|
| Hook-based interception (db_prewrite/postwrite) | FA's existing extension mechanism, no core modifications | Direct FA API calls (too invasive), separate module import (too late) |
| Separate SquareInvoiceService class | SRP: single class for Square Invoice lifecycle | Inline in hooks.php (violates SRP), separate module (unnecessary coupling) |
| Two mapping tables (invoice_map + customer_mappings) | Separate concerns: invoice tracking vs customer linking | Single table (violates SRP), no customer mapping (can't create invoices) |
| Auto-create Square Customer | Zero-config for administrators | Require manual setup (friction), skip customer (can't publish) |

---

## BABOK Alignment: ISU Config Absorption (Refactor)

### Business Requirements

| ID | Business Requirement | BABOK Task | Rationale |
|----|---------------------|------------|-----------|
| BR-SQ-010 | Own all Square-specific GL/bank/payment config | Define Needs | ISU is generic staging; source modules own their config |
| BR-SQ-011 | Remove ISU coupling for Square config | Define Change Requirements | ISU should not know Square GL accounts |
| BR-SQ-012 | Standardize staging on ISU tables (remove dual-write) | Assess Risk | Two staging table sets create confusion |
| BR-SQ-013 | Config UI for import settings (GL, bank, payment methods) | Define Requirements | Admin configures Square import routing in Square module |

### Functional Requirements Traceability

| FR | Business Requirement | Stakeholder Need | Solution |
|----|---------------------|-----------------|----------|
| FR-SQ-016-001-003 | BR-SQ-010 | GL routing for Square imports | Settings.php stores GL accounts |
| FR-SQ-016-004-006 | BR-SQ-010 | Bank routing for deposits/transfers | Settings.php stores bank accounts |
| FR-SQ-016-007-010 | BR-SQ-010 | Payment method defaults | Settings.php stores payment methods |
| FR-SQ-016-011 | BR-SQ-013 | Admin UI for import config | pages/config.php import section |
| FR-SQ-016-012 | BR-SQ-010 | Pass config to ISU at processing time | ImportService passes $sourceConfig |
| FR-SQ-017-001-004 | BR-SQ-012 | Single staging table set | Remove square_staging_* references |
| FR-SQ-018-001-011 | BR-SQ-010 | Absorbed fields from ISU | Settings.php + config UI |

### Config Fields Absorbed from ISU

| ISU Field | Square Config | Purpose | Dead? |
|-----------|--------------|---------|-------|
| `square_gl` | `square_gl` | GL for card transactions | No |
| `cash_gl` | `cash_gl` | GL for cash transactions | No |
| `xfer_to_gl` | `xfer_to_gl` | Transfer destination GL | No |
| `square_bank` | `square_bank` | Bank for deposits | No |
| `xfer_to_bank` | `xfer_to_bank` | Transfer dest bank | No |
| `cash_bank` | `cash_bank` | Cash bank | No |
| `useCardAsBranch` | `useCardAsBranch` | PAN as branch | No |
| `allowSkuChange` | `allowSkuChange` | SKU editing gate | No |
| `default_pay_card` | `default_pay_card` | Card payment method | Yes |
| `default_pay_cash` | `default_pay_cash` | Cash payment method | Yes |
| `default_pricebook` | `default_pricebook` | Price book | Yes (dead) |

### Design Decisions

| Decision | Rationale | Alternatives Considered |
|----------|-----------|------------------------|
| Absorb ALL 11 fields including dead ones | Clean break; remove dead code later | Move only active fields (creates orphaned ISU fields) |
| Config in Settings.php (existing DTO) | Consistent with Square module pattern | New config table (unnecessary duplication) |
| Pass $sourceConfig at processing time | Loose coupling; ISU never imports Square | ISU reads Square config directly (tight coupling) |
| Remove square_staging_* tables | Single canonical staging set reduces confusion | Keep both (creates maintenance burden) |

---

## BABOK Alignment: ISU Repository Adapters (v2.4.5)

### Business Requirements

| ID | Business Requirement | BABOK Task | Rationale |
|----|---------------------|------------|-----------|
| BR-SQ-020 | Standardize Square staging on ISU repository interfaces | Define Design Options | Enables ISU's StagingService to process Square data polymorphically |
| BR-SQ-021 | Enable ISU to consume Square transaction/customer/payment data | Recommend Solution | Single processing pipeline across all import sources |

### Functional Requirements Traceability

| FR | Business Requirement | Stakeholder Need | Solution |
|----|---------------------|-----------------|----------|
| FR-SQUARE-ISU-001 | BR-SQ-020 | ISU processes Square transactions | TransactionRepositoryAdapter bridges Square DAO to ISU StagingTransaction |
| FR-SQUARE-ISU-002 | BR-SQ-020 | ISU processes Square customers | CustomerRepositoryAdapter maps ISU StagingCustomer to staging_customers table |
| FR-SQUARE-ISU-003 | BR-SQ-020 | ISU processes Square payments | PaymentRepositoryAdapter maps ISU StagingPayment to staging_payments table |
| FR-SQUARE-ISU-004 | BR-SQ-020 | ISU processes Square line items | LineItemRepositoryAdapter uses EAV for Square-specific attributes |
| FR-SQUARE-ISU-005 | BR-SQ-020 | Unified audit trail across sources | AuditLogRepositoryAdapter writes to ISU staging_log table |

### Stakeholder Analysis

| Stakeholder | Need | How Addressed | Risk |
|-------------|------|---------------|------|
| FA Administrator | Single import dashboard | All sources visible in ISU UI | Low: ISU provides unified view |
| Developer | Maintainable adapter layer | One adapter per entity type, testable in isolation | Low: unit + integration tests |
| Finance | Consistent data format | ISU models normalize source-specific fields | Medium: field mapping drift |

### Design Decisions

| Decision | Rationale | Alternatives Considered |
|----------|-----------|------------------------|
| Adapter pattern (ISU interfaces) | Polymorphic access; ISU never imports Square-specific code | Direct DAO coupling (tight), abstract base class (less flexible) |
| Use db_escape() not prepared statements | FA's db_query() doesn't support parameterized queries | PDO (requires FA core changes, incompatible with PHP 7.3 FA) |
| db_escape($val, true) for nullable columns | FA's db_escape(null) returns '' not SQL NULL; datetime columns reject '' | Modify FA core db_escape (too invasive) |
| Remove unused ksf_ModulesDAO parameter | Dead code; adapters use FA global db_* functions | Keep for future use (YAGNI) |
| Square-specific fields in raw_json/attributes | ISU models don't know Square fields; EAV preserves extensibility | Extend ISU models (violates Open/Closed) |

### Design: Adapter Architecture

```
ISU Module (ksf_FA_ImportStagingProcessing)
├── Contracts/
│   ├── TransactionRepositoryInterface
│   ├── CustomerRepositoryInterface
│   ├── PaymentRepositoryInterface
│   ├── LineItemRepositoryInterface
│   └── AuditLogRepositoryInterface
└── Models/
    ├── StagingTransaction
    ├── StagingCustomer
    ├── StagingPayment
    └── StagingLineItem

Square Module (ksf_FA_Square)
├── Staging/
│   ├── TransactionRepositoryAdapter ──implements──> TransactionRepositoryInterface
│   ├── CustomerRepositoryAdapter ────implements──> CustomerRepositoryInterface
│   ├── PaymentRepositoryAdapter ─────implements──> PaymentRepositoryInterface
│   ├── LineItemRepositoryAdapter ────implements──> LineItemRepositoryInterface
│   └── AuditLogRepositoryAdapter ────implements──> AuditLogRepositoryInterface
└── DAO/
    └── TransactionStagingDAO (used by TransactionRepositoryAdapter)
```

### Bugs Found During Integration Testing

| Bug | Root Cause | Fix |
|-----|-----------|-----|
| All 5 adapters fail to load | `\ksf_ModulesDAO` constructor param never used | Removed parameter |
| Customer/Payment/LineItem/AuditLog inserts fail | `db_query($sql, $params)` — FA has no prepared statements | Replaced with `db_escape()` |
| NULL datetime values produce '' not SQL NULL | `db_escape(null)` returns `''` not `NULL` | Added `$nullify = true` for nullable columns |
| Payment/Transaction `toModel()` crash | Models require `$source` in constructor | Pass `$row['source']` to constructor |

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-05-20 | KSFraser | Initial BABOK alignment for Square SDK v40 |
| 0.2 | 2026-05-21 | KSFraser | Updated to reflect current state, refactoring progress, and unified staging plan |
| 0.3 | 2026-05-21 | KSFraser | Updated current/future state, prioritization, and change strategy with Phase 1 completion and Phase 2 (Location Mapping FR-08) |
| 0.4 | 2026-05-22 | KSFraser | Added DAO layer, Service layer, and inter-module communication via hook_invoke pattern |
| 0.5 | 2026-05-23 | KSFraser | Updated with comprehensive Square API coverage analysis and FA integration opportunities |
| 0.6 | 2026-08-20 | KSFraser | Added BABOK alignment for ISU config absorption and staging ownership refactor |
| 0.7 | 2026-08-23 | KSFraser | Added BABOK alignment for ISU repository adapters (FR-SQUARE-ISU-001 through 005) |
