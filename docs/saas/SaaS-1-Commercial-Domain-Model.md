# SaaS.1 — Commercial Domain Model

**Status:** Proposed (SaaS.1, architecture only; **no migration is written in SaaS.1**) · **Date:** 6 October 2026 · **Part of:** [SaaS.1 Commercial Architecture & Gap Analysis](SaaS-1-Commercial-Architecture-Gap-Analysis.md)

This is the target data model for the commercial layer. For each entity it records:
- ownership and tenant scope;
- keys and uniqueness;
- status and effective dates;
- what is immutable and what may change;
- indexes;
- audit and retention.

It also records which entities from the starting hypothesis are **not** needed, and why.

## 1. Modelling rules (inherited from PeopleOS, not new)

| Rule | Source | Commercial consequence |
|---|---|---|
| Effective dating for history; never overwrite | ADR-0006; security invariants 16 | Plan versions, subscription items, entitlement overrides, tax profiles, supplier profiles and tax rules are end-dated and superseded, never edited |
| Published versions are immutable | ADR-0009 / ADR-0010 | A published plan version (prices, entitlements, trial and dunning terms) is frozen. A change is a new version |
| Append-only records for facts | audit, ledger, payroll entries | Transitions, usage events, payment events, issued invoices and credit notes are append-only |
| No soft deletes; status + audit | Architecture contract §14 | Commercial rows are never deleted while their retention runs; status columns carry the lifecycle |
| Integer money | — | `*_minor BIGINT` + `currency CHAR(3)` on every monetary row |
| Bigint primary keys; opaque external references | Codebase convention; ADR-0011 | Internal `id BIGINT`. Anything shown to a provider or in a URL uses an opaque `reference` (ULID), never the id |
| Additive, guarded migrations | Contract §14 | Every table here is new; existing tables gain nullable columns only (Gap Analysis §20) |

## 2. Where commercial records live (tenant scope)

There are three kinds of record:

