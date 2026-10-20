# SAAS.7 FINAL CONFIGURATION GATE REPORT

**Gate:** SaaS.7 final configuration closure / release gate (owner prompt of 8 October 2026).
**Branch:** `feature/oct_1_phase_1`. Not pushed, not merged, not deployed. SaaS.8 not started.

**FINAL STATUS: A. SAAS.7 COMPLETE** (§21–§23).

PeopleOS is configuration-driven: prices, customer deals, minimums, discounts, payment terms and billing policy, tax rates and rules (GST/VAT/sales tax, SAC, place of supply, reverse charge, export conditions), tax registrations and the LUT, US state and local rules, UK/EU/UAE configuration and effective dates are data. Calculation algorithms stay in code. A change follows one path, with no deployment:

```
CONFIGURATION (maker) → SECOND-OPERATOR APPROVAL → EFFECTIVE DATE → ACTIVE RULE → TRANSACTION → IMMUTABLE HISTORICAL RECORD
```

No business or legal value was invented in this gate: no price, SAC, registration, LUT, nexus, local rate or customer deal was created as production data. The missing inputs (B-4, B-7, B-8, B-9) are entered later as data (§23).

## 1. Starting commit

`975d114` (docs: SaaS.7 configuration report, ADR-0058..0064, invariants 66-72 and browser evidence).

## 2. Ending commit

- `36a9292` "updated": **committed by the owner** during the gate, containing the gate's code, migration and tests as they stood (41 files, +724 / −150). Not rewritten.
- `256624e`: selling-entity changes in the configuration trail (2 files).
- Documentation: the commit that adds this report, immediately after `256624e`.

## 3. Files changed

Code (`36a9292` + `256624e`):
- **New:** migration `2026_10_28_100001_saas7_configuration_closure.php`; `tests/Feature/Billing/ConfigurationGateTest.php`.
- **Tax:** `TaxJurisdiction` (locality), `TaxRule` (locality in label and scope), `TaxRules` (locality-scoped lookups, versions and history; deadlock retry), `TaxEngine` (local leg; `local_rates_required` semantics), `TaxRegistry` (`usesLocalities`), `UsSalesTaxDestination` (locality in the place of supply).
- **Billing:** `SupplierProfiles` (propose / execute / close, approved-only reads, deadlock retry), `SupplierProfile` (status and guard), `ApprovalAction` (`supplier_profile_change`), `InvoiceSeries` (approved entity), `BillingProfiles` and `TenantBillingProfile` (`tax_locality`), `Invoices` (customer locality, configuration references, tax-line locality), `InvoicePresentation` (local leg label), `BillingPeriods` and `PricingBasis` (fixed per interval), `NegotiatedPrices` / `NegotiatedPriceVersion` (wording), `BillingDirectory` (entity changes in the trail), `AuditAction` (`SUPPLIER_PROFILE_PROPOSED`).
- **Payments:** `ApprovalDesk` (entity approvals and closing), `ProviderEvents` (chargeback outcome and warning).
- **Pages and views:** Tax & invoicing (propose entity, entity status, locality on rules, local rules section, no padding default, format-only placeholders), Billing accounts (customer tax locality, fixed-per-interval wording, configured notice period), Billing catalogue (configured notice period, format-only placeholder), Invoices (format-only placeholders), Payments (chargeback flag).
- **Tests adapted:** `ArchitectureTest` (hardcode guard), `BillingPagesTest`, `BillingSecurityTest`, `BillingTestHelpers` (`supplierVersion()`), `CommercialPricingTest`, `FinancialControlsTest`, `InvoiceTest`, `SettlementTest`, `StatutoryDatasetTest`, MySQL `CommercialConfigurationConcurrencyTest` (race 16).

Documentation: this report; `docs/operations/commercial-configuration-runbook.md` (new); decision register (ADR-0065..0069); security invariants 73–77; the SaaS.7 developer's map; Commercial Decisions §16; an erratum in the configuration report §14; browser evidence `evidence/SaaS-7-configuration-gate-browser-validation.json`.

## 4. Migrations

One additive migration, `2026_10_28_100001_saas7_configuration_closure`:

| Table | Change |
|---|---|
| `tax_rules` | `locality` (default `''` = the whole subdivision or country); the version is unique per regime, country, subdivision, locality and category |
| `tenant_billing_profiles` | `tax_locality`: the customer's local tax jurisdiction code (US county, city, district), recorded by an operator |
| `billing_supplier_profiles` | `status` (pending / approved / rejected / withdrawn; existing rows approved), `approval_id`, `approved_by`, `approved_at` |

