# SaaS.7 — Commercial Decisions (B-1 to B-16)

**Phase:** SaaS.7 commercial decision resolution · **Starting commit:** `2f07358` · **Date:** 7 October 2026

**Purpose:** turn SaaS.7 from "technically implemented, business decisions open" into an approved commercial specification that a controlled SaaS.7 completion pass can implement. **No code changes; nothing is activated** (no price, tax rule, payment provider or invoice).

**How to read it:** every B-decision states:
- what SaaS.7 supports today;
- the exact decision required and what depends on it;
- the options, with one **recommendation**;
- the decision type (BUSINESS, TAX or LEGAL) and its owner;
- one **status**: APPROVED, PENDING USER, PENDING TAX ADVISER, PENDING LEGAL, DEFERRED or NOT APPLICABLE.

**A recommendation is not a decision.** A status becomes APPROVED only on the business owner's explicit choice, recorded with its date in §13. Statements about law (GST, VAT, sales tax, retention) are context for advisers, not legal or tax advice. They are marked **[for adviser]**.

Sources read: [SaaS-7 report](SaaS-7-Billing-GST-Payments-Report.md), [SaaS-7 baseline](SaaS-7-Baseline.md), ADR-0042 to 0048 and the [decision register](../architecture/decision-register.md), and the SaaS.4, SaaS.5 and SaaS.6 reports. Also the code at `2f07358`: `BillableUnits`, `LifecycleState`, `employee_lifecycle_transitions`, billing, tax and payment services.

## 1. Executive Summary

- **What exists.** SaaS.7 built a global-ready billing, tax and payment architecture:
  - money and currency;
  - markets with independently versioned prices;
  - billing terms that pin a price version to a subscription;
  - billing profiles with generic tax registrations;
  - a jurisdiction-neutral tax engine (India GST implemented, inactive);
  - invoices with gap-free numbering and frozen snapshots;
  - a provider-neutral payment boundary with bank transfers and a non-production sandbox;
  - verified webhooks and exact-match reconciliation.

  It stopped at 16 decisions only Markedge, its tax adviser or its counsel can make.
- **What was decided** (by the business owner on 7 October 2026, recorded in §13):
  - **pricing:** per active employee per month, on the **monthly peak**;
  - **billing:** **monthly in arrears and annual in advance** (committed quantity + monthly true-up), calendar months, day-based proration for partial first and last periods only;
  - **trial only**, no free plan;
  - **all five markets launch**: India, US, UK, EU and UAE, sold **directly by the Indian entity**, **B2B only**, **tax-exclusive**;
  - **payments:** bank transfer now, **Razorpay** next (India and international), **settled in INR**;
  - **terms:** **net 15** with **TDS-aware settlement**, no other partial payments;
  - **corrections:** **credit notes and refunds against them**;
  - **controls:** **maker-checker** for price, credit, refund and write-off;
  - **existing subscribers:** grandfathered until renewal, with **30 days' notice**.
- **What is still open:**
  - the **actual prices** (B-4, owner);
  - Markedge's **entity details and registrations** (B-7, legal);
  - **India GST**, now including the **export treatment** every foreign invoice needs (B-8, tax adviser);
  - the **foreign customer-side treatments** of the four other markets (B-9, tax advisers);
  - **retention periods** (B-16, legal; needed by SaaS.9, not SaaS.7).
- **Result:** SaaS.7 completion stays **blocked** until B-4, B-7, B-8 and B-9 are resolved. The approved decisions already fix what the completion pass must build (§12).

## 2. Already Resolved

These are not reopened.

| Topic | Resolution | Source |
|---|---|---|
| Money | Integer minor units, ISO-4217 code on every monetary row, catalogue-controlled precision, no floats, no FX in calculation | ADR-0042 |
| Contexts | Billing, Tax and Payments are separate; billing never authorises; tenant financial records are tenant-owned and retained | ADR-0043, ADR-0048 |
| Price structure | Plan version × market × interval, independently versioned, immutable once published; subscriptions pin a price version (no silent repricing) | ADR-0044 |
| Tax architecture | Regime → determiner → verified rule (second operator) → generic tax lines; fail closed; India GST is one jurisdiction module; no jurisdiction "supported" by software | ADR-0045 |
| Invoices | Issue fixes the number (gap-free series), tax, totals and snapshots; issued invoices never change | ADR-0046 |
| Payments | Confirmation only by a verified provider event, a server-side fetch or an operator-recorded transfer; exact match or exception; provider-neutral contract | ADR-0047 |
| Plans | Immutable plan versions, no default plan, protected capabilities never switched off | ADR-0031 to 0037 |
| Subscriptions and trials | Effective-dated timeline; explicit trial dates; no automatic conversion, grace or renewal; lapse = no plan in force, never a denial | ADR-0038 to 0041 |
| Enforcement | No commercial enforcement: an unpaid or overdue invoice restricts nothing (SaaS.7 non-goal; access mode is a later phase) | ADR-0048, SaaS.1 D-6/D-7 open |
| Tax-rule verification | Second-operator verification of tax rules stays mandatory, whatever B-13 decides | ADR-0045 |

## 3. Decisions Required

Each decision below follows the same structure. B-4, B-5 and B-7 to B-16 are analysed in their themed sections (§5 to §11).

### B-1 Pricing basis per plan

| | |
|---|---|
| SaaS.7 supports | `PricingBasis`: `flat` and `per_active_employee` (representable); one basis per price; unit amount per market and interval |
| Decision required | Which pricing metric PeopleOS sells on, per plan |
| Depends on it | The billing calculation (amount = unit amount × quantity), the meaning of a "price", invoice line descriptions, and whether billable-quantity measurement (B-2) is needed at all |
| Decision type | **BUSINESS** |

Options, judged on the criteria given:

| Model | HCM usage | Predictability | Scales | Enterprise | Payroll/HR fit | International | Billing complexity |
|---|---|---|---|---|---|---|---|
| Per employee (all records) | Bills dormant records (exited, alumni) | High | Yes | Disputed (paying for leavers) | Poor | Common wording, unfair in practice | Low |
| **Per active employee** | Matches value: payroll, leave, attendance and lifecycle work is per working employee | Medium-high (changes with headcount) | Linear | Standard in HCM (PEPM) | Same population payroll serves | The norm in HCM/payroll SaaS globally | Medium (needs B-2) |
| Employee bands (1–50, 51–200 …) | Coarse | High within a band; cliffs at the edges | Steps | Often negotiated anyway | Neutral | Common for SMB | Low-medium |
| Per user / seat | Penalises employee self-service (every employee is a user) | High | Poor for ESS-heavy HCM | Seat-counting disputes | Poor | Common for admin tools, not HCM | Low |
| Hybrid (base fee + per active employee) | Covers fixed onboarding/support cost | Medium | Yes | Common | Good | Common | Medium |

