# SaaS.7 — Billing, tax and payments (global-ready): the developer's map

Full report: `docs/saas/SaaS-7-Billing-GST-Payments-Report.md`; completion pass (approved decisions B-1 to B-15): `docs/saas/SaaS-7-Completion-Report.md`. Design and decisions: `docs/saas/SaaS-7-Baseline.md`, `docs/saas/SaaS-7-Commercial-Decisions.md`. What it reads: `saas-6-subscriptions.md` (subscriptions), `saas-4-plans.md` (plan versions).

## The shape

```
app/Support/Money           Currency (ISO-4217 catalogue, minor units) · Money (integer minor units) · MoneyFormatter (intl, display only)
app/Domain/Tax              jurisdiction-neutral: regimes, treatments, verified rules, determination, pure calculator
   └─ Jurisdictions/India   GST states, GSTIN check, determination (intra-state / intra-UT / inter-state), presentation rows
app/Domain/Billing          markets, prices + versions, supplier profiles, number series   (platform catalogue)
                            financial approvals (maker-checker, platform)
                            billing profiles, billing terms, price notices, billing periods, invoices + lines + tax lines,
                            credit notes, TDS claims   (tenant-owned, fail-closed)
                            BillableQuantity (monthly peak from lifecycle history) · BillingPeriods (the billing run)
app/Domain/Payments         provider contract, manual + sandbox + Razorpay (test mode) providers, payments and refunds (tenant),
                            provider events (platform), reconciler, webhook intake, tenant-aware job, sweep,
                            TdsSettlement, ApprovalDesk (approves and executes maker-checker requests)

Payments ──► Billing ──► Tax ──► Support/Money          Billing ──► Subscriptions, plan versions (read only)
Nothing that authorises, no HCM module, the entitlement engine and the subscription lifecycle depend on Billing, Tax or Payments.
```

## Rules for code

