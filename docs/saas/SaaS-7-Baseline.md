# SaaS.7 — Working Baseline (discovery and design)

Recorded on 7 October 2026, before any SaaS.7 code change. Every statement about the repository was checked at `1ee7106`. It includes the **international addendum** received during discovery: SaaS.7 builds a global-ready billing, tax and payments architecture, and India GST is one jurisdiction within it.

| Item | Value |
|---|---|
| Branch | `feature/oct_1_phase_1` |
| HEAD | `1ee7106` (SaaS.6 report); clean tree |
| Roadmap position | SaaS.1 §23 **Workstream 5: Billing, Tax & Payments** ("Invoice and collect, lawfully"). SaaS.8 (signup), SaaS.9 (offboarding, retention), SaaS.10 (control plane) and commercial validation are out of scope |
| SaaS.6 | Closed. Billing reads the subscription timeline; nothing in it is changed |

## 1. What exists today (verified)

**There is no commercial money code.** No price, invoice, payment, provider, tax, credit-note or refund code or table exists, and no payment SDK is installed (no Razorpay or Stripe in `composer.lock`). Every hit for the money and tax terms concerns the tenant's own employees:

| Term | What it is today | Relevance |
|---|---|---|
| tax, cess, TDS, PAN, PT | Payroll and statutory compliance (`Domain/Compliance`, `Domain/Payroll`), the tenant's obligations as an employer | **Must never mix** with commercial tax (proposed ADR-0023) |
| payment | `payroll_periods.payment_date`, final-settlement references, EPF attestations | Unrelated |
| currency, exchange rates | Compensation currency (`CHAR(3)`, default INR), `exchange_rates` + `CurrencyRates` (float arithmetic) for HR costing | Not reusable for billing (float; HR purpose) |
| invoice number | `assets.invoice_number` (asset purchases) | Unrelated |
| webhook | Tenant outbound webhooks; the inbound Integration Hub (tenant API key + HMAC) | Pattern only; no unauthenticated provider endpoint exists |
| subscription, commercial | SaaS.3–6 (entitlements, plans, subscriptions), all money-free; architecture tests forbid money in them | Billing consumes them read-only |
| legal entity, GSTIN | `legal_entities`, `statutory_registrations` are the tenant's **employer** structure (PF, ESI, TAN, PT). No GST registration type exists | Never reused as a customer billing profile |

**Reusable primitives and the patterns they set:**

| Primitive | Where | Use in SaaS.7 |
|---|---|---|
| Money in HR | `decimal(14,2)` + PHP `round()` on floats | **Not reused.** Billing needs exact, currency-aware arithmetic |
| `brick/math` | Required by `laravel/framework` itself | Exact decimal arithmetic for rates and rounding (no floats) |
| `intl` (`NumberFormatter`) | PHP extension, present | Locale-aware money display (`₹1,00,000.00`, `1.250,00 €`) |
| `NumberSequences` | `app/Support/Numbering` (tenant-scoped, calendar year, `FOR UPDATE`) | Pattern reused; a **platform** invoice series is needed (the supplier numbers its invoices, across tenants) |
| Inbound events | `Domain/Integration` (unique idempotency key, payload hash, leased claim, encrypted payload) | Pattern reused for provider events, on a **platform** store (tenant resolved from a verified reference, never from the payload) |
| Verified rules | `Domain/Compliance` (`draft → review → verified`; the verifier is never the submitter; production refuses unverified rules) | Mirrored for commercial tax rules |
| Jobs | Every queued job is a `TenantAwareJob` with `BindTenantContext` (architecture test) | Provider events resolve the tenant before any job is dispatched |
| Audit | Hash-chained tenant chain and platform chain (`platform: true`) | Every financial change, on both chains for tenant records |
| Documents | No PDF library; letters and certificates are HTML; downloads via signed routes | Invoice presentation is structured data rendered as HTML; PDF is deferred |
| Operators | `isPlatformAdmin()` (flag + no tenant), MFA enforced per request (SaaS.2), reason-per-action (SaaS.6) | Reused; there is no platform role catalogue (D-15) |
| Settings | Tenant settings only; no platform settings store | New platform catalogue tables |

