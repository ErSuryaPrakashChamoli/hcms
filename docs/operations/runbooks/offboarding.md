# Runbook: tenant offboarding (close and reopen)

Audience: Markedge platform operators. Page: **Platform → Offboarding** (`/admin/platform-offboarding`).
Architecture: `docs/architecture/saas-9-offboarding.md`.

## Before you start
- Have the customer's written notice and its reference (contract or ticket number).
- Billing: **Subscriptions** must show no subscription that continues. End it through the subscription lifecycle
  (cancel or let it expire at the term's end). Offboarding never cancels a subscription or an invoice for you.
- Unpaid or draft invoices do not stop closure. They stop deletion later, and they stay collectable.

## 1. Start the offboarding
1. Select the tenant and choose **Start offboarding**.
2. Enter the reason, the customer reference and the end-of-service date if known.
3. The tenant keeps working. Exports can be requested (see `export.md`).
4. To withdraw before closing, choose **Cancel offboarding**. A reason is needed.

## 2. Close the tenant (end of service)
1. Choose **Close tenant** and enter a reason.
2. The closure is refused, with a reason, if a subscription still continues. The page lists it under *Before closing*.
3. Once closed:
   - nobody signs in, and all sessions are revoked;
   - API keys stop working;
   - queued work for the tenant is deferred;
   - the warehouse feed is switched off;
   - retention starts counting from today.
4. No employee is exited and nobody is notified. A tenant's exit is not an employee's exit.

## 3. Reopen (customer stays)
- Choose **Reopen tenant** with a reason. The tenant returns to the status it had (trial or active). The warehouse feed
  is restored if it was on, and deferred work is released.
- Reopening is refused while a deletion is planned or approved: cancel or reject it first.
- Reopening is refused **for good** once a deletion has started, even if it then stopped.
- A legal hold does not prevent reopening.
- After reopening:
  - deferred notifications are sent;
  - roles and permissions are unchanged (they were never touched);
  - API keys and integrations work again.
- No subscription restarts by itself. If the customer stays commercially, start one on **Subscriptions**.

## Checks
- **Platform audit trail:** `OFFBOARDING_REQUESTED`, `TENANT_CLOSED` or `TENANT_REOPENED`, with your name and reason.
- **Platform operations:** the tenant shows `closed`.