| Rule | Why |
|---|---|
| Change billing data only through its services: `BillingCatalog`, `SupplierProfiles`, `InvoiceSeries`, `TaxRules`, `BillingProfiles`, `BillingTerms`, `Invoices`, `Payments`. They run `OperatorChange::assert()` (operator + reason) and audit via `BillingAudit` | Model guards refuse edits of anything final; mass updates would bypass them |
| Money is `Money` (int minor units + `Currency`). Never `float`, `round()` or `number_format()` in Billing, Tax, Payments or Support/Money; rates are exact decimal strings; rounding goes through a named `RoundingMode` | Architecture test (MoneyFormatter's display cast is the one exception) |
| Never convert currencies in billing. No code there reads `exchange_rates` or `CurrencyRates` | Architecture test; option A (billing currency = payment currency) |
| Country-specific tax code lives only in `Tax/Jurisdictions/{Country}` and is wired only by `TaxRegistry`. No GST-specific code (GSTIN, CGST, IN_GST…) outside the Tax domain | Architecture test |
| Tax determination throws `TaxUnavailableException` rather than guess. Never add a default rate, a fallback regime or an "assume domestic" branch | Fail closed: an invoice is refused, never mis-taxed |
| A rule applies only once `verified` by an operator other than its author and submitter | Maker-checker, as statutory rules |
| Read invoice lines, tax lines and payments inside the invoice's tenant (`runAs`). Cross-tenant lists only via `BillingDirectory` / `PaymentDirectory` | Fail-closed scope: outside a tenant they return nothing (this caught two bugs in development) |
| A payment succeeds only through `PaymentReconciler::apply()`, from a verified provider event, a server-side `fetch()`, or `Payments::recordBankTransfer()` | No browser or return URL ever confirms a payment |
| Provider SDK fields stay in the provider adapter and the encrypted event payload; never a provider column on `payments` or `invoices` | Provider replaceable without touching invoices, tax, subscriptions or entitlements |
| Billing never feeds entitlements, authorisation, subscriptions or HCM | ADR-0048; payment failure never denies anything |
| A financial operation that needs dual control (price publication, credit note, refund, write-off, exception resolution) has a `request…()` method for the maker and an `execute…()` method that starts with `FinancialApprovals::claim()`. Only `ApprovalDesk::approve()` calls executors | ADR-0052; the model refuses self-approval and second executions |
| A billed quantity is never recalculated: read it from the `billing_periods` row or the invoice line's `quantity_evidence` | ADR-0049/0050; a correction is a credit note |
| Under the invoice (or payment) lock, read sums of credit notes, TDS claims and refunds with `sharedLock()` | MySQL repeatable read: a plain read can return the snapshot from before the lock wait (found by race 11) |
| Settlement (what reached Markedge) goes only into the payment's `settlement_*` fields; never change an invoice or payment amount for it | ADR-0054 |

## Locks

| Change | Serialised on |
|---|---|
| Issue an invoice | The invoice row, then the open number series covering the day (`FOR UPDATE`); number, tax lines, totals and snapshots in one transaction (gap-free) |
| Reconcile a payment | The invoice row, then the payment row (always in that order) |
| Billing terms | The tenant's commercial lock (`TenantCommercialLock`), serialised with subscription changes |
| Billing profile versions | The tenant's latest profile row; unique `(tenant_id, version)` backstop |
| Price versions | The `plan_prices` row; one-draft and one-start-date generated unique columns |
| Tax rules | The scope's rows; unique version per scope |
| Provider events | Unique `(provider, event_id)`; leased claim (`claimed_until`) when applied |
| One open provider payment per invoice | Generated `open_invoice_id` unique (operator-recorded transfers never hold it) |
| Billing periods | Unique `(subscription_id, kind, period_start)`; the period and its draft in one transaction |
| Approvals | The approval row (`FOR UPDATE`) in the approving transaction, then what the executor locks; unique `correlation_key` |
| Credit notes | The invoice row, then the open credit-note series covering the day; sums read with `sharedLock()` |
| Refunds | The payment row; refunded sums read with `sharedLock()`; unique `(provider, provider_refund_reference)` |
| TDS | The invoice row; unique `invoice_id` on `invoice_tds_claims` |

## Adding a jurisdiction

1. Write `Tax/Jurisdictions/{Country}/…Determiner` (implements `TaxDeterminer`: its outcome keys, its treatments, `TaxUnavailableException` for everything not configured), an optional `TaxIdValidator` and `TaxPresentation`.
2. Register them in `TaxRegistry`.
3. Have the rules reviewed, drafted, submitted and verified by two operators.
4. Billing, invoices, tax lines and payments are unchanged; `JurisdictionCatalogue` already describes the regime.

## Tests

| Kind | Where |
|---|---|
| Feature | `tests/Feature/Billing/` (money, tax engine, pricing, billing profiles, invoices, payments, security, pages) |
| Feature (completion) | `BillingRunTest`, `FinancialControlsTest`, `SettlementTest`, `RazorpayTest`, `PriceNoticeTest` (the 24 critical cases) |
| MySQL races | `tests/MySql/BillingConcurrencyTest.php` (6 races) and `BillingCompletionConcurrencyTest.php` (races 7–11), invariants after each |
| Test-only regimes | `tests/Feature/Billing/TestRegimes.php` (UK VAT and US sales tax determiners, never wired in production) |
| Helpers | `tests/Feature/Billing/BillingTestHelpers.php` (fictional GSTINs on pseudo-PAN `ZZZZZ9999Z`, odd test rates) |

Test gotchas:
- Services resolved before `useTestRegimes()` keep the real registry: resolve them again after it.
- Entitlement decisions carry their evaluation day: compare decisions for a fixed day.
- A model's `tenant_id` column means "tenant-owned" to the platform invariants; a platform record that points at a tenant uses another name (`resolved_tenant_id`).
- Never run `migrate --database=sqlite` locally: the SQLite path falls back to `DB_DATABASE` and creates a stray file.
- Every test runs inside a transaction, so a "must run inside a transaction" guard cannot be shown to fail in a feature test.
- Razorpay is always faked (`fakeRazorpay()`): no test sends a request to Razorpay, and its keys are fictional `rzp_test_` values.
- Back-dated lifecycle history is how billing tests build employees (`staff()`, `staffExit()`): the engine records the effective dates.