## 2. What earlier phases decided

| Source | Status | Relevance |
|---|---|---|
| ADR-0027, 0038 | Accepted | Commercial configuration and subscriptions are **tenant-owned** (`BelongsToTenant`, fail-closed). Proposed ADR-0017 (platform-owned, tenant-keyed records) was left open "for billing records" |
| ADR-0031, 0032, 0033 | Accepted | Plans with immutable published versions; the engine reads plan assignments only |
| ADR-0037 | Accepted | Price is never part of a plan or of entitlement. A price is "its own versioned definition referencing a published plan version: chosen by a subscription, read by billing, never read by the entitlement engine". **"No price table is created until the price decisions exist"** (D-1, D-3, intervals, D-10, D-12) |
| ADR-0038 – 0041 | Accepted | The subscription timeline is the commercial truth; nothing automatic; lapse is never DENY |
| ADR-0019 | Proposed | PeopleOS is the system of record (invoices, tax); providers are adapters behind a contract; verified, idempotent provider-event pipeline; money in integer minor units; no card data |
| ADR-0021 | Proposed | Pinned versions; price and plan changes reach subscribers only explicitly |
| ADR-0023 | Proposed | Commercial tax separate from statutory compliance, a pure calculator, unverified rules cannot finalise an invoice |
| SaaS.1 D-1 … D-17 | **Recommendations only** | None was ever approved. SaaS.3–6 each recorded them as open |

## 3. Reconciling the international addendum

The addendum requires a global-ready architecture with India GST as one jurisdiction.

| Earlier material | Conflict? | Resolution |
|---|---|---|
| SaaS.1 billing design: `billing_tax_profiles` with a `gstin` column, `invoice_lines` with `cgst/sgst/igst` columns, "GST is computed in INR", D-3 "INR only" | Yes, India-centric | These are **proposals**, never accepted. Superseded by the addendum: generic tax registrations, generic tax lines, explicit currency everywhere, no INR assumption |
| ADR-0023 ("GST … Tax context") | Naming only | The Tax context is jurisdiction-neutral; India GST is one regime inside it |
| ADR-0037's price-table gate | Partly | The addendum is the decision that the price **structure** exists: market × currency × versioned price × interval, independently versioned per market. Price **values**, the pricing basis (D-1), the intervals offered and the markets launched remain undecided: **no price, market or rule value is created** |
| Accepted SaaS.3–6 decisions | No | Untouched. Billing reads subscriptions and plan versions; nothing feeds back into entitlements or authorisation |

**No conflict with an accepted decision. No STOP before coding.**

## 4. Decisions: implementation vs business, tax and legal

### 4.1 Business, tax and legal decisions (open; SaaS.7 does not take them)

| # | Decision | Owner | What it blocks |
|---|---|---|---|
| B-1 | **Pricing basis** per plan: flat, per active employee, hybrid (D-1) | Product / commercial | Billing calculation |
| B-2 | **Billable quantity**: what counts and when (peak daily, average, period end), and its measurement history (D-13; metering WS4 does not exist) | Product / commercial | Billing calculation for any per-unit price |
| B-3 | **Billing intervals offered**, period anchoring (calendar or anniversary), proration on mid-period changes (ADR-0037 list, G-SUB-3, D-5) | Commercial / finance | Billing periods and their generation |
| B-4 | **Markets launched**, the currency and countries of each, the price amounts (D-3, D-1) | Commercial | Any real price |
| B-5 | Tax-inclusive or tax-exclusive prices and display per market (D-10; consumer markets) | Finance / tax | Inclusive pricing (prices are exclusive amounts until decided) |
| B-6 | Free plan (D-12) | Commercial | Nothing structural (a zero amount is representable) |
| B-7 | **Markedge's supplier entities**: legal entity per market, registrations (GSTIN(s), VAT numbers) | Finance | Issuing any invoice |
| B-8 | **India GST treatment**: rate, SAC, place-of-supply determination, rounding, invoice content and series format, export/LUT zero-rating, SEZ, e-invoicing (D-10) | Tax adviser | Verifying the India rule (no invoice can be issued until then) |
| B-9 | Which other jurisdictions to activate (EU VAT, UK VAT, UAE VAT, US sales tax, …), each with its own tax review | Finance / tax | Their determination logic and rules |
| B-10 | **Payment provider(s)** per market, accounts, KYC, webhook secrets (D-2) | Markedge | Any automatic collection (manual bank transfer and a sandbox are built) |
| B-11 | Payment terms (due dates), partial payments, customer withholding (India TDS), overpayments | Finance | Partial and over-payments (refused as reconciliation exceptions until decided) |
| B-12 | **Refunds, credit and debit notes** (D-5) | Finance / tax | Corrections after issue (none possible yet: an issued invoice cannot be changed) |
| B-13 | Dual control for financial operations; platform operator roles (D-15) | Markedge | Nothing (the rule-verification maker-checker precedent is reused; other financial operations need one operator and a reason) |
| B-14 | Settlement currency different from billing currency (FX, option B) | Finance | FX (not built) |
| B-15 | When a new price reaches existing subscribers (grandfathering, notice; D-5, ADR-0021) | Commercial | Nothing (terms pin a price version; only an explicit, audited re-pin changes it) |
| B-16 | Retention of commercial and tax records after a tenant leaves (D-8) | Legal | SaaS.9 (invoices block tenant deletion until then) |