| Kind | Examples | Tenant trait? | Read path |
|---|---|---|---|
| **Platform catalogue** | plans, plan versions, prices, plan entitlements, supplier profiles, tax rules | No `tenant_id` | Platform services; read-only to everyone else |
| **Platform-owned, tenant-keyed** (Markedge's commercial books about a tenant) | billing accounts, subscriptions, items, invoices, payments, refunds, overrides, snapshots, usage aggregates, lifecycle transitions, exports, deletion requests | `tenant_id NOT NULL`, **not** `BelongsToTenant` | Only through commercial services. Tenant-facing pages go through `TenantBilling` read services that always filter `tenant_id = TenantContext::id()`. Platform operators use control-plane services. Architecture test: no other class queries these models |
| **Platform-level, tenant unknown at arrival** | provider webhook events, signups before provisioning, provisioning runs | `tenant_id NULL` until resolved | Commercial / platform services only |

**Why not `BelongsToTenant`?**
- Markedge's invoices and payments are Markedge's records. They must survive deletion of the tenant's HCM data, for Markedge's own tax retention **[verify period]**.
- Platform operators read them across tenants all day. With the tenant trait, every such read would need `TenantContext::bypass()`, whose allow-list is deliberately short (security invariant 3).

This is the precedent `AuditEvent` already set: nullable or explicit tenant, reads scoped explicitly, writes only through one service. It is recorded as [ADR-0017](../architecture/decision-register.md#saas1-proposed-decisions), with an architecture test that confines reads to the commercial services and the tenant billing read model.

The **entitlement snapshot** is read on hot paths. It is cached by tenant and version (Entitlement doc §7), so this choice adds no per-request query.

## 3. Catalogue (platform)

### `plans`
| | |
|---|---|
| Purpose | A sellable offering: a base plan (Starter, Growth, Enterprise…) or an add-on (`kind = add_on`: extra AI capacity, advanced payroll, SSO…). There is no separate add-ons table |
| Keys | `id`; `code` UNIQUE (stable, e.g. `growth`, `addon_ai_plus`) |
| Columns | `kind` (base, add_on), `name`, `visibility` (public, private, legacy), `status` (draft, active, retired), `sort` |
| Mutable | name, visibility, status (`retired` stops new sales; existing subscribers keep their pinned versions) |
| Audit | Every change → `AuditRecorder` platform event |
| Retention | Forever (referenced by history) |

### `plan_versions`
| | |
|---|---|
| Purpose | The commercial terms in force for a plan from a date |
| Keys | `id`; UNIQUE `(plan_id, version)` |
| Columns | `version` int, `status` (draft, published, retired), `effective_from`, `effective_until` (nullable; for sale from/until), `trial_days`, `trial_retention_days`, `grace_days`, `dunning_policy` (json: steps), `min_commitment` (json: min units, min term), `markets` (json: country/region codes this version is sold in), `published_at`, `published_by` |
| Immutable after publish | Everything except `status` (→ retired) and `effective_until` (may end sales; may not shorten a period already sold) |
| Indexes | `(plan_id, status, effective_from)` |
| Audit | publish, retire → platform audit with reason |

### `plan_prices`
| | |
|---|---|
| Purpose | One price component of a plan version for a billing period and currency/market |
| Keys | `id`; UNIQUE `(plan_version_id, component, billing_period, currency, market)` |
| Columns | `component` (base, per_active_employee, per_user, per_admin_seat, ai_overage…), `billing_period` (month, year), `pricing_model` (flat, per_unit, tiered, volume, package), `unit` (meter key; null for flat), `unit_amount_minor`, `tiers` (json for tiered/volume), `included_quantity`, `minimum_quantity`, `currency`, `market` |
| Immutable | Always (belongs to a published version) |

### `plan_entitlements`
| | |
|---|---|
| Purpose | What the plan version grants: capability key → value |
| Keys | `id`; UNIQUE `(plan_version_id, capability)` |
| Columns | `capability` (catalogue key, Entitlement doc §3), `type` (module, feature, limit), `value_bool`, `value_int` (null = unlimited for limits), `limit_mode` (hard, soft), `trial_value_int` / `trial_value_bool` (nullable: the trial override) |
| Immutable | Always |

**Not required: `products`.** PeopleOS is a single product. A products table would have one row. If Markedge sells a second product, add it then; plans gain a nullable `product_id`. Recorded in Gap Analysis §8.

## 4. Billing account and tax identity (platform-owned, tenant-keyed)

### `billing_accounts`
| | |
|---|---|
| Purpose | Who pays for a tenant, and how |
| Keys | `id`; `reference` ULID UNIQUE; `tenant_id` INDEX (one account per tenant at launch; the model allows more, e.g. a group paying for several tenants later) |
| Columns | `legal_name`, `billing_email`, `currency`, `gateway` (razorpay, stripe, manual), `provider_customer_id` (UNIQUE with gateway, nullable), `collection_method` (automatic, invoice), `payment_terms_days`, `status` (active, closed) |
| Mutable | contacts, gateway (only with no open invoice), collection method (operator) |
| Audit | All changes; billing email changes notify the old address |
| Retention | Commercial retention period after the tenant ends **[verify]** |

### `billing_tax_profiles`
| | |
|---|---|
| Purpose | The customer's tax identity at a point in time |
| Keys | `id`; INDEX `(billing_account_id, effective_from)` |
| Columns | `registration_type` (regular, composition, unregistered, sez, overseas), `gstin` (nullable; validated format and check digit; state code derived), `legal_name`, `address_lines`, `city`, `state_code`, `postal_code`, `country_code`, `effective_from`, `effective_until` |
| Immutable | Rows are end-dated and superseded, never edited. An invoice stores a **snapshot**, so later changes never alter it |

### `payment_mandates`
| | |
|---|---|
| Purpose | A reusable payment instrument authorised at the provider (card e-mandate, UPI AutoPay, eNACH) |
| Keys | `id`; UNIQUE `(gateway, provider_mandate_id)` |
| Columns | `billing_account_id`, `type`, `display` (masked: brand + last 4, or bank + masked account), `status` (pending, active, cancelled, failed, expired), `max_amount_minor`, `expires_at` |
| Never stored | Card number, CVV, UPI PIN, bank credentials |
| Audit | Activation and cancellation |

## 5. Subscription (platform-owned, tenant-keyed)

### `subscriptions`
| | |
|---|---|
| Keys | `id`; `reference` ULID UNIQUE; INDEX `(tenant_id, status)`. **One live subscription per tenant**: a stored generated column `live_tenant_id = IF(status IN ('pending','trialing','active','past_due','suspended','expired'), tenant_id, NULL)` with a UNIQUE index (MySQL has no partial indexes) |
| Columns | `billing_account_id`, `status` (State Machines §3), `trial_starts_at`, `trial_ends_at`, `current_period_start`, `current_period_end`, `billing_period`, `cancel_at_period_end`, `scheduled_change` (json: target items at next renewal), `past_due_since`, `grace_ends_at`, `suspended_at`, `started_at`, `ended_at`, `end_reason`, `collection_method` |
| Status writer | `SubscriptionService` only (architecture test) |
| Indexes for sweeps | `(status, trial_ends_at)`, `(status, current_period_end)`, `(status, grace_ends_at)` |
| Audit | Every transition (plus §8 transition row) |

### `subscription_items`
| | |
|---|---|
| Purpose | What the subscription consists of: base plan version + add-ons, each pinned to its version and price |
| Keys | `id`; INDEX `(subscription_id, effective_from)` |
| Columns | `plan_version_id`, `plan_price_id`, `kind` (base, add_on), `quantity_mode` (fixed, metered), `quantity` (fixed), `meter` (metered: e.g. `employees.active`), `effective_from`, `effective_until` |
| Immutable | Rows are end-dated on change and a new row starts. An upgrade, downgrade or add-on change never edits a row |
| Grandfathering | Items keep their `plan_version_id`. A new plan version reaches existing subscribers only through an explicit, announced **plan migration** that end-dates old items and starts new ones at a renewal boundary (Gap Analysis §10) |

### `subscription_transitions`
Append-only. Columns: `subscription_id`, `tenant_id`, `from`, `to`, `trigger` (customer, payment_event, scheduler, operator, system), `actor_id`, `reason`, `provider_event_id` (nullable), `correlation_id`, `occurred_at`, `effective_at`. INDEX `(subscription_id, occurred_at)`. Never updated or deleted, enforced with the same immutable-builder pattern as audit rows.

### `checkout_sessions`
Keys: `reference` ULID UNIQUE (also the provider idempotency key); `(gateway, provider_session_id)` UNIQUE. Status: open, completed, expired. Expired after 24 h by the sweep. Holds no card data.

## 6. Invoicing and payments (platform-owned, tenant-keyed)

### `invoices` (one table for invoices, credit notes and debit notes)
| | |
|---|---|
| Keys | `id`; `reference` ULID UNIQUE; UNIQUE `(supplier_profile_id, document_type, financial_year, number)` |
| Columns | `document_type` (invoice, credit_note, debit_note), `references_invoice_id` (for notes), `billing_account_id`, `tenant_id`, `subscription_id`, `status` (State Machines §6), `number` (null in draft), `series`, `financial_year`, `issue_date`, `due_date`, `period_start`, `period_end`, `currency`, `subtotal_minor`, `discount_minor`, `taxable_minor`, `tax_minor`, `total_minor`, `amount_paid_minor`, `tds_claimed_minor`, `place_of_supply_state`, `supplier_snapshot` (json), `customer_snapshot` (json), `tax_rule_version_id`, `irn`, `irn_ack_at`, `signed_qr`, `pdf_path`, `finalized_at`, `voided_at` |
| Immutable after `issued` | Everything except `status`, `amount_paid_minor`, `tds_claimed_minor`, `pdf_path`, `irn*` (written once). Enforced by an immutable builder for issued rows |
| Indexes | `(tenant_id, issue_date)`, `(status, due_date)` for dunning, `(billing_account_id, status)` |
| Audit | finalize, void, write-off, every allocation |
| Retention | Commercial and tax retention **[verify: years under GST law]**. Survives tenant data deletion |

### `invoice_lines`
`invoice_id`, `line_no`, `description`, `sac`, `plan_price_id` (nullable), `subscription_item_id` (nullable), `period_start`, `period_end`, `quantity` (decimal(14,4) for prorations), `unit_amount_minor`, `amount_minor`, `discount_minor`, `taxable_minor`, `cgst_rate`, `cgst_minor`, `sgst_rate`, `sgst_minor`, `igst_rate`, `igst_minor`, `usage_aggregate_id` (nullable: the evidence for a metered quantity). Immutable with the invoice.

### `payments`
| | |
|---|---|
| Keys | `id`; UNIQUE `(gateway, provider_payment_id)` |
| Columns | `billing_account_id`, `tenant_id`, `checkout_session_id` (nullable), `status` (State Machines §7), `amount_minor`, `currency`, `method_display`, `failure_code` (internal set), `failure_message` (provider text, truncated, no PII), `succeeded_at` |
| Mutable | Status forward only, to a final state |

### `payment_allocations`
`payment_id`, `invoice_id`, `amount_minor`, `tds_claimed_minor`, `allocated_by`, `allocated_at`. UNIQUE `(payment_id, invoice_id)`. The sum of allocations ≤ payment amount; the invoice is paid when its allocations plus TDS cover the total. Append-only; reversal is a negative row with a reason.

### `refunds`
UNIQUE `(gateway, provider_refund_id)`; `payment_id`, `credit_note_id` (required), `amount_minor`, `status`, `reason`, `requested_by`, `approved_by` (dual control above a threshold, decision D-15).

### `dunning_attempts`
UNIQUE `(invoice_id, step)`; `scheduled_at`, `executed_at`, `outcome` (charged, failed, reminded, skipped), `payment_id`.

### `billing_provider_events` (platform-level)
UNIQUE `(gateway, provider_event_id)`; `type`, `status` (received, processing, succeeded, ignored, failed, retrying, dead_letter, reprocessed), `attempts`, `next_attempt_at`, `claimed_until`, `payload_encrypted` (purged after the retention window), `payload_sha256`, `billing_account_id` (resolved), `tenant_id` (resolved), `correlation_id`, `received_at`, `processed_at`, `last_error` (redacted).

### `commercial_supplier_profiles`, `commercial_tax_rules` (platform catalogue)
- **Supplier profiles:** Markedge's GSTIN(s), legal name, address, state code, LUT reference and validity. Effective-dated.
- **Tax rules:** `version`, `effective_from`, `effective_until`, `sac`, `rate`, `rounding_mode`, `rules` (json: zero-rating conditions), `verification_status` (illustrative, verified), `verified_by`, `verified_at`. Finalize refuses an unverified rule in production, the same gate as `compliance.enforce_verified_rules`.

## 7. Entitlements (platform-owned, tenant-keyed)

### `entitlement_overrides`
| | |
|---|---|
| Purpose | Enterprise or support grants beyond the plan: "allow 1,200 employees until 31 March", "enable SSO for the pilot" |
| Keys | `id`; INDEX `(tenant_id, capability, effective_from)` |
| Columns | `capability`, `value_bool` / `value_int`, `operation` (set, add), `reason` (required), `effective_from`, `effective_until` (required unless approved as permanent), `granted_by`, `approved_by` (dual control for limits above a threshold), `status` (active, revoked) |
| Immutable | Value and dates. Revocation is a status change with a reason; a correction is a new row |
| Audit | Grant and revoke → platform audit, visible to the tenant's owners in the billing page |

### `tenant_entitlement_snapshots`
| | |
|---|---|
| Purpose | The resolved, effective entitlement set per tenant for a date range: the only thing the hot path reads |
| Keys | `id`; UNIQUE `(tenant_id, version)`; INDEX `(tenant_id, effective_from)` |
| Columns | `version` (monotonic per tenant), `effective_from`, `effective_until`, `entitlements` (json: capability → value, mode), `sources` (json: subscription item ids, plan version ids, override ids), `hash`, `computed_at`, `computed_by` (trigger) |
| Derivation | Rebuilt by `EntitlementCompiler` whenever a source changes (subscription transition, item change, override grant or revoke, plan migration). Future-dated changes (a scheduled downgrade) produce a future row at compile time. Deterministic: the same sources give the same hash |
| Immutable | Rows are never edited. A recompile writes a new version and end-dates the previous one |
| Retention | Kept, as the answer to "what was this tenant entitled to on 3 March?" |

## 8. Metering (platform-owned, tenant-keyed)

### `usage_events`
| | |
|---|---|
| Purpose | Append-only consumption facts for meters that are not authoritative counts (AI requests and tokens, API calls, storage deltas, outbound notifications if billed) |
| Keys | `id`; UNIQUE `(tenant_id, meter, idempotency_key)` |
| Columns | `meter`, `quantity` (bigint), `occurred_at`, `recorded_at`, `source` (ai_gateway, api, storage…), `subject_type` / `subject_id` (nullable), `idempotency_key` (e.g. `ai_interaction:{id}`, `api_request:{request_id}`) |
| Volume | High (API calls). Monthly range partitioning when volume requires (P2). Raw events retained 13 months (proposed), aggregates kept |

### `usage_aggregates`
UNIQUE `(tenant_id, meter, period_type, period_start)`. Columns: `quantity`, `peak_quantity` (for count meters sampled daily), `last_event_id`, `computed_at`, `finalized_at` (frozen when an invoice references it). An invoiced aggregate is immutable. Late events after finalization land in the next period, and the line description says so.

**Count meters are not events.** Active employees, users, admins, locations and companies are counted from the authoritative HCM tables at enforcement time, and sampled daily into `usage_aggregates` (`period_type = day`) for billing on peak or average (Entitlement doc §8). They are not reconstructed from event streams.

## 9. Tenant lifecycle and data lifecycle (platform)

| Entity | Purpose | Keys / notable columns |
|---|---|---|
| `tenant_lifecycle_transitions` | Append-only transition log for the tenant machine (State Machines §2) | `tenant_id`, `from`, `to`, `trigger`, `actor_id`, `reason`, `correlation_id`, `occurred_at` |
| `signups` | Self-service signup before a tenant exists | `reference` UNIQUE; `email`, `email_verified_at`, `company_name`, `domain`, `country_code`, `region`, `timezone`, `currency`, `locale`, `plan_code`, `terms_version`, `privacy_version`, `accepted_at`, `ip`, `status` (started, verified, provisioning, completed, rejected, expired), `tenant_id` (when provisioned). PII: purged 90 days after completion or expiry (proposed) |
| `provisioning_runs` | Resumable provisioning workflow | UNIQUE `idempotency_key` (signup reference or operator request id); `tenant_id`, `status`, `step`, `steps` (json checkpoints), `attempts`, `last_error` |
| `tenant_domains` (named in blueprint §99) | Verified e-mail domains of a tenant: duplicate-company detection, SSO home-realm discovery, auto-join policy | UNIQUE `domain` where verified (one tenant per verified domain); `verification_method` (DNS TXT), `verified_at` |
| `tenant_exports` | Full-tenant export jobs | `tenant_id`, `requested_by`, `status`, `scope` (json), `archive_path`, `archive_sha256`, `encryption` (key reference), `expires_at`, `downloaded_at`, `size_bytes` |
| `tenant_deletion_requests` | Deletion workflow | `tenant_id`, `requested_by` (+ platform confirmation), `status`, `scheduled_for` (cooling-off), `executed_steps` (json), `certificate_path`, `completed_at` |
| `legal_holds` | Blocks deletion and purge for a tenant (or a scope) | `tenant_id`, `scope`, `reason`, `placed_by`, `placed_at`, `released_by`, `released_at` |

## 10. Entities considered and not adopted

| Hypothesis entity | Decision | Reason |
|---|---|---|
| `products` | Not now | Single product (§3) |
| Separate `add_ons` | Folded into `plans.kind = add_on` | Same versioning, pricing and entitlement shape |
| Separate `credit_notes` / `debit_notes` tables | Folded into `invoices.document_type` | Same numbering, tax and immutability rules; one series table |
| `CommercialEvent` (a generic event log) | Not adopted | It would be a second audit system. "Why" questions are answered by the transition tables plus `AuditRecorder` events with correlation ids ([ADR-0024](../architecture/decision-register.md#saas1-proposed-decisions)) |
| `PaymentMethod` storing instrument details | Replaced by `payment_mandates` holding provider references and masked display only | PCI scope |
| `WebhookEvent` (generic) | `billing_provider_events` | Outbound webhooks (`webhook_deliveries`) and tenant inbound integration events (`inbound_events`) already exist with different trust models; provider events are platform-level |
| `UsageAggregate` for counts as event sums | Counts sampled from authoritative tables | Avoids drift between an event stream and the employee table (§8) |

## 11. Existing tables touched (by later phases, additively)

| Table | Change | Why |
|---|---|---|
| `tenants` | Add the lifecycle statuses (State Machines §2) in the same string column. Add `owner_user_id`, `region` semantics (Target Architecture §10), `deletion_scheduled_for` (nullable). Keep `tier`, `trial_ends_at` read-only for one release, then deprecate | Separation of tenant and subscription state |
| `tenant_features` | Unchanged. Becomes the **operational** toggle layer under entitlements (Entitlement doc §2) | Kill switches and tenant opt-out remain |
| `users` | Possibly `is_billing_contact`; nothing else | Seats are derived, not flagged |
| `api_keys` | None. API usage is metered by events keyed on request id | — |
| `ai_interactions` | None. AI usage events are written from the gateway, keyed on interaction id | — |
| `audit_events` | None. New action names (`SUBSCRIPTION_*`, `INVOICE_*`, `ENTITLEMENT_*`, `TENANT_LIFECYCLE_*`) and module `commercial` | Reuse |
