# Runbook: tenant deletion

Audience: two platform operators (a maker and a checker). Page: **Platform → Offboarding → Deletion**.

> Deletion is **off** in every environment (`PEOPLEOS_TENANT_DELETION_ENABLED=false`). Turning it on for production is
> an owner decision, taken only after the SaaS.9 validation gate. Never turn it on for the showcase or a development copy.

## Preconditions (the page lists any that fail)
- The tenant is **closed** (`TENANT_NOT_CLOSED`).
- An export was **delivered**, or waived (`EXPORT_NOT_DELIVERED`). No export is generating (`EXPORT_IN_PROGRESS`).
- There is no open financial item: draft or unpaid invoices, payments in progress, payment exceptions, or refunds in
  progress (`FINANCIAL_*`). Resolve them in Billing or Payments. They are never cleared by deletion.
- No other deletion of this tenant is in flight (`DELETION_IN_FLIGHT`).
- Per category, the reason column of the plan shows whether it can go: an approved retention policy has ended, no hold
  covers it, and nothing kept depends on it.

## 1. Plan (maker)
1. Review the eligibility table:
   - **Delete:** retention has ended;
   - **Anonymise:** sign-in accounts that kept records still reference;
   - **Keep:** with the reason;
   - **Nothing to do.**
2. Choose **Plan deletion**, with a reason.

## 2. Approve (checker: a different operator)
1. Re-read the plan. Check the counts, the retained categories and the external dependencies.
2. Choose **Approve deletion**, with a reason. Approval is refused if anything changed since the plan: data, policy,
   hold, dependency, a reopen or an export. In that case, reject the plan and plan again.
3. The deletion is scheduled after the cooling-off period (14 days). **Cancel** remains possible until it starts.

## 3. Execution (automatic)
- The 15-minute tick dispatches the deletion when it is due. The page shows each step with its counts.
- A hold, a failure or a stopped worker stops it safely. See `recovery.md`.
- At the end the certificate is issued. **Certificate** downloads the signed JSON.
- **Final:** no customer data remains. The tenant becomes `deleted` and the case `completed`.
- **Partial:** some categories are still retained. The tenant stays `closed`. Plan again when retention ends.

## 4. External dependencies (after the run)
For each listed dependency (backups, integration systems, webhook receivers, the payment provider, delivered e-mail),
choose **Record** on its step and enter what was done outside PeopleOS:
- **Requested:** asked the provider or customer;
- **Confirmed:** written confirmation received (final);
- **Unsupported:** cannot be deleted by request (for example a webhook receiver's own copy).

Never confirm without evidence. The certificate shows the status at issue. Later confirmations are in the platform
audit trail.