### 4.2 Implementation decisions (SaaS.7 takes them; recorded as ADRs)

| # | Decision |
|---|---|
| I-1 | Money is an integer count of minor units (`BIGINT`) plus an ISO-4217 code. A code-owned currency catalogue gives each currency's minor units (0, 2 or 3). Rates and multiplication use exact decimals (`brick/math`) with an explicit rounding mode. No float anywhere |
| I-2 | Three bounded contexts: `Billing` (markets, prices, billing profiles, terms, invoices), `Tax` (jurisdiction-neutral engine), `Payments` (providers, payments, provider events, reconciliation). Dependencies run Payments → Billing → Tax, and Billing → Subscriptions/plan versions (read only). Nothing that authorises, no HCM module, the entitlement engine and the subscription lifecycle depend on any of them. Country-specific code lives only in `Tax/Jurisdictions/{Country}` |
| I-3 | Tenant financial records (billing profiles, terms, invoices and their lines, payments) are `BelongsToTenant` (fail-closed), with the tenant foreign key **restricted** on delete (financial records are retained). The catalogue (currencies, markets, prices, supplier profiles, tax rules, number series) and provider events are platform-level |
| I-4 | Prices are per **plan version × market × interval**, each with its own versions (draft, published, retired), immutable once published. Changing one market's price never touches another's. A subscription's **billing terms** pin one price version from a date; nothing re-prices a subscriber automatically |
| I-5 | Tax: regime, jurisdiction, verified rule version, determination, calculation, snapshot. Rules are effective-dated and verified by a second operator (maker-checker, as in statutory rules). A missing determiner, rule or treatment **refuses** the invoice (fail closed). A jurisdiction's compliance status is computed and never "supported" automatically |
| I-6 | Invoices: `draft → issued → paid`, or `draft → discarded`. The number (gap-free, from an explicit platform series), the tax and the supplier, customer and tax snapshots are fixed at issue. Nothing financial changes afterwards |
| I-7 | Payments: one provider contract. A payment succeeds only on a verified provider event, a server-side provider fetch, or an operator-recorded manual payment. Amounts and currencies must match exactly; anything else is a reconciliation exception, never a partial settlement |
| I-8 | Billing currency = payment currency (option A). Provider settlement data may be recorded but is never used to settle an invoice |
| I-9 | No FX in SaaS.7: no invoice, tax or payment calculation reads an exchange rate (architecture test) |

## 5. The boundary: what SaaS.7 builds and what is blocked

