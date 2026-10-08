# SaaS.7 — FINAL REPORT

**Phase:** Billing, Tax (GST) & Payments: global-ready, with India GST as one jurisdiction · **Date:** 7 October 2026

Supporting documents:

| Document | Content |
|---|---|
| [SaaS-7 Baseline](SaaS-7-Baseline.md) | Discovery at `1ee7106`: what exists, earlier decisions, reconciliation with the international addendum, business vs implementation decisions (B-1 to B-16, I-1 to I-9), the boundary, the design, international readiness, implementation notes |
| [Decision register](../architecture/decision-register.md#saas7-decisions) | ADR-0042 to ADR-0048 |
| [Security invariants](../architecture/security-invariants.md) | 53–58 |
| [Architecture note](../architecture/saas-7-billing.md) | The developer's map: shape, rules for code, locks, adding a jurisdiction, test gotchas |
| [Browser evidence](evidence/SaaS-7-browser-validation.json) | Browser checks and axe results |
| [Operations runbook](../operations/queue-and-scheduler.md) | `peopleos:billing:provider-events`, `ApplyProviderEvent` |

## 1. Executive summary

**Status: BLOCKED at the business-decision boundary.** Everything that needs no business, tax or legal decision is built and proven:
- money and currency;
- markets and versioned prices;
- billing terms, and the answer to "what price applied";
- billing profiles with generic tax registrations;
- a jurisdiction-neutral tax engine, with India GST as a jurisdiction module;
- invoices with gap-free numbering, immutable snapshots and localised presentation;
- a provider-neutral payment boundary, with operator-recorded bank transfers and a non-production sandbox;
- verified, idempotent, replay-safe webhooks;
- exact-match reconciliation.

These parts are deliberately stopped, as your stop conditions require:

| Not built | Waits on |
|---|---|
| **Billing periods and their calculation** (the amount for a period), invoice generation from subscriptions, and its scheduler | Pricing basis, billable quantity, intervals offered, anchoring and proration (B-1 to B-3) |
| A **real, issued invoice** | Markedge's selling entities and registrations, and a tax-reviewed India rule: rate, SAC, place of supply, rounding, series format (B-7, B-8) |
| A **real payment provider** | B-10 |
| **Refunds and credit notes** | B-12 |
| **Partial payments and customer withholding** | B-11 |

No price, rate, registration, provider or policy value was invented. In tests and the disposable UI walkthrough, every such value is fictional.

**Global by construction, honest by design:**
- Currency is first-class (ISO 4217, 0, 2 or 3 decimals).
- Prices vary independently by market.
- The billing jurisdiction is explicit on each billing profile.
- Tax runs regime → determiner → verified rule → generic tax lines; VAT and sales tax run through the same code in tests.
- Unsupported jurisdictions refuse rather than guess.
- No jurisdiction is ever reported as legally "supported" by software.
- Billing never authorises: a failed payment or an unpaid invoice restricts nothing.

## 2. Starting commit

`1ee7106` (docs: SaaS.6 commercial subscription and trial lifecycle report), branch `feature/oct_1_phase_1`, clean tree.

## 3. Ending commit

The documentation commit that adds this report (§35). Its hash cannot be written inside itself; see `git log`. The SaaS.7 code was committed by the repository owner as `62e89c7` ("update phase 1") during the phase; it is kept as is (no history rewrite). Nothing is pushed, merged or deployed.

**Process notes:**
- The repository owner committed the work in progress as `62e89c7` during the phase; later changes are separate commits on top (§35).
- The session's scratchpad was cleared mid-phase. The validation tooling (UI fixtures and walkthrough, performance probe, persona smoke, WebKit launcher, MySQL client configuration) was rebuilt from the session record; no result was carried over without being re-run, except the mutation results (§27), which were final before the reset.
- The 8090 showcase server stopped with the reset. It was restarted only after the showcase had been backed up and migrated (§17 of the baseline; §21 here).
- During development a SQLite command created a stray file named `hcm` in the project root (the SQLite path fell back to the database name). It was identified as a fresh file created moments earlier, removed, and never committed; `database/database.sqlite` was untouched.

## 4. Discovery

Full record: [Baseline](SaaS-7-Baseline.md) §1–§4.

- **No commercial money code existed.** Every hit for tax, payment, currency, PAN, invoice or webhook concerned the tenant's own employees (payroll, statutory compliance, compensation, assets) or tenant integrations.
- **Existing primitives are not reusable for billing as they are:**
  - HR money is `decimal` + float `round()`;
  - `NumberSequences` is tenant-scoped with calendar years;
  - the inbound integration hub needs a tenant API key;
  - every queued job must be tenant-aware;
  - there is no PDF library, no platform settings store and no GST state codes.
- **The reusable patterns:**
  - inbound-event idempotency (unique key, payload hash, leased claim, encrypted payload);
  - statutory rule verification (maker-checker, refused in production while unverified);
  - the two audit chains;
  - signed temporary routes.
- **Decisions:** no D-decision of SaaS.1 was ever approved (recommendations only).
  - ADR-0037 (accepted) forbade a price table until the price decisions existed.
  - The international addendum, received during discovery, required the price *structure* (market × currency × versioned price × interval) and a global tax architecture.
  - Its conflicts were only with SaaS.1 **proposals** (GSTIN-shaped profiles, CGST/SGST/IGST invoice columns, "INR only"), which it supersedes. **No conflict with an accepted decision**; the price values stay decisions.

## 5. Billing architecture

```
Platform operator ──► Platform › Billing catalogue · Tax & invoicing · Billing accounts · Invoices · Payments
                            │ (each action: service guard OperatorChange = operator + reason; BillingAudit)
     ┌──────────────────────┼─────────────────────────────────────────────────────────────┐
     ▼                      ▼                                                             ▼
 Billing (catalogue)    Billing (tenant, fail-closed)                                  Payments
 markets, prices,       billing profiles · billing terms ──reads──► Subscriptions      provider contract, payments,
 price versions,        invoices + lines + tax lines                 (timeline)         provider events (platform),
 supplier profiles,          │ issue: series lock, profiles in force, snapshots         reconciler, webhook intake,
 number series               ▼                                                          tenant-aware job, sweep
                         Tax (jurisdiction-neutral): regime → determiner → verified rule → calculator
                              └─ Jurisdictions/India (GST)
 Support/Money: Currency catalogue · Money (int minor units) · MoneyFormatter (intl display)
```

The dependencies run Payments → Billing → Tax → Support/Money, and Billing reads subscriptions and published plan versions. Architecture tests prove the boundaries:
- nothing outside these contexts uses them except their five pages, the webhook controller and the sweep command;
- Tax depends on none of Billing, Payments, Subscriptions, Entitlements, Payroll, People or Compliance;
- Billing never reaches into Payments.

## 6. Pricing architecture

- **Market** (`billing_markets`, platform):
  - a permanent code and currency (ISO 4217);
  - the selling Markedge entity and a display locale;
  - the countries it is meant for (informational; the billing jurisdiction is on the profile).
- **Price** (`plan_prices`):
  - one published plan version × one market × one interval (`month` or `year`, representable, not offered);
  - a basis (`flat` or `per_active_employee`, representable; decision B-1).
- **Price version** (`plan_price_versions`):
  - a draft becomes published from a date (today or later, after the previous one) and is immutable, or is retired;
  - one draft at a time and one version per start date (generated unique columns);
  - currency stored explicitly; amount ≥ 0 in minor units.
- **Independence:** each market's versions are its own. Changing INR never touches USD or EUR (tested).
- **"What price applied to this subscription on that day?"** answers from `subscription_billing_terms`:
  - the operator pins the version on sale, for the subscription's plan version in force and in the tenant's billing market;
  - rows are painted like plan assignments; nothing re-prices a subscriber automatically;
  - a later plan change without a re-pin is reported as inconsistent, never silently re-priced (`BillingTerms::applicableOn`).
- **Future pricing page:** `BillingCatalog::catalogue(market, day)` answers "what does PeopleOS cost in my market?" without touching the plan catalogue (no page is built).
- **Price never reaches entitlements:** decisions are identical with and without prices and terms (test, ADR-0037 kept).

## 7. Billing-period architecture

**Blocked; not built.** A billing period (tenant, subscription, plan and price version, interval, start, end, currency, amounts, status, invoice) is fully specified by the design. Calculating its amount requires:
- **B-1:** the pricing basis of each plan;
- **B-2:** what "active employees in a period" means and its measurement history (metering does not exist);
- **B-3:** the intervals offered, calendar or anniversary periods, and proration on mid-period changes.

Building a table without a lawful writer would create dead data, and inventing any of these would be a commercial decision. The invoice drafting service accepts priced lines, and is the entry point the billing calculation will use once these decisions exist.

## 8. Invoice architecture

- **States:** `draft → issued → paid`, or `draft → discarded`. Overdue is derived from the due date and never restricts anything.
- **Draft:**
  - priced lines in the market's currency: whole quantities and integer minor units;
  - one tax category per invoice (SaaS.7);
  - optional idempotency key; the same key returns the same draft.
- **Issue** (operator, reason), in one transaction under the invoice lock and the series lock:
  - the customer profile in force (it must be the draft's market) and the supplier profile of the market's entity;
  - the tax quote (fail closed) and calculation;
  - the next number from the open series covering the day;
  - the totals and the snapshots (market, supplier, customer, tax determination with rule version and verification reference, totals by tax type);
  - audit on both chains.

  Issuing twice changes nothing.
- **Numbering:**
  - an explicit platform series per Markedge entity: prefix, date window (e.g. a financial year chosen by finance), padding;
  - open series never overlap;
  - a jurisdiction maximum length (India: 16 characters, **pending tax review**) is enforced at creation and allocation;
  - unique `(series_id, sequence)` and `(supplier_entity, number)`;
  - no max + 1: allocation under the series row lock inside the issuing transaction, so a refused or rolled-back issue gives its number back.
- **Presentation:** a structured document (header, parties, lines, tax summary, totals) plus labelled rows contributed by the regime (India: GSTINs, place of supply with state code, supply type, SAC). Money is formatted for the market's locale; issued invoices are presented from their snapshot only. PDF and e-invoicing are deferred (B-8).
- **No hand-drafted invoices:** no page creates drafts, because ad-hoc amounts would be a second, unapproved source of prices.

## 9. GST/tax architecture

| Element | Implementation |
|---|---|
| Regimes | `IN_GST`, `EU_VAT`, `GB_VAT`, `AE_VAT`, `US_SALES_TAX`, `CA_SALES_TAX`, `AU_GST`, `SG_GST` (code-owned; tax types per regime) |
| Treatments | `standard`, `zero_rated`, `exempt`, `reverse_charge`, `out_of_scope`; non-standard outcomes may carry no tax lines |
| Determination | Per regime (`TaxDeterminer`: its outcome keys; treatment, outcome and place of supply, with the basis in words). Only India is built |
| Rules | `tax_rules`: regime, country, optional subdivision, tax category, version, effective date, outcomes → components (type, exact rate), rounding mode and stage, classification (e.g. SAC). States: draft → review → verified by an operator other than the author or submitter (with the tax-review reference) → retired. Immutable once submitted. Outcome keys must be the determiner's |
| Engine | Supplier country → regime → determiner → verified rule in force on the tax point → components → pure calculator. Every gap throws `TaxUnavailableException` and the invoice is refused |
| India GST | ISO 3166-2 states mapped to GST codes (former ISO codes as aliases). GSTIN format, Luhn mod-36 check character and state match. Same state → `intra_state` (CGST + SGST); same union territory without legislature → `intra_union_territory` (CGST + UTGST); otherwise `inter_state` (IGST). Place of supply = the recipient's state on record (GSTIN state for registered customers, billing address otherwise), **pending the tax review that verifies the rule**. Exports, SEZ and UIN customers are refused (zero-rating not configured). No rate or SAC is shipped |
| Snapshot | Each tax line: regime, jurisdiction, tax type, treatment, rate, taxable amount, tax, currency and rule id. The invoice snapshot holds the determination, the rule (version, effective date, verification reference, rounding) and the classification |

## International readiness

| Topic | SaaS.7 |
|---|---|
| Supported currencies | Catalogue: INR, USD, EUR, GBP, AED, SGD, AUD, CAD, CHF, JPY (0 decimals), BHD and KWD (3 decimals). Technical support only: a currency is sold only through a market an operator creates |
| Currency model | ISO-4217 code (never a symbol) on every price version, billing terms row, invoice, invoice line, tax line and payment; minor units from the catalogue only; integers, never floats; mixed currencies refused |
| Pricing by market | Plan version × market × interval, each with independent immutable versions. Changing USD never changes INR (tested); invoices copy amounts, so price changes never rewrite them |
| Billing jurisdiction | Explicit on the billing profile: country, subdivision, B2B/B2C, registration. Never derived from IP, locale, currency, server or employee location, nor from the tenant's operating country |
| Tax abstraction | Regime → jurisdiction → verified rule → determination → generic tax lines. No GST-specific column anywhere outside the Tax domain (architecture test) |
| GST implementation | India jurisdiction module (states, GSTIN, determination, presentation). It becomes active only when a verified rule and a registered supplier exist |
| VAT readiness | Regimes, the EU VAT ID, UK VAT and UAE TRN identifiers, and reverse-charge and zero-rated treatments are representable. A test-only UK VAT determiner issues GBP invoices with VAT lines, and EUR reverse-charge invoices with no tax lines, through the same code |
| Sales-tax readiness | `US_SALES_TAX` with subdivision-level rules and STATE/COUNTY/CITY/DISTRICT components. A test-only determiner issues USD invoices with stacked components |
| B2B / B2C | `customer_type` is explicit and required. A consumer has no business registration; a business is registered or not |
| Tax registration | Generic type, value, issuing country (validated), validation status: format-checked for GSTIN only, others "not validated". Effective dating through the profile version; no live authority lookups |
| FX | **Not built and not needed** (option A: billing currency = payment currency). No billing, tax or payment code reads an exchange rate (architecture test). A future FX design must snapshot rate, source, timestamp and method on the invoice; payments record an optional provider settlement amount and currency without using it |
| Payment providers | Provider-neutral contract and generic method types (card, bank transfer, direct debit, wallet, local, other); no Indian method assumed. Provider fields stay in adapters and encrypted event payloads |
| Invoice localisation | Presentation data plus per-regime rows; money formatted by the market's display locale (`₹1,00,000.00`, `$1,250.00`, `1.250,00 €`, `£1,250.00`) |
| Compliance boundary | Computed status per jurisdiction: `not_supported`, `pending_tax_review`, `configured`. `supported` is never computed: it needs tax and legal approval outside software. `not_applicable` is reserved |
| Unsupported or unconfigured jurisdictions | Issue is refused with the reason (e.g. "US sales tax is not supported yet (architecture-ready …)"); no default tax |
| Jurisdiction support matrix | Platform › Tax & invoicing. Per jurisdiction (India, EU, UK, UAE, US, Canada, Australia, Singapore): currency, regime, registration concept, B2B/B2C, determination, invoice requirements, payment availability and computed status. Countries not listed have no regime and are not supported |
| Expansion path | A determiner (+ validator, presentation) under `Tax/Jurisdictions/{Country}`, registered in `TaxRegistry`, plus rules verified by tax review. Billing, invoices, tax lines and payments are unchanged |

## 10. Payment architecture

- **`payments` (tenant-owned):**
  - one attempt to pay one invoice through one provider;
  - amount and currency fixed;
  - the provider reference is written once and is unique per provider;
  - a unique idempotency key;
  - generic method; optional settlement amount and currency (recorded only);
  - status `initiated → pending → succeeded | failed | cancelled` (forward only, final states fixed);
  - reconciliation `unreconciled → matched | exception → resolved`.
- **One open provider payment per invoice:** generated unique column. Operator-recorded transfers never hold the slot, so a bank transfer received while a card link is open is still recorded.
- **Operations** (operator, reason):
  - initiate: the exact invoice total, a provider that takes the currency; the provider call runs outside database locks and is idempotent on the key;
  - record a bank transfer: recorded and reconciled in one transaction; it holds what was actually received;
  - refresh from the provider (server-side fetch);
  - resolve an exception with a note.
- **No public surface:** no checkout, no tenant payment page and no return URL in SaaS.7.

## 11. Provider abstraction

- **Contract:** `PaymentProvider` defines key, label, currencies, methods, `startsPayments`, `acceptsWebhooks`, `start` (idempotent), `fetch`, `verifyWebhook` (raw body + headers) and `interpret` (→ normalised `ProviderPaymentUpdate`).
- **Providers:**
  - `ManualBankTransferProvider`: operator-recorded; no start, fetch or webhooks;
  - `SandboxProvider`: test mode, deterministic references, HMAC-SHA256 signature over `timestamp.body` with a timestamp tolerance. `ProviderRegistry` enables it only when configured and **never in production** (tested).
- **No real adapter:** choosing Razorpay, Stripe or another provider per market is decision B-10.
- **Replaceability:** a provider can be replaced without touching invoices, subscriptions, tax, tenant lifecycle or entitlements, because provider names appear only in the providers and the registry (architecture test).

## 12. Webhook security

`POST /webhooks/billing/{provider}` sits outside the web and API groups:
- no session, CSRF or API key;
- rate-limited per IP (configurable, 120/min);
- a body limit (64 KiB).

| Threat | Control | Proof |
|---|---|---|
| Forgery | Constant-time HMAC over the **raw** body; unknown or non-webhook provider → 404; invalid → 401, logged with provider, reason and request id only (nothing stored or audited) | Forged, unsigned and wrong-secret webhooks → 401, no event row, payment unchanged (test); mutation F11 |
| Replay | Timestamp tolerance (300 s), and the unique `(provider, event_id)`. A re-signed replay of a known event is a no-op; the same id with a different body → 409 and an alert | Stale → 401; duplicate → `duplicate`; conflict → 409 (test); mutation F12 |
| Duplicate processing | Event row unique; leased claim; the reconciler ignores repeats and out-of-order outcomes; job unique per event | Duplicate deliveries at once → one event, one success (MySQL race 3); job re-run → no change (test) |
| Tenant spoofing | The tenant comes only from the payment found by the verified provider reference; nothing in the payload names one. Events store `resolved_tenant_id` | Unknown reference → exception, resolved later by the sweep (test) |
| Amount and currency tampering | Compared with the payment and invoice exactly; any difference is an exception, never a settlement | Tests; mutations F07, F08 |
| Payload exposure | Payload encrypted at rest (`encrypted:array`), hidden, never shown on pages; only status and outcome are displayed | Raw DB value contains no reference (test); browser check |
| Browser trust | No billing route outside the admin panel except the webhook | Route assertion (test) |

## 13. Reconciliation

`PaymentReconciler::apply()` takes the invoice lock, then the payment lock (always in that order).

1. **Repeats and late arrivals:** the same state again → `duplicate`; an outcome after a final one → `out_of_order` (ignored).
2. **Pending, failed or cancelled:** recorded and audited; the invoice is unchanged.
3. **Succeeded:** the invoice is settled (`paid`, `paid_by_payment_id`) only if the amount and currency equal the payment's and the invoice's exactly and the invoice is issued and unpaid. Otherwise the payment is `succeeded` with a reconciliation exception, and the invoice is unchanged:
   - `amount_missing`;
   - `currency_mismatch`;
   - `amount_mismatch` (partial or over payment, e.g. customer withholding, decision B-11);
   - `invoice_already_paid` (a duplicate payment);
   - `invoice_not_payable`.
4. **Exceptions:** operators resolve them with a note (e.g. "refunded by bank outside PeopleOS"). There are no credit notes or refunds yet (B-12).
5. **The sweep** (`peopleos:billing:provider-events`, every 5 minutes):
   - re-routes events that arrived before their payment's reference was stored (within 2 days);
   - resets expired leases;
   - re-dispatches failed events below 5 attempts.

## 14. Currency/money arithmetic

- **`Money`** (`app/Support/Money`):
  - immutable integer minor units plus a `Currency` (exact parse, refusing extra decimals);
  - `plus` and `minus` (same currency only), `times(int)`, `percentage(rate, mode)` (exact decimal, rounded once);
  - range-checked to signed 64-bit; `toDecimal`.
- **Arithmetic:** `brick/math` (a Laravel dependency) for exact decimals.
- **`MoneyFormatter`:** display only. It shows exactly the currency's decimals, and falls back to "CODE 123.45" beyond magnitudes a double represents exactly.
- **Enforcement:** an architecture test forbids `(float)`, `floatval`, `round(`, `number_format(` and float parameters in Billing, Tax, Payments and Support/Money; MoneyFormatter's bounded display cast is the one exception.
- **Tested:** 0.1 + 0.2 = 0.30, a thousand paise sum exactly, mixed currencies refused, overflow refused.

## 15. Rounding

| Stage | Rule |
|---|---|
| Line amount | Whole quantity × integer unit amount: exact, no rounding |
| Tax | Per line and per component, rounded once to the currency's minor unit with the **rule's** mode (`half_up` or `half_even`); the stage is `line` (other stages refused) |
| Invoice tax | The sum of the rounded component amounts |
| Invoice total | Subtotal + invoice tax, exact |

**Tested:**
- half-up vs half-even at 4.5 paise;
- 0.45 paise → 0;
- 8.875 % in USD;
- JPY and BHD;
- 0 % and 100 %;
- multiple lines;
- stacked components.

Which rounding mode a jurisdiction uses, and whether invoice-level rounding is needed, are tax decisions (B-8). The rule records the mode; the reviewer verifies it.

## 16. Financial immutability

| Record | Guard |
|---|---|
| Issued invoice | Only `status → paid`, `paid_at`, `paid_by_payment_id`; anything else, deletion included, throws (tested for total, tax, subtotal, currency, number, snapshot, status, issue date) |
| Invoice lines, tax lines | Never updated or deleted |
| Published price version | Only `published → retired`; amount, currency and start fixed |
| Supplier and billing profile versions | Never updated or deleted (new version instead) |
| Tax rule | Content fixed once submitted; status only forward; never deleted |
| Number series | Prefix, window and padding fixed; the sequence only increases |
| Payment | Invoice, amount, currency and idempotency key fixed; provider reference written once; status and reconciliation only forward |
| Provider event | What was received (id, type, payload, hash) fixed |

Corrections after issue need credit or debit notes (B-12); none can be made by editing.

## 17. Security

- **Operators only:**
  - every mutation runs `OperatorChange::assert()` in the service: `isPlatformAdmin()` (flag and no tenant; MFA enforced per request since SaaS.2) and a reason of 5 to 500 characters;
  - refused for employees, tenant administrators, a tenant user flagged as an operator and a tenantless non-operator, in every billing, tax and payment service (test, mutation F20).
- **Maker-checker for tax rules** (mutation F17).
- **Pages** are refused (403) to every tenant user; there is no tenant-facing billing page. Tenant tax identifiers are never shown to tenant users.
- **No secrets in code.** Sandbox secrets come from the environment only; the diff was scanned for keys, tokens, private keys and `.env` files: none.
- **Session behaviour** is unchanged; financial operations never touch sessions or authorisation.

## 18. Tenant isolation

- **Fail-closed:** billing profiles, terms, invoices, lines, tax lines and payments are `BelongsToTenant`. Tenant A sees none of tenant B's records (counts and direct finds), and nothing is visible without a bound tenant (test, mutation F14).
- **Cross-tenant reads:** only `BillingDirectory` and `PaymentDirectory` (operator lists of headers and names, bounded), plus `ProviderEvents` (reference resolution) are allow-listed.
- **Platform records:** the catalogue, rules, series and provider events. A provider event names its tenant only as `resolved_tenant_id` from a verified reference.
- **Queued work:** `ApplyProviderEvent` is a `TenantAwareJob` bound to the resolved tenant. It is the only `RunsForSuspendedTenants` job (exact allow-list).
- **Development finding:** fail-closed scoping caught two read paths (draft readiness, presentation) that read lines outside the invoice's tenant. Both now bind the tenant.

## 19. Audit

- **Two chains:** platform catalogue changes go on the platform chain; tenant records go on the tenant chain and, naming the tenant, on the platform chain.
- **27 actions:**
  - markets, prices and price versions;
  - supplier profiles, series, tax rules (drafted, submitted, verified, retired);
  - billing profiles and terms;
  - invoices (drafted, issued, discarded, paid);
  - payments (initiated, recorded, pending, succeeded, failed, cancelled, reconciliation exception, exception resolved).
- **Each event carries:** actor (null for provider or system, with `trigger`), reason, before → after, effective date, and a correlation or idempotency key (invoice reference, payment key, `provider:event_id`, `manual:<bank reference>`).
- **Proof:** chains verified valid in the security test and after every MySQL race; audit rows are immutable.

## 20. Queue/scheduler behavior

| Item | Behaviour |
|---|---|
| `ApplyProviderEvent` | Tenant-aware, unique per event, 1 try, leased claim, idempotent reconciler; runs for suspended tenants (money already moved) |
| `peopleos:billing:provider-events` | Every 5 minutes, `withoutOverlapping()->onOneServer()`. Platform-level: iterates events, not tenants. Idempotent and safe if delayed or repeated (tested twice in a row). Listed in the runbook |
| No billing-run scheduler | Blocked with the billing calculation (§7) |

## 21. Legacy tenant strategy

- **Nothing is created** by the migration or by any service for a tenant an operator has not configured: no profile, market, terms, price, invoice, payment or tax data (tested). In the showcase, all 13 SaaS.7 tables stay empty after migration.
- **A tenant without a billing profile cannot be invoiced** (refused with the reason).
- **Untouched:** legacy trial metadata, SaaS.6 subscriptions, SaaS.3–5 entitlements and HCM behaviour. Recording a billing profile writes no entitlement row.

## 22. UI

| Page (Platform group) | Content | Actions |
|---|---|---|
| Billing catalogue | Markets, prices with versions and what is on sale today, currency catalogue | New/edit market, new price, draft/publish/retire amount |
| Tax & invoicing | Jurisdiction support matrix with computed status, selling entities, tax rules with verification, number series | Record supplier entity, draft/submit/verify/retire rule (outcome inputs generated from the determiner), new/close series |
| Billing accounts | All tenants' market, customer type, jurisdiction and open invoices; for one tenant: profile versions, billing terms with today's applicable price, invoices, payments | Record billing profile, set billing terms |
| Invoices | All invoices (status filter, overdue badge); one invoice as a document; draft readiness ("cannot be issued today: …") | Issue, discard draft, start payment, record bank transfer |
| Payments | Payments (exceptions filter), provider events (status and outcome only); one payment | Check with provider, resolve exception |

Every action is visible only when legal, needs a reason, and shows its service's refusal as a notification. There is no public checkout, pricing page or signup UI.

## 23. API/webhooks

The only new route outside the admin panel is `POST /webhooks/billing/{provider}` (`billing.webhooks`), described in §12. No billing API, return URL or tenant endpoint was added.

## 24. Database/migrations

One additive migration (§34) creates 13 tables.

- **Database constraints:**
  - unique invoice sequence per series, and number per entity;
  - one live billing term per subscription and start date;
  - one draft and one start date per price;
  - unique price per plan version × market × interval;
  - unique profile version per tenant;
  - unique rule version per scope;
  - unique series prefix per entity;
  - unique provider reference per provider;
  - unique payment idempotency key;
  - one open provider payment per invoice;
  - unique provider event per provider.
- **Foreign keys:** tenant keys are restricted on delete (financial records are retained; deletion and retention belong to SaaS.9 / D-8).
- **MySQL 8.4 chain:** migrate, rollback and reapply are clean (§26). The showcase was migrated after a backup.

## 25. Tests

| Suite | Result |
|---|---|
| Full suite (`php artisan test --parallel --processes=2`, SQLite) | **1345 tests: 1250 passed, 0 failed, 95 skipped** (the opt-in MySQL suite, run separately in §26), 15,822 assertions. SaaS.6 ended at 1299 / 1210 / 89 |
| New feature tests `tests/Feature/Billing/` | 37 tests: money 4, tax engine 7, pricing 4, billing profiles 3, invoices 5, payments 8, security 4, pages 2 |
| Architecture | 135 (3 new SaaS.7 rules; 7 allow-lists extended) |
| Operations | `OperationsHardeningTest` 9/9 (runbook lists the new command and job) |
| SaaS.1–6 regression | All earlier suites pass unchanged (included in the full suite) |

The required proofs map to tests as follows:

| Proof | Test |
|---|---|
| Tenant isolation | `BillingSecurityTest` |
| Unauthorised operators refused | `BillingSecurityTest` |
| Numbering under concurrency | MySQL races 1–2 |
| Old invoices unchanged after price, GST profile, supplier or rule changes | `InvoiceTest` |
| Deterministic tax, no float error | `TaxEngineTest`, `MoneyTest` |
| Duplicate and replayed webhooks harmless | `PaymentTest`, race 3 |
| Amount and currency mismatches rejected | `PaymentTest` |
| Browser success never trusted | `PaymentTest` route assertion |
| Payment failure never denies HR access | `PaymentTest` |
| Idempotent drafts | `InvoiceTest` |
| Retry-safe jobs | `PaymentTest` |
| Idempotent scheduler | `PaymentTest` sweep |
| Valid audit chains | `BillingSecurityTest`, MySQL invariants |
| Subscriptions and entitlements intact | `PricingTest`, full suite |
| Payroll intact | `BillingSecurityTest` |
| International proofs (independent market prices, USD/EUR/GBP invoices keep their currency, precision, regime-specific rules, no GST for other jurisdictions, generic tax lines, unchanged snapshots, B2B/B2C explicit, unsupported jurisdictions refused) | `PricingTest`, `InvoiceTest`, `TaxEngineTest`, `BillingProfileTest` |
| "Changing an FX rate does not rewrite old invoices" | Holds structurally: no FX exists, and the architecture test proves no billing code reads a rate |

## 26. MySQL validation

| Check | Result |
|---|---|
| Server | MySQL 8.4.11 |
| Migration chain | Throwaway database: full migrate (114 migrations); rollback 4 steps (SaaS.7, 6, 4, 3) removed all 13 SaaS.7 tables; reapply clean; `mysqldump --no-data` identical before and after; dropped |
| SaaS.7 races (`tests/MySql/BillingConcurrencyTest.php`) | 6/6: 1. two drafts issued at once → consecutive numbers 1 and 2. 2. one draft issued twice at once → one number, one tax set, one audit. 3. a duplicate webhook delivered twice at once → one event, one success. 4. a provider confirmation racing a recorded bank transfer → paid once, the other `invoice_already_paid`. 5. two drafts of one price at once → one draft. 6. two profile versions at once → distinct versions. After every race: gap-free unique sequences matching the series, totals = subtotal + tax lines, exactly one matched payment per paid invoice, both audit chains valid |
| Full MySQL suite (`PEOPLEOS_MYSQL_CONCURRENCY_DB=hcm_saas6_concurrency php artisan test tests/MySql`) | **95 / 95 pass**, 471 assertions: every earlier race (tenancy, audit chain, payroll, entitlements, plans, subscriptions) plus the 6 billing races |

## 27. Mutation testing

26 mutants of the financial rules ran on a private copy of the tree (F06 on MySQL). The first run killed 25.

F19 (a standard-rated outcome priced with no tax) survived: no test defined an outcome with an empty component list. The tax test now verifies such a rule and asserts the refusal, and the re-run kills it. **26 of 26 killed.**

| Area | Mutants |
|---|---|
| Tax calculation | F01 tax skipped |
| Rounding | F02 rule mode ignored, F03 money mode ignored |
| Invoice totals | F04 total without tax |
| Numbering | F05 series never advances, F06 no row lock (MySQL race) |
| Payment verification | F07 amount, F08 currency, F09 already paid |
| Payment state | F10 final states change |
| Webhook security | F11 signature, F12 timestamp |
| Idempotency | F13 re-issue |
| Tenant isolation | F14 invoices unscoped |
| Immutability | F15 issued invoice editable, F21 published price editable |
| Tax gates | F16 unverified rules apply, F17 maker-checker, F18 exports taxed, F19 empty standard outcome, F25 GSTIN check character |
| Authorisation | F20 operator check |
| Markets | F22 market independence, F23 terms market |
| Provider safety | F24 sandbox in production |
| Profiles | F26 consumer registration |

## 28. Browser/accessibility

[Evidence](evidence/SaaS-7-browser-validation.json): a disposable `hcm_saas7_ui_showcase` (showcase seeder plus fictional billing data) on 8094, dropped afterwards. Chromium; 1440×900, light and dark, and 390×844.

- **23 of 23 checks pass:**
  - TOTP sign-in;
  - catalogue in INR and USD with the price/entitlement statement;
  - the matrix shows India "configured (not a legal approval)", others "architecture-ready", and nothing "supported";
  - rule verification reference;
  - billing account overview, including a legacy tenant with "no billing profile";
  - B2B profile and terms;
  - an issued invoice with GST rows, Karnataka (29) and `₹1,073.93`;
  - a ready draft issued through the UI as `UIN/00005`;
  - a USD draft refused ("US sales tax is not supported yet");
  - a short transfer recorded through the UI as an exception;
  - provider events without payloads;
  - the exception resolved through the UI;
  - no horizontal scroll on phone;
  - a tenant administrator gets 403 on all five pages, with no billing menu.
- **axe-core (WCAG 2 A/AA, 2.1 A/AA):** 0 violations in 11 states (catalogue, tax setup light and dark, billing account, invoices, invoice detail, issue modal, transfer modal, payments, payment detail, phone invoice). No console errors.
- **Visual regression and browser suites (8092,** frozen-clock showcase rebuilt from scratch including the SaaS.7 migration): visual **120 passed**, 198 skipped by design, no baseline changed; browser suite (Chromium and WebKit) **74 passed**, 6 skipped by design. The WebKit user-space launcher was rebuilt after the scratchpad was cleared (only `libavif16` and `libflite1` were missing).
- **Showcase (8090,** `hcm_ux_showcase` after the additive migration):
  - all 6 personas (employee, manager, HR, tenant administrator, payroll, executive) sign in, and their everyday pages answer 200;
  - all 8 platform commercial pages (Plans, Entitlements, Subscriptions and the 5 billing pages) answer 403, with no Platform links;
  - the only console errors are those expected 403s;
  - the existing operator renders every billing page in a rolled-back transaction, showing the empty states, India "pending tax review", nothing "supported", and the demo tenant "cannot be invoiced". Users, audit events, markets, billing profiles, invoices and entitlement profiles have the same counts before and after.

## 29. Performance

**New pages: query counts are constant in the number of billed tenants** (1, 5 and 20 tenants, each with invoices, transfers and drafts):

| Page | Queries |
|---|---|
| Billing catalogue | 12 |
| Tax & invoicing | 16 |
| Billing accounts overview | 9 |
| Account detail | 18 |
| Invoice list | 6 |
| Invoice detail | 14 |
| Payment list | 8 |
| Payment detail | 12 |

There is no N+1 and no cross-tenant financial data beyond bounded headers.

**Shared surfaces, `1ee7106` vs HEAD** (3 interleaved rounds; array and database cache; tenants without and with a subscription):

| Surface | Queries (both commits) |
|---|---|
| payroll calculate (15 employees) | 790 |
| leave request | 55 |
| API employees | 9 (11 with database cache) |
| home | 51 (62) |
| entitlement cold load + 1 decision | 1 / 5 (4 / 8) |
| 31 capabilities × 10, warm | 0 |
| Entitlements page | 29 / 35 |
| Subscriptions page | 12 / 21 |

**Query counts are identical on every shared surface**: SaaS.7 adds no query to any HCM, entitlement or subscription path (the architecture tests prove no such path references billing). Timings moved in both directions on unchanged code: payroll calculate −1 % to +36 %, home −19 % to +58 %. The host was in active desktop use (Chromium renderers, desktop indexing), so these are machine noise, not regressions.

## 30. Deferred decisions

| # | Decision | Owner | Blocks |
|---|---|---|---|
| B-1 | Pricing basis per plan (flat, per active employee, hybrid) | Product / commercial | Billing calculation |
| B-2 | Billable quantity: definition and measurement history (metering) | Product / commercial | Per-unit billing |
| B-3 | Intervals offered; period anchoring; proration | Commercial / finance | Billing periods and generation |
| B-4 | Markets launched, their currencies, countries and amounts | Commercial | Real prices |
| B-5 | Tax-inclusive vs exclusive prices and display per market | Finance / tax | Inclusive pricing (prices are exclusive amounts today) |
| B-6 | Free plan | Commercial | Nothing structural |
| B-7 | Markedge selling entities, registrations, entity per market | Finance | Issuing any real invoice |
| B-8 | India GST: rate, SAC, place-of-supply sign-off, rounding, invoice content and series format, 16-character rule, export/LUT, SEZ, e-invoicing | Tax adviser | Verifying the India rule; PDF/e-invoice |
| B-9 | Other jurisdictions to activate (each with tax review) | Finance / tax | Their determiners and rules |
| B-10 | Payment provider(s) per market, accounts, KYC, webhook secrets | Markedge | Automatic collection |
| B-11 | Payment terms, partial payments, customer withholding (India TDS), overpayments | Finance | Partial or over payments (exceptions until then) |
| B-12 | Refunds, credit and debit notes, invoice cancellation | Finance / tax | Any correction after issue |
| B-13 | Dual control for financial operations; operator roles (D-15) | Markedge | Nothing (one operator + reason; maker-checker for tax rules) |
| B-14 | Settlement currency ≠ billing currency (FX, option B) | Finance | FX |
| B-15 | When a new price reaches existing subscribers (grandfathering, notice) | Commercial | Nothing (explicit re-pin only) |
| B-16 | Retention of commercial records after a tenant leaves (D-8) | Legal | SaaS.9 (tenant deletion is blocked by invoices until then) |

## 31. Known limitations

1. **No billing calculation, generation or billing scheduler** (§7). No real invoice can be issued until B-7 and B-8 are decided and a rule is verified.
2. **One tax category per invoice.** Multiple categories need per-category quotes.
3. **Determination exists only for India.** For India, place of supply is the recipient's recorded state (pending tax review); exports, SEZ and UIN are refused. Other regimes are architecture-ready only.
4. **Numbering is per entity and document type** (`invoice` only), with no credit or debit notes, no PDF and no e-invoicing (IRN/QR).
5. **No partial payments, overpayments, refunds, dunning or customer notifications.** Exceptions are resolved off-system with a note.
6. **The sandbox is the only automatic provider** (non-production); no mandates or recurring charges.
7. **Any platform operator can perform every financial operation**, except verifying their own tax rule; there is no maker-checker for issue or transfers (B-13).
8. **Cross-tenant operator lists are bounded** to 100 rows, with no pagination yet.
9. **Provider event payloads** have no retention purge yet (encrypted, never displayed).
10. **Operational:** the leaked demo credential (SaaS.2/SaaS.6) is still active in `hcm` and `hcm_ux_showcase` (re-checked as booleans only, never printed). Rotation is the owner's action.

## 32. Production-readiness impact

- **PeopleOS remains NOT production-ready.** Statutory rules are 0/24 verified, the leaked credential is still active, and the earlier production gates still apply. SaaS.7 does not change them.
- **Deploying SaaS.7 would be low-risk.** The migration is additive and writes no data, nothing restricts anyone, and the sandbox is off and impossible in production.
- **Taking money needs, at minimum:**
  - B-1 to B-4 and B-7 decided;
  - an India rule verified by tax review (B-8);
  - a provider chosen and integrated (B-10) or bank-transfer collection only;
  - the billing calculation and its scheduler built;
  - credit notes (B-12) before any correction is possible;
  - SaaS.10 dual control (B-13).

## 33. Exact files changed

**`62e89c7` (committed by the repository owner during the phase, "update phase 1")**: 117 files, +8162 / −6.
- **New contexts:**
  - `app/Support/Money/*` (4);
  - `app/Support/Commercial/OperatorChange.php`;
  - `app/Support/Tenancy/Jobs/RunsForSuspendedTenants.php`;
  - `app/Domain/Tax/**` (30);
  - `app/Domain/Billing/**` (23);
  - `app/Domain/Payments/**` (21).
- **Entry points:**
  - pages `app/Filament/Pages/Platform{BillingCatalog,TaxSetup,BillingAccounts,Invoices,Payments}Page.php` and their views (+ `billing/invoice-document.blade.php`);
  - `app/Http/Controllers/Billing/ProviderWebhookController.php`;
  - `app/Console/Commands/ProcessBillingProviderEvents.php`.
- **Migration:** `database/migrations/2026_10_25_100001_create_billing_tax_payment_tables.php`.
- **Modified:**
  - `app/Domain/Audit/Enums/AuditAction.php`;
  - `app/Support/Tenancy/Jobs/BindTenantContext.php`;
  - `app/Providers/AppServiceProvider.php` (rate limiter);
  - `bootstrap/app.php` (route);
  - `config/peopleos.php` (`billing`);
  - `routes/console.php`;
  - `tests/Feature/Architecture/ArchitectureTest.php`.
- **Tests:** `tests/Feature/Billing/*` (10 files), `tests/MySql/BillingConcurrencyTest.php`.
- **Docs:**
  - `docs/saas/SaaS-7-Baseline.md`;
  - `docs/architecture/saas-7-billing.md`;
  - `docs/architecture/decision-register.md`;
  - `docs/architecture/security-invariants.md`;
  - `docs/operations/queue-and-scheduler.md`.

**`f262a63`**: `app/Domain/Audit/Enums/AuditAction.php` (an unused case removed).

**Documentation commit** (§35):
- this report;
- `docs/saas/evidence/SaaS-7-browser-validation.json`.

## 34. Exact migrations

| Migration | Up | Down |
|---|---|---|
| `2026_10_25_100001_create_billing_tax_payment_tables` | Creates `billing_markets`, `plan_prices`, `plan_price_versions` (generated `draft_price_id`, `published_from`), `billing_supplier_profiles`, `tax_rules`, `invoice_number_series`, `tenant_billing_profiles`, `subscription_billing_terms` (generated `live_from`), `invoices`, `invoice_lines`, `invoice_tax_lines`, `payments` (generated `open_invoice_id`), `payment_provider_events`, with the constraints in §24 | Drops the 13 tables in reverse order |

## 35. Exact commits

1. `62e89c7` update phase 1 (repository owner): the SaaS.7 code, migration, tests and core documents.
2. `f262a63` chore: remove the unused billing-terms-ended audit action (SaaS.7).
3. The documentation commit: `docs: SaaS.7 billing, tax and payments report and validation evidence` (this file).

Branch `feature/oct_1_phase_1`. **Not pushed. Not merged. Not deployed.**