**Recommendation:** **per active employee per month (PEPM)**, with an optional **minimum billable quantity** per plan version and market (a contract floor that protects small tenants' cost-to-serve; its value is part of B-4).

Reasons:
- PeopleOS's value grows with the people it manages; per-seat pricing would make self-service a cost.
- PEPM is the established metric for HCM and payroll in India and internationally.
- The architecture already measures exactly this unit (`BillableUnits::activeEmployees()`, SaaS.3).

A hybrid base fee can be added later as a second price component without redesign.

| | |
|---|---|
| Decision owner | User / business owner |
| Status | **APPROVED** (7 October 2026): per active employee per month, optional minimum quantity per plan and market (values: B-4) |

### B-2 Billable quantity

| | |
|---|---|
| SaaS.7 supports | `BillableUnits::activeEmployees()` counts employees in an *employed* lifecycle state **now**. `employee_lifecycle_transitions` (effective-dated) allows any past day to be reconstructed. Invoice lines take whole quantities. No metering or daily snapshot exists |
| Decision required | The exact number on a per-employee invoice line, and how each lifecycle situation counts |
| Depends on it | Measurement (a daily count, or reconstruction from history), the evidence frozen on the invoice line, and the dispute process |
| Decision type | **BUSINESS** (definitions); the measurement method is an implementation consequence |

Options for the number on the invoice:

| Option | Meaning | Fairness | Gaming risk | Explainability |
|---|---|---|---|---|
| **Monthly peak employed count** | The highest number of employed employees on any day of the billing month | Customer pays for anyone employed that month (no proration of people) | None | Simple: "the most people you had in the month" |
| Count on the last day of the period | Employed on the period's last day | Leavers before month end are free | High (exit dates moved before the cut-off) | Simplest |
| Count on the first day (prepaid) | Employed on the anchor day; joiners are free that month | Joiners are free until next month | Medium | Simple |
| Average daily employed count | Sum of daily counts ÷ days | Fairest | None | Harder to verify by the customer |
| Committed quantity + true-up | A contracted quantity paid in advance; months whose peak exceeds it are billed in arrears | Predictable | None | Contract-based (enterprise) |

**Recommendation:** **monthly peak employed count**, computed from lifecycle history. The invoice line freezes the quantity together with its evidence: the day of the peak and the employee ids counted, kept as an audit snapshot. A later back-dated HR correction therefore never silently changes a billed quantity; a correction goes through a credit or debit note (B-12). For annual prepaid terms (B-3), **committed quantity + monthly true-up on peak above the commitment**.

**Lifecycle mapping.** PeopleOS's lifecycle states are mapped to billing explicitly, not the other way round: the HR lifecycle never changes because of billing.

| Situation | Counted? | Reason |
|---|---|---|
| Pre-employee, preboarding | No | Not yet employed (`isEmployed()` false); offer stage |
| Onboarding, joined, probation, confirmed, active | Yes | Employed |
| On leave (any leave, incl. long leave) | Yes | Still employed and managed (payroll, leave, compliance) |
| Suspended (employment) | Yes | Still employed and managed |
| Notice period | Yes | Still employed |
| Joining mid-month | Yes in that month (peak) | Present on at least one day |
| Exit mid-month | Yes in that month; no from the next month | Exited or alumni is not employed |
| Alumni | No | Not employed |
| Rehire | Counted once | The same employee record returns (ADR-0002); never two people |
| Status changes within the month (e.g. probation → confirmed) | Counted once | One employee, one unit; a state change is not a new unit |
| Test or duplicate records | Removed by the tenant before the period ends | Operator credit-note path for disputes (B-12) |

| | |
|---|---|
| Decision owner | User / business owner |
| Status | **APPROVED** (7 October 2026): monthly peak employed count with the lifecycle mapping above, frozen with evidence on the invoice line; annual terms on a committed quantity with monthly true-up |

### B-3 Billing intervals, anchoring and proration

| | |
|---|---|
| SaaS.7 supports | `BillingInterval`: `month`, `year` (representable). Billing terms pin a price version from a date. No billing-period table, no calculation, no proration |
| Decision required | The intervals offered; the anchor (calendar or anniversary); prepaid vs postpaid per interval; first-period, upgrade, downgrade and cancellation behaviour; employee-count changes; proration A/B/C; rounding |
| Depends on it | The billing-period model and generator, the scheduler, the proration formula, and the invoice timing (B-11) |
| Decision type | **BUSINESS** |

| Aspect | Recommendation |
|---|---|
| Intervals | **Monthly** and **annual** |
| Anchor | **Calendar months** (1st to last day) for both; an annual term is 12 calendar months from the first full month. This aligns with the payroll month and is easy to reconcile |
| Monthly | Billed **in arrears** at month end on the monthly peak (B-2); no quantity proration needed |
| Annual | Billed **in advance** for the committed quantity × 12 × unit price; monthly peaks above the commitment billed in arrears (true-up) at the same unit price; no refund for unused commitment |
| First period | A partial first month is **prorated by days in service** (monthly: peak × unit × days ÷ days in month; annual: the stub month billed separately, then the 12-month term) |
| Upgrade (another plan version or price) | Effective from the **next period start** (new billing terms pinned from that date); no mid-period proration at launch |
| Downgrade | Effective from the next period start; for annual terms, at renewal only |
| Cancellation | Monthly: the last partial month is billed in arrears, prorated by days. Annual: runs to the end of the term (no refund except by credit note, B-12) |
| Employee-count changes | Covered by the monthly peak; no per-change proration |
| Trials | Not billed (SaaS.6 `trial` periods carry no charge); billing starts on conversion (`active`) |
| Grace (SaaS.6) | Billed as active: the plan stays in force |
| Proration model | **B: day-based proration for partial first and last periods only** |
| Rounding | Each prorated line is rounded once, half up, to the currency's minor unit; tax follows the verified rule's rounding |
| Effective dates | Business dates in UTC (as SaaS.6); tenant-local dates stay an open topic |

Alternatives:
- **A, no proration:** full months always. Simplest, but unfair at start and end.
- **C, full day-based proration:** every change prorated. Fairest, but most complex and dispute-prone.

| | |
|---|---|
| Decision owner | User / business owner |
| Status | **APPROVED** (7 October 2026): the table above, with proration model **B** |

### B-6 Free plan

| | |
|---|---|
| SaaS.7 supports | A zero amount is representable; SaaS.6 trials have explicit dates; no free plan exists |
| Decision required | Whether PeopleOS offers a permanent free plan, a limited free tier, or trials only |
| Depends on it | Plan content and limits (SaaS.4/5), conversion flow (SaaS.6/SaaS.8), support load, and billing (zero-amount invoices or none) |
| Decision type | **BUSINESS** |

Options:
- **No free plan, trial only.**
- **Permanent free plan.** Support cost and abuse risk, and payroll and statutory work has real cost.
- **Limited free tier** (e.g. under N employees, no payroll).

**Recommendation:** **trial only, no free plan** (SaaS.1 D-12). HCM carries statutory and payroll responsibility and support load from day one, and launch is sales-led. A free tier can be added later as a plan with zero-priced versions, without redesign.

| | |
|---|---|
| Decision owner | User / business owner |
| Status | **APPROVED** (7 October 2026): trial only, no free plan |

## 4. Recommended Commercial Model

The recommendation (left) and what the business owner decided (right). Only the right-hand column is binding.

| Element | Recommendation | Decided (7 October 2026) |
|---|---|---|
| Pricing metric | Per active employee per month; optional minimum quantity per plan and market | **APPROVED** as recommended |
| Billable quantity | Monthly peak employed count, frozen with evidence | **APPROVED** as recommended |
| Intervals | Monthly in arrears; annual in advance on a committed quantity with monthly true-up | **APPROVED** as recommended |
| Anchor and proration | Calendar months; proration B (partial first and last periods only) | **APPROVED** as recommended |
| Markets | India first; others architecture-ready | **Different: all five launch** (India, US, UK, EU, UAE). Prices pending (B-4) |
| Seller of record | One entity per market | **The Indian entity sells everywhere** (exports of services abroad); legal details pending (B-7) |
| Currency | The market's currency, one per invoice and payment | **APPROVED**: INR, USD, GBP, EUR, AED per market |
| Tax presentation | Exclusive for B2B | **APPROVED**: exclusive, **B2B only** |
| Free plan / trial | Trial only | **APPROVED** |
| Payment | Bank transfer, then Razorpay for India; international later | **APPROVED, extended**: Razorpay also for international customers |
| Settlement / FX | Defer (INR only) | **APPROVED: INR settlement through Razorpay** for every currency; Markedge bears FX; settlement recorded |
| Payment terms | Net 15; TDS-aware; no other partials | **APPROVED** |
| Refunds and corrections | Credit notes + refunds against them; debit notes deferred | **APPROVED** |
| Dual control | Maker-checker for price, credit, refund, write-off; no thresholds | **APPROVED** |
| Price changes | Grandfather to renewal; 30-day notice; operator re-pin | **APPROVED** |
| Retention | Per counsel | PENDING LEGAL |

**International commercial strategy (as decided).** India is one market, not the architecture:
- India: INR, GST.
- USA: USD, sales-tax-ready.
- UK: GBP, VAT-ready.
- EU: EUR, VAT-ready.
- UAE: AED, VAT-ready.

All five share the same plan catalogue, entitlement engine, subscription model, invoice core and payment abstraction. Each market has its own deliberately chosen prices. The supplier is the Indian entity, so tax determination runs through India GST (domestic, or export once advised); each market's customer-side tax handling follows its adviser. No tax rule is activated before advice.

## 5. India Launch

### B-4 (India) Market and prices

| | |
|---|---|
| SaaS.7 supports | Markets (one currency, selling entity, display locale), prices per plan version × market × interval, versioned |
| Decision required | Whether India launches, which plans and intervals are sold, and the actual INR amounts (and minimum quantities) |
| Depends on it | The real price catalogue and any real invoice |
| Decision type | **BUSINESS** |
| Recommendation | Launch **India (INR)** first: market `IN`, currency INR, display `en_IN`, plans from the SaaS.4 catalogue, monthly and annual, tax-exclusive B2B prices, payment by bank transfer then Razorpay. **Amounts are not recommended here**: they are a pricing decision |
| Decision owner | User / business owner |
| Status | PENDING USER: **India is approved as a launch market** (7 October 2026); the INR amounts and minimum quantities are not decided yet |

### B-7 Markedge selling entity and registrations

| | |
|---|---|
| SaaS.7 supports | Versioned supplier profiles per entity code (legal name, address, country, subdivision, tax registration), one entity per market, multiple entities supported. None is configured. Nothing names any company in code |
| Decision required | The legal entity that issues PeopleOS invoices (PeopleOS is a **Markedge Technologies** product; the exact legal name and form, e.g. Pvt Ltd or LLP, must come from the entity's records), its registered address, country and state, GSTIN(s), any foreign registrations, and which entity sells in which market |
| Depends on it | Invoice issuer and snapshot, the GST determination (the supplier's state decides intra- vs inter-state), number series, and provider onboarding (merchant KYC needs the same entity) |
| Decision type | **LEGAL** |
| Recommendation | One Indian entity sells in India. The architecture already allows a different entity per foreign market later |
| Business intent (approved 7 October 2026) | **The Indian Markedge Technologies entity is the seller of record in every launch market** (India, US, UK, EU, UAE), invoicing foreign B2B customers directly as exports of services. Legal details below still come from legal; the export and foreign treatments from tax advisers (B-8, B-9) |
| Information required | Exact legal name; legal form and registration number (CIN/LLPIN); registered address; state; GSTIN(s) and principal place of business; PAN (for TDS certificates); LUT status if exporting; bank account for transfers; foreign registrations (VAT, sales tax) if any |
| Decision owner | Markedge legal / finance |
| Status | PENDING LEGAL |

### B-8 India GST specifics

| | |
|---|---|
| SaaS.7 supports | IN_GST determiner (intra-state, intra-union territory, inter-state); GSTIN format and check validation; state codes; rules with outcomes, rates, rounding and SAC verified by a second operator; refusal of exports, SEZ and UIN; invoice number ≤ 16 characters enforced as **pending tax review**. No rule exists |
| Decision required | The adviser-confirmed values and treatments below |
| Depends on it | Verifying the India rule (nothing can be issued until then), invoice content, numbering format, credit notes, e-invoicing |
| Decision type | **TAX** (adviser) |
| Decision owner | Markedge's GST adviser |
| Status | PENDING TAX ADVISER |

Checklist (each item's status). Items marked **[for adviser]** are context, not advice.

| Item | Status | Note for the adviser |
|---|---|---|
| GST rate for PeopleOS subscriptions | PENDING TAX ADVISER | Software/SaaS is commonly charged at the standard services rate **[for adviser]**; no rate is configured |
| SAC code | PENDING TAX ADVISER | Candidates often discussed for SaaS: 998314 / 998315 / 997331 **[for adviser]** |
| Place of supply | PENDING TAX ADVISER | Implemented as the recipient's state on record (GSTIN state if registered, billing address otherwise), pending confirmation |
| B2B / B2C | PENDING TAX ADVISER | Both representable; B2C at launch is a business choice (§7) |
| Registered / unregistered customer | PENDING TAX ADVISER | Both representable; GSTIN required when registered |
| Intra-state (CGST + SGST) | PENDING TAX ADVISER | Implemented as an outcome; rates from the rule |
| Inter-state (IGST) | PENDING TAX ADVISER | Implemented as an outcome |
| Union territory without legislature (CGST + UTGST) | PENDING TAX ADVISER | Implemented as an outcome; UT list to confirm |
| Export of services (zero-rated, LUT) | PENDING TAX ADVISER | **Now required** (international launch from the Indian entity). Refused today; needs LUT status, export conditions (e.g. receipt in convertible foreign exchange) and invoice wording **[for adviser]** |
| INR value of foreign-currency invoices | PENDING TAX ADVISER | GST reporting of an export invoice in USD/GBP/EUR/AED needs an INR value at a prescribed rate and date **[for adviser]**; PeopleOS would snapshot rate, source and date on the invoice (see §12) |
| Foreign-side wording on export invoices | PENDING TAX ADVISER | E.g. reverse-charge statements for EU, UK and UAE business customers **[for adviser]** |
| SEZ supplies | DEFERRED | Refused today (zero-rating conditions) |
| Tax-exclusive / inclusive | PENDING TAX ADVISER | Recommendation: exclusive for B2B; the B2C display rules need confirmation |
| Rounding | PENDING TAX ADVISER | Rule supports half-up or half-even per line |
| Invoice numbering | PENDING TAX ADVISER | Consecutive per financial year, at most 16 characters **[for adviser]**; series prefix and financial-year windows to approve |
| E-invoicing (IRN, QR) | PENDING TAX ADVISER | Applicability depends on aggregate turnover thresholds **[for adviser]**; not built |
| Credit and debit notes | PENDING TAX ADVISER | Required for any post-issue correction (B-12); format, time limits and linkage to confirm |
| Required invoice fields | PENDING TAX ADVISER | Presentation already shows supplier and customer GSTIN, place of supply with state code, SAC, tax split and totals; the adviser confirms the full list (e.g. "Tax Invoice" title, signature, reverse-charge statement) |

## 6. International Launch

### B-4 (international) Markets and prices

| | |
|---|---|
| SaaS.7 supports | Any market with any catalogue currency, priced independently; no market exists |
| Decision required | Which of US, UK, EU and UAE launch now, which are architecture-ready, which come later; their prices deliberately chosen per market |
| Depends on it | Market rows, prices, tax activation (B-9), payment availability (B-10), FX (B-14) |
| Decision type | **BUSINESS** |
| Recommendation | India only at launch; US, UK, EU and UAE architecture-ready until direct vs merchant-of-record selling, a tax adviser per jurisdiction and deliberate market prices are decided |
| Business decision (7 October 2026) | **All five launch: India (INR), United States (USD), United Kingdom (GBP), European Union (EUR), UAE (AED)**, sold directly by the Indian entity (B-7), B2B only, tax-exclusive (B-5), collected through Razorpay with INR settlement (B-10, B-14). Each market's prices are set deliberately (never INR × FX) |
| Decision owner | User / business owner |
| Status | PENDING USER: markets approved; amounts (per plan, interval, market, minimum quantity) not decided (shared with §5) |

### B-9 International jurisdictions

| | |
|---|---|
| SaaS.7 supports | Regimes for EU VAT, UK VAT, UAE VAT, US sales tax, Canada GST/HST/PST/QST, Australia GST and Singapore GST are described in the jurisdiction catalogue with computed status `not_supported` (no determiner). Generic tax lines, treatments (incl. reverse charge) and registrations are ready |
| Decision required | Which jurisdictions are activated, with a named tax owner each |
| Depends on it | Determiner, rules and presentation per activated jurisdiction; registrations; supplier entity |
| Decision type | **BUSINESS** (which) + **TAX** (how) |
| Decision owner | User / business owner (which: **decided** 7 October 2026), then a tax adviser per activated jurisdiction (how) |
| Status | PENDING TAX ADVISER: the activation list is approved; no jurisdiction can be configured before its adviser confirms the treatment |

| Category | Jurisdictions | Tax ownership |
|---|---|---|
| 1. Activated (business decision, 7 October 2026) | **India** (supplier side: GST incl. export treatment) · **USA**, **UK**, **EU**, **UAE** (customer side) | TAX ADVISER REQUIRED for each. India: GST adviser (B-8). USA: whether a foreign B2B seller of SaaS owes sales tax in any state (economic nexus, SaaS taxability per state). UK: VAT treatment of B2B services from a non-UK supplier (customer reverse charge). EU: B2B reverse charge per member state (no OSS needed while B2B only). UAE: VAT reverse charge for B2B imports of services. **[for adviser]** |
| 2. Architecture-ready / not activated | none | |
| 3. Not supported | **Canada**, **Australia**, **Singapore** (described in the catalogue, not targeted) | TAX ADVISER REQUIRED if ever activated |

## 7. Tax Model

### B-5 Tax-inclusive vs tax-exclusive

| | |
|---|---|
| SaaS.7 supports | Prices are stored as amounts; tax is added on the invoice by the engine (exclusive). The engine has no inclusive back-calculation |
| Decision required | Per market and customer type, whether displayed prices include tax, and how the invoice shows price, tax line and total |
| Depends on it | Price display (future pricing page), invoice calculation (exclusive: tax added; inclusive: tax extracted, with rounding), the tax line and the total |
| Decision type | **BUSINESS** with **TAX** confirmation for B2C markets |
| Recommendation | **Tax-exclusive for B2B in every launch market**. Displayed price = net price "+ applicable taxes"; the invoice shows the net line amount, a tax line per component and the gross total. Inclusive display only for B2C in markets that require it (EU/UK consumer price rules **[for adviser]**), and only if B2C is sold there, which is not recommended at launch |
| Decision owner | User / business owner (tax adviser for B2C) |
| Status | **APPROVED** (7 October 2026): tax-exclusive prices, **B2B only** in every launch market; no B2C sales at launch |

**Tax strategy (as decided).** The Indian entity is the supplier everywhere, so every invoice is determined by the India GST regime: domestic supplies as CGST/SGST/UTGST/IGST, and foreign B2B customers as exports of services once the export treatment is adviser-confirmed and configured (today refused). Foreign customer-side obligations (reverse-charge wording, or any US state registration) follow each adviser. If an adviser requires Markedge to register abroad, the engine needs destination regimes for a registered supplier (§12). Nothing is activated before advice.

## 8. Payment Model

### B-10 Payment provider

| | |
|---|---|
| SaaS.7 supports | `PaymentProvider` contract; operator-recorded bank transfers; a non-production sandbox; verified, idempotent webhooks; reconciliation. No real provider |
| Decision required | Which provider(s) per market, and whether international sales use a merchant of record |
| Depends on it | One adapter per provider (verify, interpret, start, fetch), onboarding (KYC, webhook secrets), payment methods offered, and settlement and FX (B-14) |
| Decision type | **BUSINESS** |

Comparison (facts as reported in October 2026; confirm fees and terms at onboarding):

| Need | Bank transfer (manual) | Razorpay | Stripe | Merchant of record (e.g. Paddle) |
|---|---|---|---|---|
| India (INR, UPI, net banking, cards) | Yes (NEFT/RTGS) | Yes | Invite-only for new Indian accounts since 2024; video KYC from 2026 ([The Paypers](https://thepaypers.com/payments/news/stripe-moves-to-invite-only-in-india), [Stripe](https://support.stripe.com/questions/video-kyc-for-india-onboarding)) | Yes, as seller of record |
| International cards / currencies | SWIFT only | International payments in many currencies, settled in INR ([Razorpay](https://razorpay.com/solutions/saas/)) | Strong globally ([Stripe exports](https://docs.stripe.com/india-exports)) | Yes, 200+ markets ([Paddle](https://www.paddle.com/billing/india)) |
| Recurring / subscriptions | Manual | Subscriptions with cards, UPI AutoPay, eNACH (RBI e-mandate rules) | Yes | Yes |
| Webhooks | Not applicable | HMAC-signed | Signed with timestamp | Signed |
| Refunds | Manual | API | API | Via the MoR |
| Tax / invoice | PeopleOS invoices | PeopleOS invoices; Markedge remains the seller everywhere | PeopleOS invoices | **The MoR is the seller**: it issues the customer invoice and handles VAT/GST/sales tax; Markedge invoices the MoR. This changes ADR-0046's invoice-of-record for those markets (a new ADR) |
| Settlement | INR bank account | INR | Account-dependent | Payout from the MoR |
| Laravel integration | n/a | SDK and REST | Cashier / SDK | SDK / REST |
| Fees | Bank charges | Domestic and international rates differ **[verify]** | **[verify]** | About 5 % + $0.50 per transaction as published ([review](https://fungies.io/paddle-review-2026)) **[verify]** |
| Merchant onboarding | None | Indian entity KYC | Invite / KYC | Seller KYC |

**Recommendation:**
- **Launch (India):** bank transfer (already built) for sales-led customers; **Razorpay** as the India provider once Markedge's merchant account is approved (subscriptions, UPI AutoPay and eNACH matter for Indian recurring payments).
- **International:** decide at international launch between (a) direct sale through Razorpay international or Stripe, if invited, with Markedge registering for foreign taxes, and (b) a **merchant of record** for non-India markets. Option (b) avoids per-country tax registration at a higher fee, but needs a new ADR on invoice-of-record.

The provider abstraction is kept in every case.

| | |
|---|---|
| Decision owner | User / business owner |
| Status | **APPROVED** (7 October 2026): **bank transfer now** (built); **Razorpay next**, after Markedge's merchant onboarding, for India and for international customers (Razorpay international payments in the invoice currency, **settled in INR**). No merchant of record. Nothing is installed or activated in this phase |

### B-11 Payment terms, partial payments, customer TDS

| | |
|---|---|
| SaaS.7 supports | Optional due date per invoice; exact-match settlement; partial, short and over payments become reconciliation exceptions; bank transfers recorded by operators |
| Decision required | Prepaid vs postpaid (follows B-3); due-date terms; whether partial payments are allowed; how customer TDS deductions settle invoices; overpayment handling |
| Depends on it | Due-date calculation, payment allocation (one or more payments per invoice), a TDS-claimed amount with certificate reference, invoice "partially paid" state, and the exception volume |
| Decision type | **BUSINESS** (TDS sections and rates: **TAX**) |
| Recommendation | See below |
| Decision owner | User / business owner (tax adviser for TDS specifics) |
| Status | **APPROVED** (7 October 2026): monthly postpaid, annual prepaid, **net 15**, **TDS-aware settlement**, no other partial payments, overpayments as exceptions |

Recommendations for B-11:
- **Timing:** prepaid for annual (invoiced at term start), postpaid for monthly (invoiced at month end), as B-3.
- **Due date:** net 15 days from issue for both (a value to approve).
- **Partial payments:** not supported, **except customer TDS**. Indian business customers commonly deduct income-tax TDS from payments for services **[for adviser]**, so exact-match-only would make most Indian B2B payments exceptions. An invoice is settled when the payments received plus the TDS claimed (recorded with the certificate or 26AS reference) equal the total. Any other short payment stays an exception.
- **Overpayment:** an exception; refunded or credited by operator (B-12). No customer balance or wallet at launch.

### B-14 FX settlement

| | |
|---|---|
| SaaS.7 supports | Billing currency = payment currency (option A); optional settlement amount and currency recorded on payments, never used; no FX anywhere |
| Decision required | Whether settlement in a different currency is accepted, who bears FX, and whether customers see conversions |
| Depends on it | FX snapshots on payments and invoices, provider settlement reports, and accounting |
| Decision type | **BUSINESS** |
| Recommendation | **DEFERRED** with India-only launch: INR billed, paid and settled. At international launch the customer pays in the invoice currency; the provider settles INR to Markedge (provider FX; Markedge bears it); the settlement amount is recorded (fields exist). Customers never see a conversion. Multi-currency settlement accounts stay out of scope |
| Decision owner | User / business owner |
| Status | **APPROVED** (7 October 2026): invoices and payments in the market currency; **Razorpay settles INR** to Markedge; Markedge bears provider FX; the settlement amount and currency are recorded per payment (fields exist); customers never see a conversion. (A first answer chose multi-currency settlement accounts; it was clarified to INR settlement because Razorpay settles in INR.) The INR **reporting** value of foreign-currency invoices for GST is a separate requirement (B-8, §12) |

## 9. Financial Controls

### B-12 Refunds, credit and debit notes

| | |
|---|---|
| SaaS.7 supports | Issued invoices are immutable; drafts can be discarded; exceptions are resolved off-system with a note. No credit or debit notes, refunds or cancellation |
| Decision required | Which corrections exist: refunds (full or partial), credit notes, debit notes, invoice cancellation, payment reversal |
| Depends on it | A credit/debit note document type (own number series, reference to the original, tax split), refund records tied to payments (provider refund API or manual), invoice "credited" state, numbering, and dual control (B-13) |
| Decision type | **BUSINESS** (form and timing in India: **TAX**) |
| Recommendation | **Credit notes (full and partial)** as the only way to reduce an issued invoice; a full credit note is the cancellation. **Refunds** only against a credit note: full or partial, by provider API or bank transfer, operator-recorded. **Debit notes:** deferred (underbilling is invoiced as a new invoice). **Payment reversal** (chargebacks): recorded from provider events as an exception at launch |
| Decision owner | User / business owner (tax adviser for India credit-note rules) |
| Status | **APPROVED** (7 October 2026): credit notes (full and partial; a full one cancels the invoice); refunds only against a credit note; debit notes deferred; chargebacks recorded as exceptions |

### B-13 Dual control for financial operations

| | |
|---|---|
| SaaS.7 supports | One operator + reason for every financial operation; **second-operator verification for tax rules** (kept) |
| Decision required | Which operations need maker-checker; single operator vs role-based vs amount thresholds |
| Depends on it | An approval-request model (requested → approved or rejected by another operator), pages, and audit |
| Decision type | **BUSINESS** |
| Recommendation | **Maker-checker** (a second operator approves) for: price publication; credit notes; refunds; invoice cancellation (full credit); writing off or accepting an exception; tax-rule activation (as today). **Single operator + reason** for: drafting; issuing invoices; recording bank transfers; billing profiles and terms. **No amount thresholds at launch** (no role catalogue yet: D-15, SaaS.10); role-based approval later |
| Decision owner | User / business owner |
| Status | **APPROVED** (7 October 2026): as recommended (maker-checker, no thresholds; tax-rule verification unchanged) |

## 10. Existing Subscriber Policy

### B-15 New prices for existing subscribers

| | |
|---|---|
| SaaS.7 supports | Billing terms pin a price version; nothing re-prices automatically; an operator re-pin is the only way (audited) |
| Decision required | When and how a new price reaches an existing subscriber |
| Depends on it | The renewal process, notice records, and whether customer acceptance is captured |
| Decision type | **BUSINESS** |
| Options | Grandfather until renewal; immediate change; notice + next renewal; operator migration; customer acceptance |
| Recommendation | **Grandfather until the next renewal** (annual) or the next period after notice (monthly); **written notice of at least 30 days** before a price increase takes effect; applied only by an operator re-pin (existing mechanism); decreases may apply from the next period without notice. No silent repricing (already enforced). Recording customer acceptance is a later option |
| Decision owner | User / business owner |
| Status | **APPROVED** (7 October 2026): as recommended |

## 11. Financial Retention

### B-16 Financial-record retention

| | |
|---|---|
| SaaS.7 supports | Invoices, lines, tax lines and payments are never deleted; the tenant key is restricted on delete (a tenant with invoices cannot be deleted); audit chains are append-only; provider payloads are encrypted, with no purge yet |
| Decision required | Retention periods for invoices, payment records, credit/debit notes, tax records, audit records and provider payloads, after a customer leaves |
| Depends on it | SaaS.9 deletion and retention workflow (D-8): which records survive tenant deletion and for how long |
| Decision type | **LEGAL** (and accounting) |
| Context for counsel | India's GST law and company law both set record-keeping periods (commonly cited: CGST Act s.36; Companies Act s.128) **[for adviser, not advice]**; foreign jurisdictions add their own once activated |
| Recommendation | Keep every financial record (and its audit trail) at least for the period counsel confirms, measured from the end of the relevant financial year; never with the tenant's HCM data deletion; provider payloads purged after a shorter operational window (e.g. 90 days) once counsel confirms they are not records |
| Decision owner | Markedge legal / accounting |
| Status | PENDING LEGAL |

## 12. Implementation Consequences

What the SaaS.7 completion pass must build for the approved decisions. Nothing is built in this phase. Items marked *(after advice)* wait for B-7, B-8 or B-9.

| Decision | Code consequence |
|---|---|
| B-1 PEPM + minimum | Price versions gain an optional `minimum_quantity` (additive column); amount = max(quantity, minimum) × unit |
| B-2 Monthly peak | A daily employed-count snapshot per tenant (scheduled, idempotent, tenant-aware) or reconstruction from `employee_lifecycle_transitions`; invoice lines store the quantity evidence (peak day, count, snapshot reference); back-dated HR changes never alter issued lines |
| B-3 Intervals and proration B | A `billing_periods` table (tenant, subscription, terms, price version, interval, start, end, currency, quantity evidence, amounts, status, invoice). A generator (idempotent, tenant-aware, scheduled, in the runbook) drafts invoices through the existing `Invoices::draft()`. Day-based proration for stub periods; annual committed quantity on billing terms (additive) and monthly true-up lines; trials not billed, grace billed |
| B-4 Markets and prices | Operator data only (five markets, prices, minimums) through the existing pages, published by maker-checker (B-13), once the amounts are decided |
| B-5 Exclusive, B2B | Billing profiles for launch markets accept only `business` customers (a market or launch rule; additive) |
| B-6 Trial only | No change |
| B-7 Indian entity everywhere | Operator data only (supplier profile, series) *(after advice)*; all five markets point to the Indian entity |
| B-8 India GST + export | An adviser-verified India rule (operator data, two operators) *(after advice)*. The India determiner gains an **export of services** outcome (zero-rated with LUT reference, or as advised) instead of refusing foreign customers. **FX reporting snapshot:** a foreign-currency invoice stores its INR value with the rate, source and date prescribed by the adviser. This changes I-9/ADR-0042 ("no FX") from "no FX at all" to "no FX in amount calculation; a reporting conversion snapshot only" (a new ADR in the completion pass). Export-invoice wording. PDF or e-invoicing only if advised |
| B-9 Five markets | Customer-side wording per market (e.g. reverse charge) as presentation rows *(after advice)*. If an adviser requires Markedge to register abroad (e.g. a US state), the engine must apply a destination regime for a registered supplier: regime selection by supplier registrations and place of supply, not supplier country alone (a new ADR, only if advised) |
| B-10 Razorpay | A `RazorpayProvider` adapter (verify, interpret, start, fetch; international currencies) behind the existing contract; secrets from the environment; sandbox first; activated only after merchant onboarding |
| B-11 Net 15, TDS-aware | Due date = issue date + 15 days; TDS claimed with certificate reference on payment allocation; invoice settles when payments + TDS = total; "partially paid" state only for TDS pending; other short or over payments stay exceptions |
| B-12 Credit notes, refunds | Credit-note document type (own series, reference to the invoice, mirrored tax split; a full one cancels); an invoice "credited" state; refunds tied to a credit note (Razorpay refund API or manual); chargebacks as exceptions |
| B-13 Maker-checker | An approval-request model (requested → approved or rejected by another operator) for price publication, credit notes, refunds, cancellations and exception write-offs; pages; audit; self-approval refused |
| B-14 INR settlement | Record the provider's INR settlement amount per payment (fields exist); settlement report reconciliation |
| B-15 Grandfathering | Notice records (date, customer, effective renewal) and a renewal re-pin worklist; no automatic change |
| B-16 Retention | SaaS.9 (retention classes; payload purge) *(after legal advice)* |

**Does SaaS.7 completion require code changes? Yes.** B-1, B-2, B-3, B-5, B-8 (export outcome, FX reporting snapshot), B-10, B-11, B-12, B-13 and B-15 need code. B-4 and B-7 are operator data.

**Test-plan additions for the completion pass:**
- peak quantity across every lifecycle situation in B-2;
- proration and rounding at month boundaries;
- annual commitment and true-up;
- generator idempotency and concurrency (MySQL);
- TDS-aware settlement;
- credit-note numbering and immutability;
- refund idempotency;
- maker-checker self-approval refusal;
- export invoices (zero tax, LUT reference, INR snapshot unchanged when rates change later);
- invoices in USD, GBP, EUR and AED from the Indian entity;
- Razorpay webhook verification against its sandbox;
- INR settlement recording.

## 13. Final Approval Matrix

| Decision | Recommendation | Owner | Status | Blocks SaaS.7? |
|---|---|---|---|---|
| B-1 Pricing basis | Per active employee per month + optional minimum | Business owner | APPROVED | No |
| B-2 Billable quantity | Monthly peak employed count, frozen with evidence; annual: commitment + true-up | Business owner | APPROVED | No |
| B-3 Intervals, anchor, proration | Monthly (arrears) + annual (advance), calendar months, proration B | Business owner | APPROVED | No |
| B-4 Markets and amounts | Markets decided: India, US, UK, EU, UAE; prices per market by the owner | Business owner | PENDING USER | **Yes** (no price, no invoice) |
| B-5 Tax-inclusive/exclusive | Exclusive, B2B only | Business owner | APPROVED | No |
| B-6 Free plan | Trial only | Business owner | APPROVED | No |
| B-7 Selling entity | The Indian Markedge Technologies entity sells everywhere (intent decided); legal name, CIN/LLPIN, address, GSTIN, PAN, LUT, bank details from legal | Legal / finance | PENDING LEGAL | **Yes** |
| B-8 India GST | Adviser checklist (§5), incl. export of services and INR reporting value | GST adviser | PENDING TAX ADVISER | **Yes** |
| B-9 Jurisdictions | Activated: India, US, UK, EU, UAE (decided); customer-side treatments per adviser; CA, AU, SG not supported | Business owner + tax advisers | PENDING TAX ADVISER | **Yes** (foreign invoices) |
| B-10 Payment provider | Bank transfer now; Razorpay next (India + international), after onboarding | Business owner | APPROVED | No (bank transfer works; Razorpay adapter is completion work) |
| B-11 Terms, partial, TDS | Net 15; TDS-aware settlement; no other partials | Business owner | APPROVED | No |
| B-12 Refunds, notes | Credit notes + refunds against them; debit notes deferred | Business owner | APPROVED | No |
| B-13 Dual control | Maker-checker for price, credit, refund, cancellation, write-off; no thresholds | Business owner | APPROVED | No |
| B-14 FX settlement | INR settlement through Razorpay; Markedge bears FX | Business owner | APPROVED | No |
| B-15 Existing subscribers | Grandfather to renewal, 30-day notice, operator re-pin | Business owner | APPROVED | No |
| B-16 Retention | Per counsel; financial records survive tenant deletion | Legal / accounting | PENDING LEGAL | No (SaaS.9) |

**Totals:** APPROVED 11 · PENDING USER 1 · PENDING TAX ADVISER 2 · PENDING LEGAL 2 · DEFERRED 0 · NOT APPLICABLE 0.

**Approval log.** All choices were made by the business owner in the SaaS.7 decision session on 7 October 2026, from the options in this document:

| Decision | Choice |
|---|---|
| B-1 | "Per active employee/month" |
| B-2 | "Monthly peak employed" |
| B-3 | "Monthly + annual, proration B" |
| B-6 | "Trial only, no free plan" |
| B-4 / B-9 | Markets: "India + US, UK, EU, UAE" |
| B-5 | "Exclusive, B2B only" |
| B-10 | "Bank transfer now, Razorpay next" |
| B-14 | First "Multi-currency settlement accounts", then, given that Razorpay settles in INR, "INR settlement via Razorpay" |
| Seller abroad | "Indian entity exports directly" |
| B-11 | "Net 15, TDS-aware, no partials" |
| B-12 | "Credit notes + refunds" |
| B-13 | "Maker-checker, no thresholds" |
| B-15 | "Grandfather to renewal, 30-day notice" |
| B-4 amounts | "Not yet: keep PENDING" |

**SaaS.7 decision-resolution status: BLOCKED**, on B-4 (prices), B-7 (entity, legal), B-8 (India GST incl. export, tax adviser) and B-9 (foreign treatments, tax advisers).

## 14. Completion pass (8 October 2026)

The SaaS.7 completion pass implemented every approved decision. It changed no decision's status. Report: [SaaS-7 completion report](SaaS-7-Completion-Report.md); ADR-0049 to 0057 in the [decision register](../architecture/decision-register.md).

**Clarifications given by the business owner in that pass** (they refine approved decisions; none is a new decision):

| Question | Choice |
|---|---|
| How is a partial month before an annual term billed? (B-3) | "Monthly terms, in arrears": peak × unit × days ÷ days in the month; the annual term starts on the 1st of the next month, with a commitment of at least the price's minimum |
| When can annual terms change? (B-3, B-15) | "Only at renewal": price, plan, interval and commitment alike; the true-up is max(0, monthly peak − commitment) × the same unit, in arrears |
| What do "cancellation" and "write-off" cover? (B-13) | "Invoice cancellation + both write-offs": an issued invoice cancelled by a full credit note; an unpaid invoice written off; a payment exception accepted or written off. Subscription cancellation stays the SaaS.6 operator action |
| How far does the billing run go? (B-3) | "Draft invoices only": an operator issues them, gated by a verified tax rule |

**What each approved decision became:**

| Decision | Implemented as |
|---|---|
| B-1 | PEPM unit amount on price versions, with an optional `minimum_quantity`; flat prices stay representable but are never billed by the run |
| B-2 | `BillableQuantity`: monthly peak employed count rebuilt from `employee_lifecycle_transitions`, frozen with its evidence on the billing period and the invoice line |
| B-3 | `billing_periods` and `peopleos:billing:run` (drafts only; scheduled only when enabled): monthly in arrears, annual in advance on the commitment, monthly true-up; calendar anchoring; proration B; trials not billed, grace billed |
| B-5 | Billing profiles refuse B2C customers (`peopleos.billing.b2b_only`, on by default); prices stay tax-exclusive |
| B-6 | No change (trials are not billed) |
| B-10 | Bank transfers (existing) and a `RazorpayProvider` adapter in **test mode only** (`rzp_test_` keys, never production) |
| B-11 | Net 15 default due date; operator-declared customer TDS (amount due = total − credit notes − TDS; partially paid only while the certificate is pending); every other short or over payment stays an exception |
| B-12 | Credit notes (own series, original tax; a full one cancels the invoice) and refunds only against them; debit notes deferred |
| B-13 | `financial_approvals` and the Approvals page: price publication, credit notes, refunds, invoice write-offs and exception resolutions need a second operator; self-approval refused in the service and the model |
| B-14 | The provider's INR settlement (and the implied rate, for reporting) recorded once on the payment; invoices and payments stay in the market currency |
| B-15 | `price_change_notices` (≥ 30 days, at a period start or renewal) and the re-pin worklist; a re-pin to a higher version of the same price is refused without one |

**Still open, unchanged:** B-4 (prices, owner), B-7 (entity details, legal), B-8 (India GST incl. export of services and the INR reporting value, tax adviser), B-9 (foreign customer-side treatments, tax advisers), B-16 (retention, legal; SaaS.9). Until B-4, B-7, B-8 and B-9 are resolved no real price exists, no foreign invoice can be issued and no invoice should be issued to a real customer. **SaaS.7 status: BLOCKED.**

## 15. Configuration pass (8 October 2026)

The SaaS.7 configuration pass made every commercial value data: prices, customer deals, Markedge policy and statutory values are versioned, effective-dated and changed by two operators without a deployment. It changed no decision's status. Report: [SaaS-7 configuration report](SaaS-7-Configuration-Report.md); ADR-0058 to 0064 in the [decision register](../architecture/decision-register.md).

**Three kinds of rule, kept apart:**

| Kind | Where it lives | Who changes it |
|---|---|---|
| Statute (tax rates, treatments, conditions, invoice-number length) | `tax_rules`, statutory `configuration_versions`; shipped as the statutory dataset with sources | One operator loads or drafts, another verifies |
| Markedge policy (decisions B-3, B-5, B-11, B-15) | `configuration_versions` (company policy), shipped defaults in `config/peopleos.php` | One operator proposes from a date, another approves |
| Customer contract | `negotiated_prices` and their versions, pinned as billing terms | One operator records and drafts, another publishes |

**What each decision became in this pass:**

| Decision | Configuration |
|---|---|
| B-1 | Standard prices and customer deals both support PEPM and fixed monthly (flat) amounts; flat is now billed (one unit a month; × 12 in advance for annual terms) |
| B-3 | Proration rounding is the policy `billing.proration_rounding` (half up) |
| B-4 | **Still pending.** The structure is complete (markets, prices per plan version × market × interval, versions, deals); no amount is shipped; a combination without a price shows NO PRICE CONFIGURED and is never billed |
| B-5 | `billing.b2b_only` (yes) and `billing.prices_include_tax` (no) are policies; an inclusive price is refused at issue rather than mis-taxed |
| B-8 | **Still pending tax adviser.** The current India GST rule (18 %: CGST 9 + SGST/UTGST 9, IGST 18; export of services zero-rated only under its statutory conditions) ships with its notifications; the SAC classification (a judgement among candidate codes), the LUT itself and the INR rate source policy remain the adviser's: invoices are refused (TAX_CLASSIFICATION_PENDING) until a rule version with the SAC is verified |
| B-9 | **Still pending tax advisers.** The current destination rules ship (UK 20 %, 27 EU member-state rates (Greece pending), UAE 5 %, five researched US states): whether Markedge registers abroad (OSS, UK VAT, UAE TRN, US state permits) is the advisers'; until a registration is recorded on the supplier profile, a consumer supply into those places is refused (REGISTRATION_REQUIRED) |
| B-11 | `billing.payment_terms_days` (15) and `settlement.tds_jurisdictions` (India, INR) are policies |
| B-15 | `billing.price_increase_notice_days` (30) is a policy; customer deals change by contract, not by notice |

**Still open, unchanged:** B-4, B-7, B-8, B-9, B-16. Loading the statutory dataset is not a claim of legal compliance; it is the current researched configuration, kept current by new versions. **SaaS.7 status: BLOCKED.**