It writes no row except marking existing supplier versions `approved` (there were none in the showcase). On a throwaway MySQL 8.4 database it migrated, rolled back (the new columns removed and the previous unique index restored) and re-applied; the schema after up → down → up is identical to the first up; the database was dropped. A rollback on a database that already has local rules sharing a version number across localities would need those rules removed first (documented). On the 8090 showcase it added the columns and one migration row; every table count was identical.

## 5. Pricing configuration

Verified (tests in §15): Plan → Plan Version → Market → Currency → Billing Interval → Price → Price Version, each version with amount, minimum quantity, status and effective dates.

| Requirement | How |
|---|---|
| Standard price configurable | Billing catalogue: market, price, draft amount, publication by a second operator |
| Per-employee and fixed prices | PEPM (per employee per month, any interval) or **fixed per billing interval** (a month, or a year for annual terms; ADR-0067: an annual commitment is entered as the annual amount) |
| Minimum quantity, discount, contract and effective dates, future prices | Price versions (minimum); customer deals (minimum, discount, contract window); every version from a date, scheduled until then |
| Published versions immutable | Model guards; a change is a new version |
| Existing subscriptions pinned, historical invoices unchanged | Billing terms pin a version; increases need the configured notice; invoices freeze lines, price version and source |
| No price | `NO_PRICE_CONFIGURED`: price matrix, `BillingTerms::priceFor`, billing run exception (nothing billed); never zero, never another market's price, never an FX-manufactured price |

## 6. Customer deal configuration

A negotiated price (tenant-owned, fail-closed) per subscription, plan version, market (its currency) and interval: PEPM or fixed per interval, minimum, discount, contract start and end, contract reference, notes; versions published by maker-checker, effective-dated, audited on both chains. Precedence **CUSTOMER AGREED PRICE > STANDARD PLAN PRICE > NO PRICE** (`BillingTerms::priceFor`; the standard price cannot be pinned while a deal is in force). One tenant's deal is invisible to every other tenant and to the catalogue.

The owner's examples are configuration only (`CommercialPricingTest`, fictional tenants; nothing seeded):
- Client A: ₹80 per employee, minimum 250 → 3 employees billed 250 × 80 = ₹20,000.
- Client B: fixed annual ₹600,000 → entered as `600000.00`, billed once in advance, no true-up; after the contract the days are NO_PRICE_CONFIGURED.

## 7. Policy configuration

One authoritative source: `CommercialConfiguration` resolves each Markedge policy from approved, effective-dated versions, else the shipped default recorded in `config('peopleos.commercial.policy_defaults')` (the approved decisions); nothing else reads those defaults and no environment setting duplicates them (architecture test, ADR-0069).

| Policy | Key | Shipped default | Changed by |
|---|---|---|---|
| Payment terms | `billing.payment_terms_days` | 15 days (B-11) | Commercial policies → Propose change → second operator |
| B2B only | `billing.b2b_only` | yes (B-5) | same |
| Tax-exclusive pricing | `billing.prices_include_tax` | no (B-5) | same |
| Price-increase notice | `billing.price_increase_notice_days` | 30 days (B-15) | same |
| TDS treatment | `settlement.tds_jurisdictions` | IN:INR (B-11) | same |
| Rounding | `billing.proration_rounding` | half up (B-3) | same |
| Approval policy | which operations need a second operator | B-13 (no thresholds) | code: a security control, deliberately not configurable (§13) |

A version after its end with no successor leaves the policy NOT CONFIGURED: billing refuses (`[CONFIGURATION_MISSING]`) instead of falling back. UI texts that quoted "30 days" now show the configured value.

## 8. Tax / statutory configuration

Jurisdiction → Regime → Rule (scope: country, state, member state, **local tax jurisdiction**) → Version → Effective / expiry dates → Verification by a second operator → Active (derived state). A rule outcome holds treatment (standard, zero-rated, exempt, reverse charge, not taxable, out of scope), components and rates, taxable share, conditions (B2B/B2C, customer registration and identifier type, recipient abroad, invoice currency, supplier registration valid on the day, local rates, special status), required supplier registration, statutory wording and failure code; the rule holds classification (SAC), rounding, amount basis, source, legal reference, URL, source date, dataset verification status and history. Never a single percentage.

