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
  covers it, and nothing kept depends on it. These reasons keep a category:
  - `RETENTION_POLICY_NOT_CONFIGURED`: no approved policy;
  - `LEGAL_MINIMUM_PENDING_VERIFICATION`: a legal minimum was entered but not verified;
  - `RETENTION_RUNNING`: the retention date, including any customer-contract minimum, has not passed;
  - `LEGAL_HOLD`;
  - `PROTECTED_BY_DEPENDENCY`.
- **Contract conflicts** shown above the plan mean a customer's contract requires deletion sooner than the data is kept.
  Nothing is deleted because of them. Resolve each one with counsel (a new policy version, a contract change or a
  documented decision) before planning.

## 1. Plan (maker)
1. Review the eligibility table:
   - **Delete:** retention has ended;
   - **Anonymise:** sign-in accounts that kept records still reference;
   - **Keep:** with the reason;
   - **Nothing to do.**
2. Choose **Plan deletion**, with a reason.

## Who may act (D-15)

Any two **different** platform operators: one plans, the other approves.
- **The maker** can never approve the same deletion. The page does not offer it, and the service refuses it.
- **A tenant user** can never plan, approve, cancel, reject or retry, or record an external copy. **No API route**
  reaches any of these.
- **One operator alone** cannot shorten the cooling-off: it is set by the environment
  (`PEOPLEOS_DELETION_COOLING_OFF_DAYS`, default 14), not on the page.
- **A hold** can only be released by two operators.

A dedicated "deletion approver" role is a D-15 option for later (the SaaS.10 control plane). It is not configured, and
the existing two-operator rule applies.

## 2. Approve (checker: a different operator)
1. Re-read the plan. Check the counts, the retained categories and the external dependencies.
2. Choose **Approve deletion**, with a reason. Approval is refused if anything changed since the plan: data, policy,
   hold, dependency, a reopen or an export. In that case, reject the plan and plan again.
3. The deletion is scheduled after the cooling-off period (14 days). **Cancel** remains possible until it starts.

## 3. Execution (automatic)
- The 15-minute tick dispatches the deletion when it is due. The page shows each step with its counts.
- A hold, a failure or a stopped worker stops it safely. See `recovery.md`.
- At the end the certificate is issued. **Certificate** downloads the signed JSON.
- **Export packages:** any package files still stored for the tenant are removed, because they copy the deleted data.
  The export records, checksums and manifests stay.
- **The certificate's claims:** it states its scope (PeopleOS's database and file storage), lists
  `external_copies_outstanding`, and says `complete_including_external_copies`. That is true only when every external
  copy is `CONFIRMED`. Never describe a tenant as completely deleted while it is false.
- **Final:** no customer data remains in PeopleOS. The tenant becomes `deleted` and the case `completed`.
- **Partial:** some categories are still retained. The tenant stays `closed`. Plan again when retention ends.

## 4. External dependencies (after the run)
For each listed dependency, choose **Record** on its step and enter what was done outside PeopleOS. The dependencies
are:
- database backups;
- file storage backups and replicas;
- integration systems;
- webhook receivers;
- the payment provider;
- delivered e-mail.

The statuses are:
- **REQUESTED:** asked the provider or customer;
- **FAILED:** the request failed or was refused;
- **RETENTION_REQUIRED:** the copy must be kept until a date (for example the backup schedule); enter that date;
- **UNSUPPORTED:** cannot be deleted by request (for example a webhook receiver's own copy);
- **CONFIRMED:** written confirmation received (final).

Never confirm without evidence. The certificate shows the status at issue. Later records are in the platform audit trail
and on the page. Full procedure: `external-copies.md`.
