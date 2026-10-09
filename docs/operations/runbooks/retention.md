# Runbook: retention policies (D-8)

Audience: platform operators, with legal or compliance advice. Page: **Platform → Retention policies** (`/admin/platform-retention-policies`).

PeopleOS ships **no** retention period and **no** legal minimum. Each period comes from Markedge's policy and, where a
law sets a minimum, from verified legal advice.

## Propose
1. Choose **Propose policy version** and enter:
   - the category;
   - the jurisdiction (an ISO country such as `IN`, or `*` for all);
   - the company policy, in months, with its basis;
   - the effective date (today or later);
   - the reason.
2. If a legal minimum is **verified**, enter it with its legal basis and reference (act and section). The policy cannot
   be shorter than it.
3. If no minimum is verified, leave it empty. The page shows `LEGAL_MINIMUM_NOT_CONFIGURED`. Never enter a guess.

## Approve
- A **different operator** approves or rejects the proposal, with a reason. The proposer can withdraw it while it is
  pending.
- A new version for the same category and jurisdiction applies from its effective date.
- For a closed tenant, the **longer** of the version in force at closure and the version in force today applies.

## Effect
- A category with no approved policy is **never deleted**. The plan shows `RETENTION_POLICY_NOT_CONFIGURED`.
- A category is kept until closure date + months. The plan shows `RETENTION_RUNNING` with the date.
- Markedge's billing, invoice, payment and subscription records are always retained under Markedge's own retention.
  They are not deleted with a tenant.

## Checks
- **Platform audit trail:** `RETENTION_POLICY_PROPOSED` / `APPROVED` / `REJECTED` / `WITHDRAWN`.