Refusal reasons (never 0 %, never an expired rule, never another jurisdiction's rule, never an incomplete invoice): `TAX_CONFIGURATION_MISSING`, `TAX_RULE_UNVERIFIED`, `PLACE_OF_SUPPLY_UNRESOLVED`, `CUSTOMER_TAX_STATUS_UNRESOLVED`, `SUPPLIER_TAX_STATUS_UNRESOLVED`, `REGISTRATION_REQUIRED`, `EXPORT_CONDITIONS_NOT_SATISFIED`, `TAX_CLASSIFICATION_PENDING` (the gate's "SAC_NOT_CONFIGURED": India invoices are refused until a rule version carries the SAC), plus `[REPORTING_VALUE_REQUIRED]` and `[CONFIGURATION_MISSING]` at issue.

Current statutory data stays as **initial configuration** (dataset 2026.10: India, UK, 27 EU member states, UAE, five US states, with sources); Greece and the unresearched US jurisdictions stay PENDING_VERIFICATION and cannot be used.

Registrations and the LUT are **configuration of the selling entity**: closed in this gate, a version is proposed by one operator and applies only once another approves it (ADR-0066).

## 9. Jurisdiction architecture

- India (supplier side: intra-state, intra-UT, inter-state, export of services under its conditions), UK, EU (one rule per member state), UAE and US (one rule per state; **local tax jurisdictions** as their own rules, ADR-0065) on the destination side. No claim of universal compliance; the support matrix never says "supported".
- EU stays member-state aware (27 rules); US stays state- and local-aware: closed in this gate, a state rule that requires local rates is completed by the verified rule of the customer's recorded local tax jurisdiction (its own invoice leg); no locality → refused; no local rule → refused; the state rate never stands alone.
- A new jurisdiction is a determiner module registered in `TaxRegistry` plus rules as data; billing, invoices, tax lines and payments are unchanged.

## 10. Effective dating

