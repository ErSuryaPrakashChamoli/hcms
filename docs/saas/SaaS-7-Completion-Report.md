# SaaS.7 — Completion Report (approved decisions B-1 to B-15)

**Phase:** SaaS.7 finalization / implementation gate · **Date:** 8 October 2026 · **Branch:** `feature/oct_1_phase_1`

**SAAS.7 STATUS: BLOCKED.** Every approved decision is implemented and validated, except one B-12 detail that waits for Razorpay activation (chargeback events, §3). SaaS.7 cannot be called complete because four decisions remain open: B-4 (prices, owner), B-7 (entity details, legal), B-8 (India GST incl. export of services, tax adviser) and B-9 (foreign customer-side tax, tax advisers). Until they are resolved no real price exists, no foreign-customer invoice can be issued, and no invoice should be issued to a real customer.

| | |
|---|---|
| Starting commit | `c394d16` (docs: SaaS.7 commercial decision resolution) |
| Ending commit | The documentation commit that adds this report (§16); the code is in the commit before it |
| Deployment | NOT DEPLOYED |
| Push | NOT PUSHED |
| Merge | NOT MERGED |

Supporting documents:

| Document | Content |
|---|---|
| [Commercial decisions](SaaS-7-Commercial-Decisions.md) §14 | The owner's clarifications in this pass and what each decision became |
| [Decision register](../architecture/decision-register.md) | ADR-0049 to ADR-0057 (completion decisions) |
| [Security invariants](../architecture/security-invariants.md) | 59–65 |
| [Developer's map](../architecture/saas-7-billing.md) | Shape, rules for code, locks, tests and gotchas, updated |
| [Operations runbook](../operations/queue-and-scheduler.md) | `peopleos:billing:run` |
| [Browser evidence](evidence/SaaS-7-completion-browser-validation.json) | Browser checks and axe results |
| [Earlier SaaS.7 report](SaaS-7-Billing-GST-Payments-Report.md) | The architecture this pass extends |

## 1. Decisions B-1 to B-16

| Decision | Status (unchanged) | In this pass |
|---|---|---|
| B-1 Pricing basis: PEPM + optional minimum | APPROVED | Implemented |
| B-2 Billable quantity: monthly peak employed | APPROVED | Implemented |
| B-3 Monthly arrears, annual advance + true-up, calendar months, proration B | APPROVED | Implemented (with the owner's clarifications, Commercial Decisions §14) |
| B-4 Markets and amounts | Markets approved; **amounts PENDING USER** | Structure for the five markets implemented; no amount created |
| B-5 Tax-exclusive, B2B only | APPROVED | Implemented (B2B launch rule) |
| B-6 Trial only | APPROVED | No change needed (trials are not billed) |
| B-7 Selling entity | **PENDING LEGAL** | Nothing created (operator data once known) |
| B-8 India GST incl. export and INR reporting value | **PENDING TAX ADVISER** | Not implemented: exports stay refused |
| B-9 Foreign jurisdictions | **PENDING TAX ADVISER** | Not implemented: foreign customers stay refused |
| B-10 Bank transfer now, Razorpay next | APPROVED | Implemented (Razorpay adapter in test mode only) |
| B-11 Net 15, TDS-aware, no other partials | APPROVED | Implemented |
| B-12 Credit notes and refunds; debit notes deferred | APPROVED | Implemented, except chargeback events (§3) |
| B-13 Maker-checker, no thresholds | APPROVED | Implemented |
| B-14 INR settlement through Razorpay | APPROVED | Implemented (settlement snapshot, new ADR-0054) |
| B-15 Grandfather to renewal, 30 days' notice | APPROVED | Implemented |
| B-16 Retention | PENDING LEGAL (SaaS.9) | Deferred to SaaS.9 |

## 2. Implemented

| # | Item | How |
|---|---|---|
| 1 | Billing periods | `billing_periods` (tenant-owned; unique subscription × kind × start), calculated by `BillingPeriods::run()`; kinds `monthly_arrears`, `annual_advance`, `annual_true_up`; frozen quantity, evidence and amount; `exception` for anything it cannot bill safely |
| 2 | Monthly peak employee snapshots | `BillableQuantity`: rebuilt from `employee_lifecycle_transitions` per billable day; evidence (method, peak day, employee ids and their SHA-256, daily counts) frozen on the period and on the invoice line (`quantity_evidence`) |
| 3 | Minimum quantity | `plan_price_versions.minimum_quantity`; monthly quantity = max(peak, minimum); annual commitment ≥ minimum |
| 4 | Monthly billing | In arrears, after each calendar month |
| 5 | Annual billing | In advance at each 12-month term start: commitment × unit × 12 |
| 6 | Annual monthly true-up | Each month after it ends: max(0, peak − commitment) × the same unit; a zero true-up is recorded as `nothing_due` |
| 7 | Calendar-month anchoring | Monthly re-pins start on the 1st; annual terms start on the 1st; annual terms change only at renewal |
| 8 | Partial first/last month proration | Billable days ÷ days in the month, only when part of a month is billable (trials not billed, grace billed), rounded once half up (`Money::prorated`) |
| 9 | B2B-only launch rule | `peopleos.billing.b2b_only` (on): billing profiles refuse consumers |
| 10 | TDS-aware settlement | `TdsSettlement`: operator-declared TDS (amount from the customer's statement, no rate), certificate reference; amount due = total − credit notes − TDS; `partially_paid` only while the certificate is pending; a held short payment is matched when a declaration makes it exact |
| 11 | Credit notes | `CreditNotes`: full (cancellation) or partial; own number series (`document_type = credit_note`); tax at the invoice's original components, rates and rounding; never more than the invoice |
| 12 | Refunds against credit notes | `Refunds`: only against an issued credit note, from a succeeded payment of the same invoice, within what is left of both; provider API after commit (idempotent) or an operator-recorded bank transfer |
| 13 | Maker-checker | `financial_approvals`, `FinancialApprovals`, `ApprovalDesk`, the Approvals page; maker, checker, both reasons, times, before, after, correlation key |
| 14 | Price publication approval | `BillingCatalog::requestPublication()` → executed on approval (`publishPriceVersion` removed) |
| 15 | Cancellation approval | A full credit note (the invoice's cancellation) needs approval |
| 16 | Write-off approval | Unpaid invoice write-off and payment-exception acceptance or write-off need approval |
| 17 | Price-change notice records | `price_change_notices`, recorded ≥ 30 days ahead at a period start or renewal; the re-pin worklist |
| 18 | Existing subscriber price pinning | Unchanged pinning, plus: an increase of the same price needs a notice; decreases and customer-agreed plan or interval changes do not |
| 19 | Multi-market price structure | Five markets (IN, US, UK, EU, AE) representable, each with its own prices in its own currency, independently versioned; never converted |
| 20 | Multi-currency invoices | Periods and invoices in the market currency (USD, GBP, EUR, AED, INR); amounts never converted |
| 21 | INR settlement through the provider abstraction | `ProviderPaymentUpdate::$settlement`: Razorpay `base_amount`/`base_currency` or the INR a bank credited, recorded once with the implied rate (reporting only) |
| 22 | Razorpay adapter (test mode only) | `RazorpayProvider`: orders, fetch, HMAC-verified webhooks, refunds; enabled only with `rzp_test_` keys outside production |

**Interpretations within the approved decisions** (implementation choices the owner may revisit):
- A price increase needing notice is a later version of the *same* price (same plan version, market and interval) with a higher unit amount or minimum. A change of plan or interval is treated as agreed with the customer and needs no notice; it still starts at the next period (monthly) or renewal (annual).
- An annual term year is billed when it starts only if the subscription is active or in grace that day; annual terms cannot start during a trial.
- A plan change during an annual term (allowed by SaaS.6) leaves the terms on the old plan until renewal; the run records those true-up months as exceptions rather than billing another plan's price.
- Flat prices stay representable but the run never bills them (B-1 approved PEPM only); a period on a flat price is an exception.
- Customer TDS applies to INR invoices from an Indian supplier to an Indian customer.
- The settlement rate stored is the one implied by the provider's own figures (settlement ÷ amount, 10 decimals), for reporting only.

Also: net 15 default due date (B-11); the billing run command, its schedule (off unless enabled) and runbook entry; operator page actions for every new operation; architecture allow-lists, invariants and ADRs.

## 3. Not implemented

| Item | Why |
|---|---|
| Issuing invoices to real customers | B-4 (no price), B-7 (no entity details), B-8 (India GST not advised) |
| Export of services outcome (zero-rated with LUT) and the INR reporting value of foreign invoices | B-8, tax adviser. Foreign customers of the Indian entity stay refused at issue |
| Customer-side treatments in the US, UK, EU and UAE | B-9, tax advisers |
| Debit notes | Deferred by B-12 (underbilling is a new invoice) |
| Chargeback (dispute) events | B-12 records chargebacks as exceptions. No live provider is active and Razorpay dispute events are not interpreted yet: they are stored and ignored. To be added with Razorpay activation |
| Razorpay refund webhooks | Refund status comes from the API response and a server-side refresh; `refund.*` events are stored and ignored |
| Credit-note document layout (PDF) | Format pending B-8; credit notes are listed with their totals and frozen snapshot |
| Live Razorpay | Test mode only, by instruction and design; activation needs merchant onboarding and a decision |
| Retention | B-16, SaaS.9 |

## 4. Tax status

- The engine and the India GST module are unchanged. **No production tax rule exists**: every rate in tests and the UI walkthrough is fictional.
- Missing or pending tax configuration never becomes 0 % tax: issue is refused, the draft stays a draft, no number is used (critical test 22).
- India's status reads `pending_tax_review` until a verified rule exists, `configured` after; no jurisdiction is ever `supported`.
- Foreign customers of the Indian entity are refused at issue ("export of services is not configured"), and the US, UK, EU, UAE, Canada, Australia and Singapore have no customer-side module (critical test 23).
- Credit notes reuse the invoice's frozen tax (components, rates, rounding), never today's rule.

## 5. Payment status

- **Bank transfer** (operator-recorded): working; may record the INR the bank credited.
- **Sandbox** (non-production): unchanged, now with refunds.
- **Razorpay**: adapter complete in **test mode only**; not enabled anywhere (no keys); no merchant onboarding; no real money.
- Settlement: exact amount due; TDS-aware; every other short or over payment is an exception, accepted or written off only with a second operator.
- Refunds only against credit notes; dual control.

## 6. International markets

- India, US, UK, EU and UAE are representable as markets with prices in INR, USD, GBP, EUR and AED, all sold by the Indian entity (B-7 intent). Each market's price is its own: an INR change never moves another market, and nothing derives a price by exchange rate.
- Billing periods and invoices are in the market currency; payments are in the invoice currency; Razorpay settles INR, recorded beside the payment.
- **No foreign invoice can be issued** until B-8 (export) and B-9 (customer-side) are advised.

## 7. The 24 critical tests

| # | Test | Where |
|---|---|---|
| 1 | Monthly peak quantity | `BillingRunTest` "bills the monthly peak employed count…" |
| 2 | Rehire counted once | `BillingRunTest` "counts a rehired employee once…" |
| 3 | Preboarding excluded | `BillingRunTest` (critical 1, 3, 4) |
| 4 | Exit handling | `BillingRunTest` (exit day counted, the day after not) |
| 5 | Annual committed quantity | `BillingRunTest` "bills annual terms in advance…" |
| 6 | Monthly true-up | `BillingRunTest` (same test: 200.00 above the commitment, then nothing due) |
| 7 | First-month proration | `BillingRunTest` "prorates only a partial first or last month…" (16/30, half up) |
| 8 | Last-month proration | Same test (grace 10/30, then expired) |
| 9 | Price version pinning | `BillingRunTest` "keeps an existing subscriber on the pinned price version…" |
| 10 | 30-day price notice | `PriceNoticeTest` |
| 11 | Market-specific prices | `SettlementTest` "prices the five markets each in its own currency…" |
| 12 | Multi-currency invoices | Same test (a USD draft from the USD price) |
| 13 | INR settlement | `SettlementTest` "records the INR settlement…" |
| 14 | FX snapshot | Same test (rate, source, write-once; bank advice too) |
| 15 | TDS reconciliation | `SettlementTest` "settles with declared customer TDS…" |
| 16 | Credit note | `FinancialControlsTest` "credits part of an invoice at its original rates…" |
| 17 | Refund | `FinancialControlsTest` "refunds only against an approved credit note…", `RazorpayTest` |
| 18 | Maker-checker | `FinancialControlsTest` "carries out a financial operation only when a second operator approves…" |
| 19 | Maker cannot self-approve | `FinancialControlsTest` "never lets the maker approve, and refuses every bypass…" (service and model layers) |
| 20 | Duplicate webhook | `RazorpayTest` "applies each Razorpay event once…" |
| 21 | Tenant isolation | `PriceNoticeTest` "keeps billing periods, notices, credit notes, refunds and TDS claims inside their tenant…" |
| 22 | Tax configuration pending behaviour | `SettlementTest` "never turns missing or pending tax configuration into 0 % tax…" |
| 23 | Unsupported jurisdiction behaviour | Same test (US and AU customers refused; nothing `supported`) |
| 24 | Razorpay sandbox behaviour | `RazorpayTest` (test mode only; orders; signed webhooks; fetch; refunds) |

## 8. Tests

| | Total | Passed | Failed | Skipped | Assertions |
|---|---|---|---|---|---|
| Full suite (`php artisan test --parallel --processes=2`, SQLite) | 1373 | 1273 | 0 | 100 | 16,222 |
| MySQL suite (`tests/MySql`, MySQL 8.4) | 100 | 99 + 1 on re-run | 0 after re-run | 0 | 533 |

- The 100 skipped tests in the full suite are exactly the MySQL suite (opt-in, run separately).
- New in this pass: 23 feature tests (`BillingRunTest` 8, `FinancialControlsTest` 5, `SettlementTest` 4, `RazorpayTest` 4, `PriceNoticeTest` 2) covering the 24 critical cases (§7), and 5 MySQL races (§9).
- Adapted: `PricingTest`, `BillingPagesTest`, `PaymentTest`, `BillingSecurityTest`, `BillingProfileTest`, `InvoiceTest` (publication by maker-checker, exception resolution by approval, B2B rule, notices, net 15) and the architecture allow-lists (new models, page, command; only the Razorpay adapter uses HTTP).
- An earlier full run in this pass had one failure outside billing: `ServiceDeskScaleTest` counted 10 instead of 8 queries for its API call under the parallel run. It passes alone and passed in the final run. Same clock-dependent `last_used_at` write as the MySQL scale test (§9).

## 9. Validation

| Check | Result |
|---|---|
| **MySQL suite** (MySQL 8.4, disposable concurrency database) | 100 tests: 99 passed in one run; the one failure was a pre-existing Phase 14 scale test whose API page counted 16 queries instead of 15 at one size, and it passed when re-run (15 at every size). Cause: `AuthenticateApiKey` saves `last_used_at` at second precision, so whether a request writes depends on the clock ticking between two measurements. Not billing code |
| **Concurrency** | 11 billing races, all passing: the 6 existing plus 5 new (race 7 two overlapping billing runs → one period and one draft; race 8 two checkers approve one request → executed once; race 9 two credit notes at once → consecutive numbers, no gap; race 10 TDS declaration vs the net payment → paid once; race 11 two refund approvals exceeding the credit note together → one refused). Invariants after each race: gap-free credit-note numbers, credit notes ≤ invoice, refunds ≤ credit note, no approved-but-unexecuted request, both audit chains valid |
| **Mutation** | **31 of 31 killed**, on a private copy of the tree: 30 mutants of the new rules against the feature tests (maker-checker in the service and the model, executor bypass and double execution, exit day, latest recorded transition, everyone counted, minimum, proration and its rounding, trial billed, grace not billed, true-up, annual ×12, mid-term change, notice required, notice length, credit-note tax, credited status, refund cap, short payment, TDS in the amount due, settlement, Razorpay signature and live keys, B2C, net 15, frozen period, write-off of a paid invoice, failed attempt closing an order), and 1 on MySQL (refund sums without the locking read: race 11 then refunds 110.00 against a 107.50 credit note). Two were killed by an error rather than an assertion (C14 by the internal line-amount consistency guard, C27 by the missing due date) |
| **Migration** | Migrate, roll back one step, re-apply on a throwaway MySQL database: 6 tables and 7 columns removed and restored; database dropped |
| **Browser** ([evidence](evidence/SaaS-7-completion-browser-validation.json)) | 26 of 26 checks on a disposable `hcm_saas7c_ui_showcase` (showcase seeder + fictional billing data) on 8094, Chromium, two operators with TOTP: PEPM prices with minimums; publication is a request; the maker sees no Approve on their own request; requests show payload, before, after, maker and correlation key; billing periods with peak, days and evidence; the notice worklist; annual advance and true-up with a 17/31 stub; partially paid while the TDS certificate is pending, paid once recorded through the UI; frozen quantity evidence; credit notes in their own series; a USD invoice refused at issue (export pending); the checker approves a price publication and a full credit note (credited) and rejects a write-off; no horizontal scroll on a phone; the tenant administrator gets 403 on the Approvals page and all billing pages, with no platform link. Database dropped afterwards |
| **Accessibility** | axe-core (WCAG 2 A/AA, 2.1 A/AA): **0 violations in 14 states** (catalogue, approvals list, approval detail as maker, as checker and dark, approve modal, billing account periods, invoice with settlement, invoice dark, TDS certificate modal, credit note modal, payments, phone approvals, phone billing account). No console errors |
| **Visual** (8092, frozen-clock showcase rebuilt from scratch with the new migration) | **120 passed**, 198 skipped by design, no baseline changed. (Two earlier attempts in this pass were invalid and are not counted: one overrode the persona password, one ran WebKit without its launcher and several runs on one preparation, which shifts the audit-event count the admin audit screenshot shows) |
| **Browser suite** (Chromium and WebKit) | **74 passed**, 6 skipped by design |
| **Showcase (8090)** | Backed up, migrated additively (6 new empty tables, 1 migration row; every other table count identical). All 6 personas sign in and their everyday pages answer 200; the 9 platform commercial pages (incl. Approvals) answer 403 with no platform link. (The executive persona's first sign-in, the sixth in a minute from one address, did not complete — most likely the login rate limit — and passed when re-run.) The existing operator rendered every billing page and ran the billing run inside a rolled-back transaction: empty states, nothing calculated; every table count identical before and after. 8090 kept running |
| **Performance** | Billing run, one monthly period: **39 queries at 200, 1,000 and 3,000 employees**; time linear (≈0.27 ms per employee; 3,000 employees in 0.8 s, host busy). New pages, query counts flat as records grow (2, 10, 30): Approvals list 6, billing account with periods and notices 37, invoice detail with credit notes 37 (an N+1 on the approvals list, one query per maker name, was found and fixed in this pass). No HCM, entitlement or subscription code changed, so their query counts are unchanged by construction (architecture tests prove no such path references billing) |
| **Security** | §11 |

## 10. Database and migration

One additive migration, `2026_10_26_100001_complete_saas7_billing_tables`:
- new tables: `billing_periods`, `price_change_notices`, `financial_approvals`, `credit_notes`, `refunds`, `invoice_tds_claims`;
- new columns: `plan_price_versions.minimum_quantity`; `subscription_billing_terms.committed_quantity`, `price_notice_id`; `invoice_lines.billing_period_id`, `days_billed`, `days_in_period`, `quantity_evidence`; `invoices.closed_at`, `closure_approval_id`; `payments.provider_transaction_reference`, `settlement_fx_rate`, `settlement_fx_source`, `settlement_recorded_at`.

It writes no row. On a throwaway MySQL 8.4 database it migrated, rolled back (6 tables and 7 columns removed) and re-applied cleanly; the database was dropped.

## 11. Security

- Dual control: invariant 59; the maker is refused in the service (`FinancialApprovals::approve`), in the model (`FinancialApproval` guard), and every executor refuses anything but an approved, unexecuted request of its action (`claim()`). Proven at the service and model layers, not only in the UI.
- Every new operation runs `OperatorChange` (platform operator + reason) in its service; every tenant user is refused the Approvals page and all billing pages.
- New tenant records are `BelongsToTenant` (fail-closed); approvals are platform records with `subject_tenant_id` (platform invariant 22).
- Webhooks: Razorpay signatures are HMAC-SHA256 of the raw body, compared in constant time; `X-Razorpay-Event-Id` is unique per provider (the replay defence, as the signature has no timestamp); a different body under a known id is refused (409).
- No secret is stored or logged: Razorpay keys come only from the environment, are fictional `rzp_test_` values in tests, and HTTP is faked in every test.
- Under MySQL's repeatable read, a plain read after a lock wait can return the old snapshot. Sums of credit notes, TDS claims and refunds are therefore locking reads (`sharedLock()`). Race 11 proves it for refunds.
- The leaked demo credential noted in earlier phases: still active in `hcm` and `hcm_ux_showcase` (re-checked as booleans only: the account exists, the old credential works). Rotation is the owner's action.

## 12. Open blockers

| Blocker | Owner | Unblocks |
|---|---|---|
| B-4 prices per market | Business owner | Any real price, period amount or invoice |
| B-7 entity details and registrations | Legal / finance | The supplier profile, number series and any issued invoice |
| B-8 India GST (rate, SAC, place of supply, rounding, series, export with LUT, INR reporting value, credit-note format) | GST adviser | Any India invoice; every foreign invoice from the Indian entity |
| B-9 customer-side treatments abroad | Tax advisers | Invoices to US, UK, EU and UAE customers |

## 13. Deferred decisions

- B-16 retention (legal): SaaS.9.
- Debit notes (B-12): deferred by decision.
- Recording customer acceptance of a price change (B-15): a later option.
- Amount thresholds and role-based approval (B-13): later (D-15, SaaS.10).
- Razorpay activation (merchant onboarding, live keys, dispute and refund events): a separate decision after onboarding.
- Destination tax regimes (registration abroad): only if an adviser requires it (B-9).

## 14. Process notes

- The four clarifying questions (annual stub, annual changes, approval scope, billing-run scope) were asked before any code and answered by the owner; the answers are in Commercial Decisions §14.
- Facts about Razorpay used by the adapter (signature, event id header, orders by receipt, an order's payments, refunds and their receipt, statuses, `base_amount`) were checked against Razorpay's documentation; no other provider, price, tax, legal or registration fact was assumed. Every amount, rate, GSTIN and key in tests and the UI walkthrough is fictional.
- Under MySQL's repeatable read, sums read after a row-lock wait could miss rows committed meanwhile. The amount-due, already-credited and already-refunded sums are therefore locking reads; the MySQL mutant proves race 11 fails without them.
- The disposable UI database's seeder printed its generated account password into a local log once; it was scrubbed from the scratchpad logs and the database was dropped. No secret was committed or written to the repository.
- Pint was applied to the new files and to the lines this pass added in existing files; existing files were not reformatted (the baseline is not Pint-clean).

## 15. Files

**New (32):**
- `app/Console/Commands/RunBilling.php`
- `app/Domain/Billing/Enums/ApprovalAction.php`
- `app/Domain/Billing/Enums/ApprovalStatus.php`
- `app/Domain/Billing/Enums/BillingPeriodKind.php`
- `app/Domain/Billing/Models/BillingPeriod.php`
- `app/Domain/Billing/Models/CreditNote.php`
- `app/Domain/Billing/Models/FinancialApproval.php`
- `app/Domain/Billing/Models/InvoiceTdsClaim.php`
- `app/Domain/Billing/Models/PriceChangeNotice.php`
- `app/Domain/Billing/Services/BillableQuantity.php`
- `app/Domain/Billing/Services/BillingPeriods.php`
- `app/Domain/Billing/Services/CreditNotes.php`
- `app/Domain/Billing/Services/FinancialApprovals.php`
- `app/Domain/Billing/Services/PriceNotices.php`
- `app/Domain/Payments/Models/Refund.php`
- `app/Domain/Payments/Providers/RazorpayProvider.php`
- `app/Domain/Payments/Services/ApprovalDesk.php`
- `app/Domain/Payments/Services/Refunds.php`
- `app/Domain/Payments/Services/TdsSettlement.php`
- `app/Domain/Payments/Support/ProviderRefund.php`
- `app/Domain/Payments/Support/RefundStart.php`
- `app/Filament/Pages/PlatformApprovalsPage.php`
- `database/migrations/2026_10_26_100001_complete_saas7_billing_tables.php`
- `docs/saas/SaaS-7-Completion-Report.md`
- `docs/saas/evidence/SaaS-7-completion-browser-validation.json`
- `resources/views/filament/pages/platform-approvals.blade.php`
- `tests/Feature/Billing/BillingRunTest.php`
- `tests/Feature/Billing/FinancialControlsTest.php`
- `tests/Feature/Billing/PriceNoticeTest.php`
- `tests/Feature/Billing/RazorpayTest.php`
- `tests/Feature/Billing/SettlementTest.php`
- `tests/MySql/BillingCompletionConcurrencyTest.php`

**Changed (47):**
- `app/Domain/Audit/Enums/AuditAction.php`
- `app/Domain/Billing/Enums/BillingInterval.php`
- `app/Domain/Billing/Enums/InvoiceStatus.php`
- `app/Domain/Billing/Enums/PricingBasis.php`
- `app/Domain/Billing/Models/Invoice.php`
- `app/Domain/Billing/Models/InvoiceLine.php`
- `app/Domain/Billing/Models/PlanPriceVersion.php`
- `app/Domain/Billing/Models/SubscriptionBillingTerm.php`
- `app/Domain/Billing/Services/BillingCatalog.php`
- `app/Domain/Billing/Services/BillingDirectory.php`
- `app/Domain/Billing/Services/BillingProfiles.php`
- `app/Domain/Billing/Services/BillingTerms.php`
- `app/Domain/Billing/Services/InvoiceSeries.php`
- `app/Domain/Billing/Services/Invoices.php`
- `app/Domain/Billing/Support/InvoiceLineInput.php`
- `app/Domain/Payments/Contracts/PaymentProvider.php`
- `app/Domain/Payments/Models/Payment.php`
- `app/Domain/Payments/Providers/ManualBankTransferProvider.php`
- `app/Domain/Payments/Providers/SandboxProvider.php`
- `app/Domain/Payments/Services/PaymentReconciler.php`
- `app/Domain/Payments/Services/Payments.php`
- `app/Domain/Payments/Services/ProviderRegistry.php`
- `app/Domain/Payments/Support/ProviderPaymentUpdate.php`
- `app/Filament/Pages/PlatformBillingAccountsPage.php`
- `app/Filament/Pages/PlatformBillingCatalogPage.php`
- `app/Filament/Pages/PlatformInvoicesPage.php`
- `app/Filament/Pages/PlatformPaymentsPage.php`
- `app/Support/Money/Money.php`
- `config/peopleos.php`
- `docs/architecture/decision-register.md`
- `docs/architecture/saas-7-billing.md`
- `docs/architecture/security-invariants.md`
- `docs/operations/queue-and-scheduler.md`
- `docs/saas/SaaS-7-Commercial-Decisions.md`
- `resources/views/filament/pages/platform-billing-accounts.blade.php`
- `resources/views/filament/pages/platform-billing-catalog.blade.php`
- `resources/views/filament/pages/platform-invoices.blade.php`
- `resources/views/filament/pages/platform-payments.blade.php`
- `routes/console.php`
- `tests/Feature/Architecture/ArchitectureTest.php`
- `tests/Feature/Billing/BillingPagesTest.php`
- `tests/Feature/Billing/BillingProfileTest.php`
- `tests/Feature/Billing/BillingSecurityTest.php`
- `tests/Feature/Billing/BillingTestHelpers.php`
- `tests/Feature/Billing/InvoiceTest.php`
- `tests/Feature/Billing/PaymentTest.php`
- `tests/Feature/Billing/PricingTest.php`

## 16. Commits

| Commit | Content |
|---|---|
| `5800499` | Code, tests, migration, runbook (73 files) |
| The commit that adds this report | Documentation: this report, browser evidence, Commercial Decisions §14, ADR-0049..0057, invariants 59–65, developer's map |

Nothing is pushed, merged or deployed.
