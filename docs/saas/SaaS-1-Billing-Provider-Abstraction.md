# SaaS.1 — Billing, Payment Provider Abstraction and India GST

**Status:** Proposed (SaaS.1, architecture only; no provider, invoice or tax code exists or is written in SaaS.1) · **Date:** 6 October 2026 · **Part of:** [SaaS.1 Commercial Architecture & Gap Analysis](SaaS-1-Commercial-Architecture-Gap-Analysis.md)

**Statements about law in this document must be checked by a tax adviser before implementation.** They cover Indian GST (place of supply, invoice rules, e-invoicing thresholds, SAC codes) and RBI payment rules (e-mandates). They are marked **[verify]**. They describe what the architecture must be able to represent, not legal advice.

## 1. Principles

1. **PeopleOS is the system of record for commercial state.** It owns subscriptions, invoices, credit notes, tax and the billing ledger. Payment providers move money and report what happened. They are adapters, never the source of truth ([ADR-0019](../architecture/decision-register.md#saas1-proposed-decisions)).
2. **Why PeopleOS issues its own invoices.** An Indian tax invoice must be issued by the supplier, Markedge. It needs:
   - the supplier's and recipient's GSTIN;
   - place of supply;
   - a CGST, SGST or IGST split;
   - a SAC code;
   - a consecutive number series per financial year **[verify]**.

   PeopleOS must also compute per-employee charges and true-ups from its own counts. So the billing engine (invoice generation, proration, tax) lives in PeopleOS, and providers are used for collection: one-off payments, mandates and recurring charges against saved instruments. Provider-hosted "billing" products may still be used for collection, never as the invoice of record.
3. **Nothing about a provider leaks into the core.** HCM modules know nothing of billing. The commercial domain knows only the `PaymentGateway` contract and normalised DTOs. Provider SDKs, field names and event names live only in the adapters (architecture contract §15: "Domain code must not import … vendor SDK classes").
4. **No card data ever touches PeopleOS.** Hosted checkout or provider elements only, so PCI DSS scope stays at the self-assessment level for hosted payment pages (SAQ A) **[verify with the provider]**. PeopleOS stores provider tokens and references, never PAN, CVV or bank credentials.
5. **Money is integers.** Amounts are minor units (paise, cents) in `BIGINT`, with an ISO 4217 currency on every monetary row. Rounding is explicit and happens once, at the line, per the tax rule's rounding mode. No floats anywhere in the commercial domain.

## 2. The provider contract

```php
// app/Domain/Commercial/Billing/Contracts/PaymentGateway.php (target; not created in SaaS.1)
interface PaymentGateway
{
    public function key(): string;                                    // 'razorpay', 'stripe', 'manual'
    public function capabilities(): GatewayCapabilities;              // one-off, mandates, upi_autopay, cards, refunds, partial refunds, currencies

    public function ensureCustomer(BillingAccount $account): ProviderCustomerRef;
    public function startCheckout(CheckoutRequest $request): CheckoutSession;          // hosted page / order; carries our idempotency key
    public function fetchPayment(string $providerPaymentId): ProviderPayment;          // server-side confirmation, never trust the redirect
    public function setUpMandate(MandateRequest $request): CheckoutSession;            // e-mandate / card mandate / UPI AutoPay
    public function charge(ChargeRequest $request): ProviderPayment;                   // recurring charge on a mandate, idempotent
    public function refund(RefundRequest $request): ProviderRefund;                    // idempotent
    public function cancelMandate(string $providerMandateId): void;

    public function verifyWebhook(WebhookRequest $raw): VerifiedProviderEvent;         // throws on bad signature / stale timestamp
    public function normalise(VerifiedProviderEvent $event): ?CommercialPaymentEvent;  // null = not our concern → 'ignored'
    public function listSince(\DateTimeInterface $since, ?string $cursor): ProviderPage; // reconciliation
}
```

**Normalised payment events** (the only payment vocabulary the domain sees):
- `payment.succeeded`, `payment.failed`, `payment.requires_action`;
- `refund.succeeded`, `refund.failed`;
- `mandate.activated`, `mandate.cancelled`, `mandate.failed`;
- `dispute.opened`, `dispute.closed`.

Each event carries:
- `provider`, `provider_event_id` and `occurred_at`;
- references (`provider_customer_id`, `provider_payment_id`, `provider_mandate_id`, our `checkout_reference` or `invoice_number` echoed through provider metadata);
- `amount_minor`, `currency`, and a failure code mapped to a small internal set.

**Adapters:**
- `RazorpayGateway`, `StripeGateway`;
- `ManualGateway`, for bank transfer, NEFT/RTGS and cheque recorded by an operator, with evidence and audit;
- `FakeGateway`, deterministic, for tests.

Adapters are selected per billing account (`billing_accounts.gateway`), so an Indian customer and an overseas customer can use different providers without the domain knowing.

**Capability matrix.** These are the facts to confirm per provider before implementation **[verify]**:

| Need | Why it matters | Razorpay | Stripe |
|---|---|---|---|
| INR one-off payments (cards, UPI, net banking) | Indian customers | Expected | Expected (India entity requirements apply) |
| Recurring in India (card e-mandate, UPI AutoPay, eNACH) | RBI e-mandate framework: additional authentication above a per-transaction limit, pre-debit notifications **[verify current limits]** | Expected | Partial / account-dependent |
| Foreign-currency cards | Overseas customers | Account-dependent | Expected |
| Webhook signature | Forgery protection | HMAC-SHA256 of the raw body with the webhook secret (`X-Razorpay-Signature`); event id header | `Stripe-Signature` with timestamp and HMAC-SHA256 (`t=…,v1=…`), tolerance window |
| Idempotent API calls | Retries without double charge | Order and receipt references; check per endpoint | `Idempotency-Key` header |

## 3. Flows

**Checkout (first payment or conversion):**
1. A tenant owner with `billing.manage` chooses a plan version and billing period.
2. `CheckoutService` creates a `pending` subscription and a `draft` invoice (tax computed), plus a `checkout_sessions` row with our `checkout_reference`, which doubles as the idempotency key.
3. The adapter starts hosted checkout, carrying `checkout_reference` in the provider metadata.
4. The customer pays on the provider page and returns to `/billing/return?ref=…`. The return page **only shows "confirming…"**: it calls `fetchPayment` server-side and never trusts query parameters.
5. Confirmation comes from **either** the verified webhook **or** the server-side fetch, whichever arrives first. Both call `PaymentService::recordSucceeded(provider, provider_payment_id)`, which is idempotent on that unique pair.
6. In one transaction:
   - the payment succeeds and is allocated to the invoice;
   - the invoice moves to `issued` and then `paid` (numbered at issue);
   - the subscription moves `pending → active` (or `trialing → active`);
   - entitlements are rebuilt (cache version bump);
   - audit events are written.
7. Receipts and tax invoice PDFs are queued and generated after commit.

**Renewal (automatic collection):**
1. The renewal sweep finds subscriptions with `current_period_end ≤ now`. It locks each one and builds the next invoice:
   - fixed items;
   - per-unit items using the metered quantity for the closing period (Entitlement doc §8);
   - proration.
2. It issues the invoice and calls `charge()` on the active mandate, using an idempotency key of `invoice_id + attempt`.
3. The outcome arrives by webhook or the reconciliation pull:
   - success → `paid`, and the period advances;
   - failure → `past_due` and dunning (State Machines §8).

**Renewal (invoice collection, enterprise):** the invoice is issued with payment terms (net 15/30) and emailed. Bank transfer is recorded by an operator through `ManualGateway`, with the bank reference as evidence, dual control above a threshold, and audit. Overdue invoices enter the same dunning machine, with reminders instead of retries.

**Upgrade and downgrade:**
- **An upgrade** takes effect immediately. A proration invoice is issued (unused value of old items credited, new items charged for the rest of the period). The entitlement snapshot switches when the proration invoice is paid, or immediately for invoice-collection accounts.
- **A downgrade** is scheduled for the period end (`scheduled_change`). At renewal the new items begin. If usage exceeds the new limits, the downgrade is refused at request time, with the reason (for example "you have 140 active employees; the plan allows 100").

**Cancellation:**
- `cancel_at_period_end = true`. At period end the subscription becomes `ended`, the tenant becomes `closing`, and the mandate is cancelled through the adapter.
- No refund of the current period unless an operator issues a credit note: a policy decision, D-5.

**Refund:**
- Only an operator, with a reason, against a credit note. A refund never "edits" an invoice.
- `refund()` uses the credit note id as idempotency key.
- The refund record is finalised by webhook or reconciliation.

## 4. India GST: the commercial tax component

**This is not payroll compliance.**

| | HCM statutory compliance (exists) | SaaS commercial tax (target) |
|---|---|---|
| Domain | `app/Domain/Compliance` (PF, ESI, PT, LWF, TDS on salaries; statutory returns) | `app/Domain/Commercial/Tax` |
| Whose obligation | The **tenant's**, as an employer, about their employees | **Markedge's**, as a supplier of SaaS, about its invoices to tenants |
| Rules | Platform-owned `compliance_rules`, versioned, `verification_status` | Platform-owned `commercial_tax_rules`, versioned and effective-dated, with their own verification status |
| Data | Tenant-owned payroll and statutory rows | Platform-owned invoices, tax lines and credit notes |

The two never share tables, services or rule packs. They share **patterns**: versioned rules, verification before production, append-only outputs. A change to payroll compliance can never change an invoice, and the reverse.

**What the tax component must represent:**

| Concept | Where |
|---|---|
| Supplier identity: Markedge's legal name, GSTIN(s), state code, address, LUT reference for zero-rated exports | `commercial_supplier_profiles` (platform; effective-dated; more than one if Markedge registers in several states) |
| Customer tax identity: registered business name, GSTIN (optional), registration type (regular, composition, unregistered, SEZ unit or developer, overseas), billing address, state code, country | `billing_accounts` + `billing_tax_profiles` (effective-dated; GSTIN changes create a new row, never an edit) |
| GSTIN validation | Format and check digit at entry. Optional verification through a GST verification adapter (same contract pattern as the payment gateway). The state code is derived from the GSTIN and must match the billing state |
| Place of supply | For B2B services to a registered recipient, generally the recipient's location **[verify]**. Stored on each invoice, never recomputed later |
| Tax split | Supplier state = place-of-supply state → CGST + SGST; otherwise IGST. Overseas recipient (export of services) → zero-rated under LUT, IGST 0 **[verify conditions]**. SEZ → zero-rated **[verify]** |
| Rate and SAC | Rate (18 % expected for IT services **[verify]**) and SAC code (candidates 998315 / 997331 / 998314 **[verify; decision D-10]**) live in `commercial_tax_rules` versions, never in code |
| Exemptions | Represented as rule outcomes with a legal basis reference; none assumed |
| Invoice numbering | Consecutive series per financial year, at most 16 characters, unique **[verify]**. Gap-free numbering inside the finalize transaction through `NumberSequences`; series per document type (`INV`, `CN`, `DN`) and per supplier GSTIN |
| Credit notes and debit notes | Separate documents referencing the original invoice, with their own tax split and numbering. Statutory time limits for credit notes **[verify]** enforced at issue |
| E-invoicing (IRN, signed QR) | Required once the supplier's aggregate turnover crosses the notified threshold **[verify current threshold]**. Designed as an optional `EInvoiceProvider` adapter called at finalize. The IRN and QR are stored on the invoice; cancellation within the allowed window only **[verify]** |
| Customer TDS on payments | Indian business customers may deduct income-tax TDS from SaaS invoices **[verify sections and rates]**. Payments allocate `amount_received` plus `tds_claimed`; the invoice is paid when both cover the total; the TDS certificate reference is recorded for reconciliation |
| Returns | Later: a GSTR-1 data export from issued invoices and credit notes (P2). PeopleOS does not file returns |
| Rounding | Per tax line, half-up to the paise; invoice total = sum of rounded lines. The rule is stored on the rule version |

**Calculation is a pure function:**

```
TaxCalculator::calculate(SupplierProfile, CustomerTaxProfile, placeOfSupply, lines[], ruleVersion) → TaxResult
```

It does no database writes and no HTTP calls. It is exhaustively unit-tested (intra-state, inter-state, export, SEZ, unregistered, rounding). The result is frozen on the invoice at finalize. Re-running a later rule version never changes an issued invoice; only a credit or debit note can.

## 5. Webhooks from payment providers

```
POST /webhooks/billing/{provider}          (no session, no API key, CSRF-exempt, rate-limited per IP)
  1. read the RAW body (before any JSON parsing)
  2. adapter->verifyWebhook(): constant-time HMAC check; timestamp tolerance where the scheme has one (±5 min)
     → invalid: 401, logged with provider and request id only (no payload)
  3. insert billing_provider_events (provider, provider_event_id UNIQUE, type, received_at,
     payload_encrypted, payload_sha256, status='received')
     → duplicate provider_event_id: 200 with no further work (replay protection)
  4. respond 200 immediately
  5. queue ProcessBillingProviderEvent (platform job: leased claim, tries, backoff, dead letter)
       → resolve billing account from provider_customer_id / checkout_reference (never from the payload's tenant id)
       → bind the tenant (runAs) for the tenant-owned side effects
       → adapter->normalise() → PaymentService / SubscriptionService (idempotent on provider ids)
       → status succeeded | ignored | failed → retrying → dead_letter; every transition audited with correlation id
```

**Rules:**
- **Replay protection** rests on two things: the unique `(provider, provider_event_id)`, and the timestamp window for providers whose signature covers a timestamp. For providers without a signed timestamp, the event id is the only defence. The raw payload hash detects a different body under the same id; that case is refused and alerted.
- **Payload retention.** The encrypted payload is kept for 90 days (proposed) for disputes and reconciliation, then purged; metadata and the hash stay. This matches `PEOPLEOS_INTEGRATION_PAYLOAD_DAYS` semantics for inbound events.
- **Out-of-order events.** A `payment.failed` that arrives after the same payment's `payment.succeeded` is recorded and ignored. Transitions only move forward (State Machines §3).
- **Isolation from HCM.** No provider event touches an HCM table. The furthest a payment event reaches is: subscription state → tenant access mode and entitlement snapshot, through domain services.

## 6. Reconciliation

| Job | Frequency | What it does |
|---|---|---|
| `ReconcileProviderPayments` | hourly (proposed) | Pages `listSince()` from the last checkpoint. Every provider payment or refund unknown locally becomes a reconciliation exception, or is applied through the same idempotent service when it maps to a known checkout or invoice. This covers missed webhooks |
| `ReconcileSettlements` | daily | Matches provider settlements and fees to payments, for finance reporting (P2) |
| `InvoiceIntegrityCheck` | daily | Issued invoices: numbering gap check per series and year, total = lines + tax, allocations ≤ total; alerts on any breach |

Operators work exceptions in the control plane. Every resolution is audited, with a reason.

## 7. Failure behaviour

| Failure | Behaviour |
|---|---|
| Provider outage at checkout | Checkout cannot start; the customer is told to retry. The subscription stays `pending` and is abandoned after the timeout. No tenant impact |
| Provider outage at renewal | The charge attempt fails with a transient code. It is retried by the dunning schedule, not in a tight loop. The subscription is `past_due` with full access during grace |
| Webhook outage (provider cannot reach us, or we are down) | Providers retry delivery; reconciliation (§6) recovers anything still missing. The customer-facing state stays "confirming payment" until either arrives |
| Timeout on `charge()` | The outcome is unknown. The idempotency key makes a retry safe; a second attempt with the same key returns the first outcome |
| Duplicate webhook | Unique event id → no-op |
| Tax rule missing or unverified for an invoice | Finalize refuses (fail closed). The invoice stays `draft`; an operator alert is raised. No invoice is ever issued with a guessed tax |
| E-invoice registration (IRN) failure | The invoice is issued internally as `issued_pending_irn` **[verify legal acceptability]** or held in `draft` per decision D-10. Retried with backoff; operators alerted |
| PDF or e-mail failure | Retried by the existing `DeliverNotification` job; the invoice stays downloadable in the billing page |
