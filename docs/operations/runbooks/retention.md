# Runbook: retention policies (D-8)

Audience: platform operators, with legal or compliance advice. Page: **Platform → Retention policies** (`/admin/platform-retention-policies`).

PeopleOS ships **no** retention period and **no** legal minimum. Each period comes from Markedge's policy and, where a
law sets a minimum, from verified legal advice.

## Three inputs, kept apart

| Input | Where | Rule |
|---|---|---|
| A. Verified legal minimum | This page | Basis and reference, plus authoritative source, reviewer and date. Without all three it is **pending verification**, and the category is not deleted |
| B. Markedge company policy | This page | Never below a configured minimum. Counted from the closure, or from the end of the financial year in which the tenant closed |
| C. Customer contractual requirement | **Offboarding** page, per tenant (**Contract retention**) | A minimum extends retention. A deletion deadline is shown as a conflict if retention runs past it, and never deletes anything |

The legal facts researched so far, and the decisions open to you, are in `docs/saas/SaaS-9-Retention-Decision-Register.md`.

## Propose
1. Choose **Propose policy version** and enter:
   - the category;
   - the jurisdiction (an ISO country such as `IN`, or `*` for all);
   - the company policy, in months, with its basis;
   - the effective date (today or later);
   - the reason.
2. If a legal minimum is **verified**, enter it with:
   - its legal basis and reference (act and section);
   - the authoritative source (the official text or its URL);
   - the reviewer (name and role);
   - the verification date.

   The policy cannot be shorter than it.
3. If a minimum is believed to exist but is not verified yet, entering it without source, reviewer and date records it
   as `LEGAL_MINIMUM_PENDING_VERIFICATION`. The category then cannot be deleted until a verified version replaces it.
4. If no minimum applies or none is known, leave it empty. The page shows `LEGAL_MINIMUM_NOT_CONFIGURED`. Never enter a
   guess.
5. Choose what the period counts from: the tenant's closure, or the end of the financial year in which it closed (enter
   the year end, MM-DD; India's is 03-31).

## Approve
- A **different operator** approves or rejects the proposal, with a reason. The proposer can withdraw it while it is
  pending.
- A new version for the same category and jurisdiction applies from its effective date. Decided versions never change:
  a new rule is always a new version.
- An approved deletion plan is never rewritten by a later version. What a later version keeps is skipped at execution,
  not deleted.
- For a closed tenant, the **longer** of the version in force at closure and the version in force today applies.

## Effect
- A category with no approved policy is **never deleted**. The plan shows `RETENTION_POLICY_NOT_CONFIGURED`.
- A category is kept until closure date + months. The plan shows `RETENTION_RUNNING` with the date.
- Markedge's billing, invoice, payment and subscription records are always retained under Markedge's own retention.
  They are not deleted with a tenant.

## Customer contract terms (C)

On the **Offboarding** page, select the tenant and choose **Contract retention**. Enter:
- the category (or every category);
- keep at least (months after closure), and/or delete within (days after closure);
- the contract reference (agreement and clause);
- the effective date and a reason.

A different operator approves it. A conflict between a deletion deadline and the retention date is decided with counsel:
nothing is deleted automatically.

## Checks
- **Platform audit trail:** `RETENTION_POLICY_PROPOSED` / `APPROVED` / `REJECTED` / `WITHDRAWN`.