Every version (price, deal, rule, policy, statutory parameter, selling entity, billing profile, terms) applies from its date; scheduled versions activate on their date without action (e.g. California's 2027 rule); the version of a day answers questions about that day; an expired version leaves nothing in force (never an older one); approvals after the proposed start are refused (policy, entity) and publications are today or later.

## 11. Maker-checker

| Operation | Second operator | Enforced in |
|---|---|---|
| Price publication | Approvals | `FinancialApprovals` (service) + `FinancialApproval` (model) |
| Customer deal publication | Approvals | same |
| Policy change, statutory parameter | Approvals | same + `ConfigurationVersion` guard |
| Tax rule, statutory dataset | Verify (not the author, submitter or loader) | `TaxRules`, `StatutoryDataset` |
| Selling entity (identity, GSTIN, LUT, registrations) — **new in this gate** | Approvals | `SupplierProfiles` + `SupplierProfile` guard |
| Invoice cancellation (full credit note), credit notes, refunds, write-offs, payment-exception resolutions | Approvals | `FinancialApprovals` + executors' `claim()` |

The maker cannot approve their own change in the service and in the model (proven by tests at both layers). Customer billing profiles and invoice number series remain single-operator, audited records (customer master data and numbering windows; outside B-13's list).

## 12. Historical immutability

`ConfigurationGateTest` issues an invoice, then changes the standard price, adds a customer deal, publishes a new tax rule version and retires the old one, changes the payment-terms policy, approves a new selling entity version with a new name, GSTIN and LUT, and records a new customer billing profile: the invoice row, lines, tax lines and rendered document are identical before and after. Issued invoices keep price and version, price source, quantity and evidence, currency, tax rule version per leg, tax amounts, wording, supplier and customer details (incl. tax identifiers and local jurisdiction), the reporting value and, new in this gate, **the configuration references they used** (policy versions or shipped defaults, due-date source, numbering limit; ADR-0068). A new invoice uses the new configuration and records the new policy version.

## 13. Hardcode audit

Method: every numeric literal and every string literal resembling a rate, amount, GSTIN, PAN or SAC in `app/Domain/{Billing,Tax,Payments}`, `app/Support/{Money,Commercial}`, the platform billing pages, the billing commands and `config/peopleos.php` (comments excluded), plus currency and country literals, classified:

| Class | Findings | Action |
|---|---|---|
| **A. Legitimate technical constant** | Field lengths and list limits (100/150/200/300/500/2000 characters, 50/60/100/200/500 rows); HTTP statuses (200/401/404/409/413); webhook size, rate and time tolerances, provider timeouts, retry counts (3), event attempts (5); calendar arithmetic (12 months a year, date prefixes); sanity bounds (minimum ≤ 1,000,000 employees, policy days 0–365, invoice-number length 4–64, discount < 100 %, padding 1–12, reasons 5–500 characters); algorithms (GSTIN mod-36 check, exact decimal scales, percentage ÷ 100, ISO 4217 minor units); Razorpay test-mode guards (`rzp_test_`, secret length) and its supported-currency list; the approval scope of B-13 (a security control, not a tunable value); billing methods of B-2/B-3 (peak count, calendar anchoring, proration method, annual in advance with true-up) as algorithms whose parameters are configuration | Kept |
| **B. Configurable business value incorrectly hardcoded** | Configuration pass: B2B only and payment terms (environment), the 30-day notice (constant), half-up proration (constant), TDS India/INR (code), invoice-number length 16 (catalogue). This gate: UI texts quoting "30 days" (3); annual fixed price multiplied by 12 (hidden factor); invoice-number padding defaulting to 5; UI placeholders that looked like values (a ₹1499.00 price, a ₹1250.00 deal, a 10 % discount, an 88.1234 exchange rate and its source, LUT and permit numbers); US local rates impossible to configure (only refusable); registrations and the LUT changeable by one operator | All fixed: configuration versions, configured values in UI, fixed per interval, format-only placeholders, locality rules, entity maker-checker |
| **C. Test fixture** | Fictional GSTINs on pseudo-PAN `ZZZZZ9999Z`, odd test rates (3.75 / 7.5 %), fictional amounts, deals, LUT and permit references, fictional SAC `000000`, `policyDefault()` | Kept (tests only) |
| **D. Documentation / example** | Docblocks citing decisions ("B-11: net 15"), helper and placeholder texts showing formats (`IN-GST-9983-18`, `sac=000000`, `<LUT reference>`), descriptive jurisdiction texts | Kept |
| **E. Statutory reference that must remain data** | Rates, treatments, conditions, wording, sources and the invoice-number length: in the statutory dataset and rule/parameter versions (data). Reference identifier lists used by the jurisdiction modules' algorithms — GST state codes (36), US states and DC (51), EU membership (27), the ISO currency catalogue — stay in code with their modules: they change only by statute or treaty, are validated by tests, and are identifiers, not rates or rules | Kept (identifier lists: accepted residual, §22) |

Outside SaaS.7: HCM screens default some fields to `INR`/`IN` (company, compensation); they are HR master-data defaults, not commercial values, and were not changed.

An architecture test now refuses any tax rate, literal amount, GSTIN, PAN or SAC in commercial code, a second reader of the policy defaults, and an environment setting duplicating a policy (ADR-0069, invariant 76).

## 14. Security validation

- Tenant isolation: deals, billing profiles (incl. the local jurisdiction), terms, periods and invoices are tenant-owned and fail-closed (tests: another tenant's deal is not found, nothing visible without a tenant).
- Commercial configuration is platform-operator only: every tenant user gets 403 on Commercial policies, Tax & invoicing, Billing catalogue, Billing accounts, Approvals, Invoices and Payments (feature tests, browser walkthrough, 6 showcase personas).
- Maker-checker at service and model layers (§11); audit chains verified valid after every MySQL race; signed webhooks (HMAC over the raw body) and per-event idempotency unchanged and passing; suspended tenants: provider events still apply (`RunsForSuspendedTenants`), unchanged.
- Chargebacks never silently dropped (§17). Source URLs `https://` only.
- No secret in source, fixtures, logs or evidence; UI-validation passwords and TOTP secrets were generated into a 0600 scratch file and never printed; the seeder's generated password was redacted from the log as it was written.
- **The leaked demo credential:** re-checked as booleans only: the leaked 12-character demo value (in git history from `d75226e` to `3379c17`, removed in SaaS.2) **still signs in one account in `hcm` and one in `hcm_ux_showcase` (the owner's administrator account)**; it is not in the working tree, the logs or the session scratchpad. Separately, the platform operator account of those development databases accepts the plain showcase fixture word. Correction: the configuration report (`SaaS-7-Configuration-Report.md` §14) named the platform operator account as accepting the leaked credential; that check had read the seeder of the wrong commit and tested the fixture word. This gate re-checked the actual leaked value against every account. No credential was added anywhere.

## 15. Full test results

| | Total | Passed | Failed | Skipped | Assertions |
|---|---|---|---|---|---|
| Full suite (`php artisan test --parallel --processes=2`, SQLite) | 1,400 | 1,295 | 0 | 105 | 16,661 |
| MySQL suite (`tests/MySql`, MySQL 8.4) | 105 | 105 | 0 | 0 | 574 |

- The 105 skipped tests are exactly the MySQL suite (opt-in, run separately).
- New in this gate: `ConfigurationGateTest` (5: configuration-only changes, historical immutability, refusals without price or tax with draft-only billing, selling-entity maker-checker, chargeback flagging), `StatutoryDatasetTest` +1 (a US invoice with state and local legs) and the Texas test rewritten for local jurisdictions, an architecture test (no business value in commercial code), MySQL race 16.
- Adapted: `BillingTestHelpers` (`supplierVersion()`: propose and approve), `BillingPagesTest` (the entity is approved before a series), `BillingSecurityTest` and `FinancialControlsTest` (the extra approval events), `InvoiceTest`, `SettlementTest`, `CommercialPricingTest` (the annual fixed amount).
- SaaS.1–SaaS.7 regression: the full suite covers every earlier phase (entitlements, plans, packaging, subscriptions, billing, tax, payments) and passed unchanged apart from the adaptations above.

The gate's specific proofs:

| Proof | Test |
|---|---|
| Missing price refuses billing | `ConfigurationGateTest` "refuses to bill without a price…"; `CommercialPricingTest` (NO_PRICE_CONFIGURED exception, matrix, `priceFor`) |
| Missing tax refuses invoice issuance | `ConfigurationGateTest` (retired rule: draft stays a draft, `[TAX_CONFIGURATION_MISSING]`); `StatutoryDatasetTest` (all reason codes) |
| Customer deal overrides standard price; does not leak across tenants | `CommercialPricingTest` "bills Client A…" |
| Future configuration activates; expired never falls back | `ConfigurationGateTest` (policy from May, rate from May, rounding from June); `StatutoryDatasetTest` (California 2027; Nevada expiry); `CommercialPricingTest` (policy expiry) |
| Historical configuration immutable | `ConfigurationGateTest` "never changes an issued invoice…" |
| Maker cannot approve own change | price, deal, policy, entity (service and model), tax rule, dataset |
| Markets and currencies independent | `CommercialPricingTest` "keeps markets and currencies independent…" |
| No deployment for changing configuration | `ConfigurationGateTest` "changes Markedge policy and tax rates by configuration alone…" (every change made through operator services; behaviour follows from its date) |
| US state and local aware, EU member-state aware | `StatutoryDatasetTest` (Texas local leg issued on an invoice; EU rates per member state) |

## 16. MySQL results

**105 of 105 passed** (574 assertions) in the final run, incl. the Phase 14 scale test at every size and the 16 billing and configuration races. Races 12–16 also passed in repeated isolated runs while the gate was built. Race 16 is new (§17).

## 17. Concurrency results

Races 12–16 (configuration) on top of races 1–11 (billing), all passing, with invariants after each (no approved-but-unexecuted request; both audit chains valid):
- 12 two proposals of one policy → distinct versions, both accepted (deadlock retry);
- 13 two checkers approve one configuration change → applied once, one audit event;
- 14 overlapping deal windows and two drafts of one deal → one refused each time;
- 15 dataset loaded twice and verified by two checkers → 36 rules, each verified once, one activation event;
- 16 (new) two first proposals of one selling entity → versions 1 and 2, both accepted (deadlock retry); two checkers approve one entity version → applied once, one audit event.

## 18. Mutation results

**17 of 17 gate mutants killed** (on a private copy of the tree), on top of the configuration pass's 40: locality ignored when finding the state rule; local leg skipped; missing locality not refused; customer locality not used at issue; billing profile dropping the locality; a pending selling entity applied; the maker approving their own entity version in the model; an entity version approved after its start; a rejected entity version left pending; numbering from a pending entity; an annual fixed price billed × 12; the applied configuration not frozen on the invoice; a chargeback event silently ignored; the B2B policy replaced by a constant; a hardcoded tax rate in commercial code (architecture test); the rounding policy ignored; and on MySQL, entity proposals without the deadlock retry (race 16 reproduces the deadlock). Five were killed by an error rather than an assertion (issuance refused, or the billing run's line-amount consistency guard)

## 19. Browser / accessibility / visual results

| Check | Result |
|---|---|
| **Browser walkthrough** ([evidence](evidence/SaaS-7-configuration-gate-browser-validation.json)) | **17 of 17 checks** on a disposable `hcm_saas7h_ui_showcase` (showcase seeder + fictional gate data) on 8094, Chromium, two operators with TOTP: the selling entity's new version (a fictional Texas permit) PENDING APPROVAL next to the approved one with its LUT; local tax jurisdiction rules listed and counted per state; entity changes are proposals; registration placeholders show formats, never made-up numbers; the US customer's recorded local jurisdiction; the billing-profile form asks for it for a US customer; a fixed annual deal shown per billing interval, pending approval; the catalogue states the configured notice period; a chargeback event flagged for manual handling; the maker cannot approve their own entity version; the checker approves it (no version left pending) and the deal (then SCHEDULED); no horizontal scroll on a phone; the tenant administrator gets 403 on all commercial pages with no platform link. Database dropped |
| **Accessibility** | axe-core (WCAG 2 A/AA, 2.1 A/AA): **0 violations in 9 states** (tax & invoicing light and dark, propose-entity modal, US billing account, US billing-profile modal, payments light and dark, entity approval as maker, phone tax & invoicing). No console errors. (The configuration pass's 11 states were also 0.) |
| **Visual regression** (8092, frozen-clock showcase rebuilt with the new migration) | **120 passed**, 198 skipped by design, no baseline changed |
| **Browser suite** (Chromium and WebKit) | **74 passed**, 6 skipped by design |
| **Showcase (8090)** | Backed up, migrated additively (new columns only; 1 migration row; every table count identical). All 6 personas sign in (two batches, under the login limiter), their everyday pages answer 200 and the 10 platform pages answer 403 with no platform link. As its operator plus a temporary second operator, inside a rolled-back transaction: the dataset loads and is verified, a selling entity is proposed (PENDING APPROVAL on the page) and approved, a local rule is drafted and verified, every gate page renders, the billing run calculates nothing; every table count identical afterwards. The 8090 server had stopped when the session restarted; it was restarted detached and is running |

## 20. Performance

- Billing run (configuration pass, unchanged by this gate): 38 queries at 200, 1,000 and 3,000 employees, standard and agreed prices alike.
- Tax & invoicing with **10, 100 and 300 local rules: 31 queries each** (flat; rendering time grows with the listed rules, ≈1.7 ms per rule, the local list being capped at 200).
- Configuration pages at 2, 10 and 30 records (configuration pass): Commercial policies 23, Billing catalogue 26, billing account with deals 35.

## 21. Remaining blockers

**Technical: none.** Every gate requirement passes (§5–§20).

Inputs (not technical failures), entered as data without code (§23):

| Blocker | Owner | Unblocks |
|---|---|---|
| B-4 prices per market | Business owner | Any real price |
| B-7 selling entity details, GSTIN, LUT and registrations | Legal / finance | The selling entity version (second operator), numbering, any issued invoice |
| B-8 India SAC (and the INR rate-source policy) | GST adviser | India invoices (a rule version with the SAC) and exports |
| B-9 registrations abroad, US local rates, Greece, other US states | Tax advisers | Consumer supplies abroad and US invoices |
| B-16 retention | Legal | SaaS.9 |

Release blocker (owner action, security): the leaked demo credential still signs in the owner's administrator account in `hcm` and `hcm_ux_showcase` (§14). Rotation is the owner's action and was not done here (no credential was changed, recreated or written anywhere). Before any release or any sharing of these databases: rotate it, and replace the showcase fixture password of the platform operator account. This is not a defect of the SaaS.7 code

## 22. Deferred items

| Item | Why | Visible as |
|---|---|---|
| Chargeback (dispute) processing (B-12) | No live provider; to be built with Razorpay's activation (needs dispute evidence, reversal accounting and maker-checker) | Events stored with outcome `chargeback_not_processed`, logged as warnings, flagged "chargeback not processed: handle manually" on Payments (ADR-0069). Not marked implemented |
| Razorpay refund webhooks | Refund status comes from the API response and a server-side refresh | `refund_event_not_processed` |
| Live Razorpay | Test mode only, by instruction | Enabled only with `rzp_test_` keys, never in production |
| Debit notes | B-12 decision | Underbilling is a new invoice |
| Approval thresholds and role-based approval | B-13: no thresholds | All listed operations always need a second operator |
| Tax-inclusive pricing | B-5: prices are tax-exclusive | The policy exists; "true" refuses issue rather than mis-tax |
| Reference identifier lists as data (GST state codes, US states, EU membership, currency catalogue) | Identifiers used by the determination algorithms; change only by statute or treaty | A change is a reviewed code change in the jurisdiction module (§13 E) |
| Customer billing profiles and number series under maker-checker | Outside B-13's scope (customer master data, numbering windows) | Single operator, audited |

## 23. Exact steps required to enter future commercial / legal values

Full runbook: [`docs/operations/commercial-configuration-runbook.md`](../operations/commercial-configuration-runbook.md). In short (maker = one operator, checker = another; every step from an effective date; nothing needs a developer):

1. **Selling entity (B-7)** — Tax & invoicing → *Propose selling entity version* (legal name, address, state, GSTIN; registrations one per line: `IN_LUT <reference> <from> <to>`, `GB_VAT …`, `EU_OSS_NON_UNION …`, `AE_TRN …`, `US_STATE:US-XX …`) → checker approves *Selling entity change* → *New number series* (India ≤ 16 characters).
2. **Prices (B-4)** — Billing catalogue → *New market* (code, currency) → *New price* (plan version, market, interval, PEPM or fixed per interval) → *Draft amount* (amount, minimum) → *Request publication* → checker approves → the price matrix shows CURRENT.
3. **Customer deals** — Billing accounts → *New negotiated price* (subscription, plan version, market, interval, basis, contract dates and reference) → *Draft deal amount* (amount, minimum, discount) → *Request deal publication* → checker approves → *Set billing terms* (the agreed price is offered first).
4. **Policy** — Commercial policies → *Propose change* (setting, value, from, optional to) → checker approves.
5. **India SAC (B-8)** — Tax & invoicing → *New rule version* of `IN-GST-9983-18` with `sac=<code>`, the date and the adviser's reference → *Submit for review* → checker *Verify rule*. Exports also need the `IN_LUT` (step 1) and, at issue, the INR rate, source and date.
6. **Foreign customers (B-9)** — registrations abroad on the entity (step 1); Greece: checker *Verify rule* or *Reject rule*; another US state: *Draft tax rule* (US sales tax, state, outcome `saas`, rates, taxable share, required permit, `local_rates_required`) → submit → verify; **US local rates**: *Draft tax rule* with the *Local tax jurisdiction* code from the rate source (components `CITY`/`COUNTY`/`DISTRICT`) → submit → verify, and record the same code on the customer's billing profile.
7. **New law** — *New rule version* from the law's date (pre-filled), or ship a new statutory dataset version: *Load statutory dataset* (maker) → *Verify statutory dataset* (checker).
8. **Check** — Commercial policies (values, sources, history), Tax & invoicing (states, coverage, local rules), Billing accounts (price source), Invoices (why a draft cannot be issued), Payments (flagged chargebacks).

## Status

**A. SAAS.7 COMPLETE.**

The architecture and every required validation gate pass: prices, customer deals, minimums, discounts, payment terms and billing policy, tax rates and rules, SAC, place of supply, reverse charge, export conditions, registrations and the LUT, US state and local rules and the UK/EU/UAE configuration are configuration, changed by one operator, approved by another, applied from an effective date, and never rewrite an issued invoice. Missing values are refused with explicit reasons, never assumed. Full suite, MySQL suite, concurrency, mutation, browser, accessibility, visual, performance and tenant-isolation/security checks pass (§15–§20).

Not technical failures, and not closed by this gate:
- **Real commercial invoicing** waits for inputs entered as data: prices (B-4), the selling entity and its registrations including the LUT (B-7), India's SAC (B-8), registrations abroad and US local rates (B-9) — runbook §23.
- **Release blocker (owner action):** the leaked demo credential still signs in the owner's administrator account in the development databases `hcm` and `hcm_ux_showcase` (§14); rotate it (and the showcase operator's fixture password) before any release or sharing.

SaaS.8 was not started. Nothing was pushed, merged or deployed.
