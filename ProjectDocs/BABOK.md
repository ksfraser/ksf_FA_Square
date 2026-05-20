# BABOK Alignment — ksf_FA_Square

## Overview

This document maps the ksf_FA_Square project to the **Business Analysis Body of Knowledge (BABOK 3.0)** knowledge areas, tasks, and techniques. Each requirement and design decision is traced to BABOK tasks to ensure complete coverage of the business analysis process.

---

## Knowledge Area 1: Business Analysis Planning and Monitoring

### Task: Plan Business Analysis Approach
| Element | Application |
|---------|-------------|
| Approach | Agile BA — iterative discovery with working software as primary deliverable. Requirements refined through working module demos. |
| Techniques | — Stakeholder List (2.5.1) <br>— Business Process Modeling (10.4) <br>— Scope Modeling (10.42) |
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
| Square SDK v40 API docs | Document Analysis (10.18) | Catalog of 43 API endpoints |
| Existing square.php (v2.4.0) | Interface Analysis (10.25) | Current capability map |
| Square Developer Docs | [https://developer.squareup.com/docs](https://developer.squareup.com/docs) | API capability inventory |
| CSV import module (pre-existing) | Document Analysis (10.18) | Requirements merge |

### Task: Conduct Elicitation
| Session | Technique | Result |
|---------|-----------|--------|
| API Capability Review | Document Analysis (10.18) | Mapped Square SDK endpoints -> Business needs |
| Code Review | Interface Analysis (10.25) | Identified gaps: InventoryApi, TerminalApi, CustomersApi |
| Contextual inquiry: FA workflow | Observation (10.33) | Understood invoice flow, stock management, debtor mgmt |

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

**Must Have (MVP)**
- FR-01.01 through FR-01.08: Product catalog export (existing + inventory)
- FR-03.01 through FR-03.10: Sales import from Square (existing)
- FR-06.01 through FR-06.06: Configuration

**Should Have (v2.0)**
- FR-02.01 through FR-02.06: Terminal payments
- FR-04.01 through FR-04.05: Customer sync

**Could Have (v2.1+)**
- FR-05.01 through FR-05.07: CSV import merging

**Won't Have (v3.0+)**
- Loyalty, gift cards, subscriptions

---

## Knowledge Area 4: Strategy Analysis

### Task: Analyze Current State
| Element | Current State |
|---------|---------------|
| Business Process | Manual: Products entered in both FA and Square. Sales reconciled manually from Square Dashboard CSV export. |
| Technology | Square SDK v3-era module, PHP 7.3 production, PHP 7.4 UAT, FA 2.4.3 |
| Pain Points | Dual data entry, manual reconciliation, no inventory sync, no integrated card payments |
| API Gap | Existing code uses Square v3 APIs. Current SDK v40 has 43 APIs — many new capabilities unused. |

### Task: Define Future State
| Element | Future State |
|---------|--------------|
| Product Sync | One-click push of FA inventory to Square catalog + stock counts |
| Payments | FA invoice -> Square Terminal checkout -> FA payment recording |
| Sales Import | Automated/scheduled pull of Square sales -> FA invoices |
| Customer Sync | Bi-directional customer matching and creation |
| Technology | modular PHP code following AGENTS.md conventions |

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
| Phase 1: Stabilize | Migrate existing square.php to SDK v40, refactor to modular structure | Current |
| Phase 2: Inventory Sync | Add InventoryApi integration for stock-on-hand push | Next |
| Phase 3: Terminal Payments | Add TerminalApi integration for card reader payments | Next+ |
| Phase 4: CSV Merge | Integrate existing CSV import module | Future |
| Phase 5: Customer Sync | Add CustomersApi integration | Future |

---

## Knowledge Area 5: Requirements Analysis and Design Definition

### Task: Specify and Model Requirements
| Artifact | Technique | Content |
|----------|-----------|---------|
| Requirements.md | User Stories / FR Specification | 39 functional requirements, 8 NFRs |
| RTM.md | Traceability Matrix (10.47) | FR -> UC -> Code -> Test |
| UML.md | UML diagrams (10.48) | Class, sequence, component diagrams |

### Task: Define Design Options
| Option | Description | Recommendation |
|--------|-------------|----------------|
| Monolithic (current) | Single square.php handles all actions | Rejected — violates SRP |
| Modular (recommended) | Separate files per API domain: catalog_api.php, inventory_api.php, terminal_api.php, customers_api.php, csv_import.php | **Recommended** — aligns with AGENTS.md |
| FA REST API alternative | Use FA's REST plugin for Square to call | Rejected — Square needs front-end for Terminal |

### Task: Recommend Solution
**Modular re-architecture** following AGENTS.md:
- `src/Services/` — Business logic (Square API wrappers)
- `src/Contracts/` — Interfaces for Square API adapters
- `src/Models/` — Value objects (Money, OrderItem, etc.)
- `src/Exceptions/` — Custom exceptions
- `pages/` — FA UI pages (existing square.php refactored)
- `includes/` — FA-specific glue code

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
| Limitation | Impact | Workaround |
|------------|--------|------------|
| Square sandbox vs prod differences | Test coverage gaps | Use sandbox + manual prod verification |
| FA stock moves don't trigger auto-sync | Stale inventory | Manual re-export trigger |
| PHP 7.3 limits SDK version to v40 | Miss v41+ improvements | Plan PHP upgrade |

---

## BABOK Techniques Applied

| Technique | BABOK Ref | Application |
|-----------|-----------|-------------|
| Business Process Modeling | 10.4 | FA invoice-to-payment flow |
| Data Flow Diagrams | 10.14 | Square <-> FA data movement |
| Data Modeling | 10.15 | staging tables, mapping tables |
| Document Analysis | 10.18 | Square SDK API reference |
| Functional Decomposition | 10.20 | Requirements -> Use Cases -> Code |
| Interface Analysis | 10.25 | square.php -> Square API surface |
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