| Built (no business decision needed) | Blocked (needs §4.1) |
|---|---|
| Money and currency catalogue; locale display | Real markets, prices and amounts (B-4) |
| Markets; price lists per plan version × market × interval with versions | **Billing periods and their calculation**: amount for a period (B-1, B-2, B-3) |
| Subscription billing terms (pinned price version) and the answer to "what price applied on a date?" | **Invoice generation from subscriptions and its scheduler** (B-1 – B-3) |
| Customer billing profiles with generic tax registrations; supplier profiles | Issuing a real invoice: needs a verified rule, a supplier profile and a series (B-7, B-8) |
| Jurisdiction-neutral tax engine; India GST determination and GSTIN validation; jurisdiction support matrix | Other jurisdictions' determination and rules (B-9) |
| Invoices (draft, issue, discard), numbering, snapshots, presentation | Credit and debit notes, refunds, voiding (B-12); PDFs and e-invoicing (B-8) |
| Payment provider contract; manual bank-transfer recording; a sandbox provider (non-production only); verified webhook pipeline; reconciliation and exceptions | A real provider adapter (B-10); partial and over-payments (B-11); FX settlement (B-14) |

The invoice drafting service accepts priced lines. In SaaS.7 it is called only by tests and the disposable UI validation data; the billing calculation will call it once B-1 to B-3 exist. **No operator page drafts an invoice by hand**, because ad-hoc invoicing would be a second, unapproved source of amounts.

## 6. Design

### 6.1 Money (`app/Support/Money`)

- `Currency` enum: the controlled ISO-4217 catalogue. INR, USD, EUR, GBP, AED, SGD, AUD, CAD, CHF, JPY (0 decimals), plus BHD and KWD (3 decimals) to prove precision. Each has its minor units and name. Being in the catalogue is a technical fact, not market support.
- `Money` (immutable): integer minor units and a `Currency`.
  - Parsing refuses more decimals than the currency has.
  - Arithmetic refuses mixed currencies.
  - Percentages and multiplications go through exact decimals with a named rounding mode.
  - Values stay inside the signed 64-bit range.
- `MoneyFormatter`: `intl` display for a locale (a market's display locale). Display never decides the stored currency.

### 6.2 Billing catalogue (platform)

| Table | Content |
|---|---|
| `billing_markets` | `code` (unique, permanent), name, `currency` (permanent), `countries` (ISO 3166-1, informational: for a future pricing page), `supplier_entity` (which Markedge entity sells there), display `locale`, status |
| `plan_prices` | `plan_version_id` × `market_id` × `interval` (`month`, `year`: representable, not "offered"), `basis` (`flat`, `per_active_employee`: representable, B-1 open); unique triple |
| `plan_price_versions` | `version`, `status` (`draft → published → retired`), `currency` (the market's, stored explicitly), `unit_amount_minor` (≥ 0), `effective_from` (set at publication: today or later, after the previous published version). One draft at a time; immutable once published |
| `billing_supplier_profiles` | Markedge entities, versioned: `entity_code`, `version`, legal name, address, country, subdivision (ISO 3166-2), tax registration (type, value), `effective_from`. Immutable rows |
| `tax_rules` | See §6.4 |
| `invoice_number_series` | `supplier_entity`, `document_type` (`invoice`), `prefix`, `starts_on`, `ends_on` (explicit window, e.g. a financial year chosen by finance), `next_sequence`, `padding`, status. Windows never overlap per entity and type |

### 6.3 Tenant billing (tenant-owned, `BelongsToTenant`)

| Table | Content |
|---|---|
| `tenant_billing_profiles` | Versioned billing identity: `market_id`, `customer_type` (`business` / `consumer`), legal name, billing e-mail and contact, address, `country`, `subdivision`, `tax_registration` (`registered` / `unregistered` / `not_applicable`), `tax_id_type`, `tax_id_value`, `tax_id_status`, `special_tax_status` (jurisdiction-specific, e.g. India SEZ), `effective_from`. Immutable rows; the version in force on a date is the latest started one. **Separate from HR data** (employee PAN, employer registrations) |
| `subscription_billing_terms` | `subscription_id`, the pinned `plan_price_version_id` (with its plan version, market, currency, interval, basis), effective dates, painted like plan assignments. Set only if the price's plan version is the subscription's on the start date, and its market is the tenant's billing market. Serialised on the tenant's commercial lock |
| `invoices` | `reference` (ULID, for URLs), `status`, `document_type`, `market_id`, `supplier_entity`, `currency`, `subscription_id`, service period, `number` / `series_id` / `sequence` (at issue), `issue_date`, `due_date`, `subtotal_minor`, `tax_minor`, `total_minor`, the profile versions and rule used, `snapshot` (supplier, customer, tax determination, presentation), `idempotency_key` |
| `invoice_lines` | `line_no`, description, `tax_category`, integer `quantity`, `unit_amount_minor`, `amount_minor`, `currency`, price and plan version references, period |
| `invoice_tax_lines` | Generic: `regime`, `country`, `subdivision`, `tax_type` (CGST, SGST, IGST, VAT, …), `treatment`, `rate`, `taxable_minor`, `tax_minor`, `currency`, `tax_rule_id`, jurisdiction `metadata` |
| `payments` | `reference`, `invoice_id`, `provider`, `provider_reference` (unique per provider), `idempotency_key` (unique), `method` (generic), `amount_minor`, `currency`, optional settlement amount and currency (recorded only), `status`, timestamps, failure code and message, reconciliation status and code |

### 6.4 Tax (`app/Domain/Tax`, jurisdiction-neutral)

```
TaxContext (supplier jurisdiction + registration, customer type + jurisdiction + registration + special status,
            tax category, tax point date, currency)
   → regime of the supplier's country (catalogue) → its determiner (or: not supported → refuse)
   → determination: treatment, outcome key, place of supply, basis
   → verified rule version in force (regime, country, category, date) → components for the outcome (or refuse)
   → TaxCalculator (pure): per line and component, rounded per the rule → generic tax lines
   → frozen on the invoice at issue
```

- **Regimes** (code-owned): `IN_GST`, `EU_VAT`, `GB_VAT`, `AE_VAT`, `US_SALES_TAX`, `CA_SALES_TAX` (GST/HST/PST/QST), `AU_GST`, `SG_GST`. Only `IN_GST` has a determiner.
- **Treatments:** `standard`, `zero_rated`, `exempt`, `reverse_charge`, `out_of_scope`. A treatment may carry no tax lines at all: tax is never assumed to be rate × amount.
- **Rules** (`tax_rules`, platform):
  - regime, country, optional subdivision, `tax_category`, `version`, `effective_from`;
  - `outcomes`: per outcome key, a list of components (type, rate);
  - rounding mode and stage; `classification` (e.g. the SAC code).
  - Status runs `draft → review → verified` (or `retired`). Verification is by a different operator than the author, with a reference to the tax review. Rows are immutable after submission.
- **India GST** (`Tax/Jurisdictions/India`):
  - GST state codes for ISO 3166-2 subdivisions, and GSTIN format and check digit (Luhn mod 36; the state code must match the subdivision).
  - Determination:
    - a supplier registered in an Indian state, and a customer in India, give `intra_state` (same state), `intra_union_territory` (same union territory without legislature) or `inter_state`;
    - the place of supply is taken as the customer's state on record, **pending the tax review that verifies the rule**;
    - exports, SEZ and UIN customers are **refused** (zero-rating not configured).
  - A presentation extension adds GSTINs, place of supply and the CGST/SGST/IGST summary.
- **Jurisdiction support matrix** (code-owned catalogue plus the rules that exist): per country, its currency, regime, registration concept, B2B/B2C, determination, invoice requirements, payment availability and compliance status. Status is one of:
  - `not_supported`: no determiner;
  - `pending_tax_review`: determiner, no verified rule;
  - `configured`: a verified rule is in force;
  - `supported`: never set by software;
  - `not_applicable`.

### 6.5 Invoices

- **Draft** (internal service): priced lines in the market's currency. Integer quantities only; decimal quantities belong to proration (B-3).
- **Issue** (operator, reason): in one transaction, under the invoice and series locks:
  - the customer profile in force (its market must be the draft's) and the supplier profile of the market's entity;
  - tax determination and calculation (fail closed);
  - the number from the series covering the issue date (gap-free);
  - totals and snapshots;
  - audited on both chains.

  Issuing twice is a no-op. A length limit from the jurisdiction's invoice requirements (India: 16 characters **[verify]**) is checked when the series is created and at allocation.
- **Discard** (draft only, reason).
- **Immutability:** model guards. Issued invoices change only `status → paid`, `paid_at` and the settling payment. Lines and tax lines are never edited or deleted.
- **Presentation:** a structured document (header, parties, lines, tax summary, totals) plus regime extensions, rendered by small partials, with money formatted for the market's locale.

### 6.6 Payments

- **Contract** `PaymentProvider`: key, supported currencies and methods, `start`, `fetch`, `verifyWebhook`, `interpret`. Provider-specific fields live only in its adapter and in the provider event payload.
- **Providers:**
  - `manual`: bank transfer recorded by an operator with the bank reference;
  - `sandbox`: deterministic, HMAC-signed webhooks with a timestamp; enabled only outside production.
- **Webhook** `POST /webhooks/billing/{provider}`: no session, CSRF-exempt, rate-limited.
  1. Verify the raw body (401 on failure).
  2. Store `payment_provider_events`, unique `(provider, event_id)`, payload encrypted with its SHA-256. The same id and body is a no-op; a different body under a known id is refused (409).
  3. Resolve the payment from the verified provider reference (never a tenant id from the payload) and dispatch a tenant-aware job.
  4. An unknown reference becomes an exception that a scheduled sweep retries.
- **Reconciliation:** under the payment and invoice locks.
  - Payment states only move forward.
  - A success settles the invoice only if the amount and currency match exactly and the invoice is issued and unpaid.
  - Otherwise it is a reconciliation exception: amount mismatch, currency mismatch, invoice already paid, or invoice not payable. Operators resolve exceptions with a note.
  - Nothing touches entitlements, subscriptions or authorisation: an unpaid or failed payment never denies anything.

### 6.7 Security, isolation, audit

- **Operators only.** Every mutation checks `isPlatformAdmin()` in the service and needs a reason. MFA is enforced per request (SaaS.2). Tax rules need a second operator to verify.
- **Tenant users:** no tenant billing page exists. Tenant users get 403 on every billing page, and HR data never mixes with billing data.
- **Isolation:** tenant records are fail-closed. Cross-tenant operator lists go through one allow-listed directory. Provider events resolve the tenant only from verified references.
- **Audit:** every change on the platform chain, plus the tenant chain for tenant records. Each event carries actor (or provider/scheduler), reason, before and after, effective date, and a correlation or idempotency key.

### 6.8 Queue and scheduler

- **Job:** `ApplyProviderEvent` (tenant-aware, unique per event, leased claim, idempotent).
- **Command:** `peopleos:billing:provider-events`, every five minutes, `withoutOverlapping()->onOneServer()`. It retries unresolved or failed events and expired leases. Idempotent; listed in the operations runbook.
- **No billing-run scheduler** (blocked, §5).

### 6.9 Legacy tenants

Nothing is created for any existing tenant: no profile, market, terms, price, invoice or payment. A tenant without a billing profile cannot be invoiced. SaaS.3–6 behaviour is unchanged.

### 6.10 Operator UI (Platform group)

| Page | Content |
|---|---|
| Billing catalogue | Currencies (read only), markets, prices and their versions |
| Tax & invoicing | Jurisdiction support matrix, supplier profiles, tax rules (draft, submit, verify, retire), number series |
| Billing accounts | Per tenant: billing profile versions, billing terms, its invoices and payments |
| Invoices | All invoices; one invoice's presentation; issue, discard, start a payment, record a manual payment |
| Payments | Payments, provider events and reconciliation exceptions; refresh from the provider; resolve an exception |

There is no public checkout, pricing page, signup or tenant-facing billing page.

## 7. International readiness

| Topic | SaaS.7 |
|---|---|
| Supported currencies | Catalogue: INR, USD, EUR, GBP, AED, SGD, AUD, CAD, CHF, JPY, BHD, KWD. Technical support only: a currency is sold only through a market an operator creates |
| Currency model | ISO-4217 code on every price version, terms row, invoice, invoice line, tax line and payment; minor units from the catalogue (JPY 0, BHD and KWD 3, others 2); integers, never floats |
| Pricing by market | Each market has one currency; each plan version × market × interval has its own versions; changing one market never changes another; invoices copy amounts |
| Billing jurisdiction | Explicit on the customer billing profile (country, subdivision, customer type, registration). Never derived from IP, locale, currency, server or employee location. The tenant's operating country (`tenants.country_code`) is not used |
| Tax abstraction | Regime → jurisdiction → verified rule → determination → generic tax lines |
| GST implementation | India: determiner, GSTIN validation, state codes, presentation. Inactive until a verified rule and a supplier registration exist |
| VAT and sales-tax readiness | Regimes, registration types and treatments (including reverse charge and zero-rated) are representable; no determiner or rule is configured |
| B2B / B2C | `customer_type` is explicit and required |
| Tax registration | Generic type, value, issuing country, validation status (format check implemented for GSTIN only), effective dating through the profile version |
| FX | Not built and not needed (option A). No calculation reads a rate. A later FX design must snapshot rate, source, timestamp and method on the invoice |
| Payment providers | Provider-neutral contract and generic method types (card, bank transfer, direct debit, wallet, local, other); no Indian method assumed |
| Invoice localisation | Presentation data plus per-regime extensions; formatting by the market's display locale |
| Compliance boundary | Computed status per jurisdiction; nothing is "supported" without legal and tax approval outside the software |
| Unsupported jurisdictions | Invoice issue is refused with the reason; no default tax is applied |
| Expansion path | A new jurisdiction means a determiner (and validator) under `Tax/Jurisdictions/{Country}`, then rules verified by tax review. Billing, invoices and payments are unchanged |

## 8. Not added

- **Money flows:** billing periods, their calculation and scheduler (blocked); credit and debit notes, refunds, voiding, dunning; partial and over-payments; FX.
- **Providers and documents:** real provider adapters, PDF, e-invoicing.
- **Customer-facing surfaces:** public checkout, pricing page, signup, tenant billing pages, notifications to customers.
- **Untouched areas:** commercial enforcement, entitlement or authorisation changes, payroll and HR, RMS.

## 9. Test strategy

- **Feature tests:**
  - money and currency precision;
  - prices per market and their independence;
  - terms and the price applicable on a date;
  - billing profiles and registrations (GSTIN);
  - the tax engine (determination, rules, verification, calculation, rounding, fail-closed cases);
  - invoices (draft, issue, numbering, snapshots, immutability, presentation per currency);
  - payments (manual, sandbox, webhook signature, replay, duplicates, mismatches, retries, exceptions);
  - security and isolation; legacy tenants; payroll and entitlements untouched; architecture rules.
- **MySQL races:** concurrent issues (numbering), the same draft issued twice, duplicate webhooks at once, a webhook racing a manual payment, two payments for one invoice, concurrent price publication.
- **Mutation testing** of the financial rules.
- **Regression and the rest:** browser and axe walkthroughs, visual regression, the showcase, performance, full suites.

## 10. Implementation notes (recorded after the build)

The build refined the design in these points. None changes a decision above.

- **Provider events name the tenant `resolved_tenant_id`.** Platform invariant 22 treats any `tenant_id` column as tenant ownership. A provider event is a platform record that merely points at the tenant resolved from a verified reference, so it uses another name instead of weakening the invariant.
- **Recorded bank transfers are atomic and never hold the "open payment" slot.** The transfer is recorded and reconciled in one transaction, and the one-open-payment unique index covers provider-started payments only. A customer who pays by bank while a card link is open is therefore recorded, then matched, or flagged as an exception.
- **Determiners declare their outcomes.** A rule may only price outcomes its regime's determiner can produce, so a typo cannot create an outcome that never applies. The operator form is generated from these outcome keys, with no country-specific code in the page.
- **One queued job runs for suspended tenants.** `BindTenantContext` skips suspended tenants. Payment reconciliation, the only implementer of the new `RunsForSuspendedTenants` marker, still runs: the provider already moved the money, and it touches only commercial records.
- **`TaxRegistry` is extendable.** Tests register UK VAT and US sales-tax determiners (test-only) to prove that invoices, tax lines and presentation are regime-neutral. Production wires India only.
- **Fail-closed tenancy caught two read paths.** Draft readiness and invoice presentation read lines without binding the invoice's tenant, and returned nothing. Both now run inside the invoice's tenant, and the snapshot test asserts that the compared document is not empty.
