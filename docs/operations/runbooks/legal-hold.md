# Runbook: legal holds

Audience: platform operators, on instruction from Markedge legal. Page: **Platform → Offboarding → Legal holds**.

## Place a hold (immediate)
1. Choose **Place legal hold** and enter:
   - the scope (the whole tenant, or categories);
   - the reason;
   - the matter reference;
   - the start date.
2. A single operator can place a hold, at any time, for any tenant (operating, closed, or during a deletion).
3. The hold protects at once:
   - a deletion running now stops before its next batch;
   - the operational retention purge stops for the covered categories;
   - a planned deletion can no longer be approved unchanged.

## Release a hold (two operators)
1. Operator A chooses **Request release** with the reason (for example "matter settled", with the reference). The hold
   **still holds**.
2. Operator B, a different person, chooses **Approve release** or **Keep the hold**, with a reason.
3. Once released, a new deletion plan is needed. The old one is refused because the plan changed.

## Checks
- **Platform audit trail:** `LEGAL_HOLD_PLACED` / `LEGAL_HOLD_RELEASE_REQUESTED` / `LEGAL_HOLD_RELEASED` /
  `LEGAL_HOLD_RELEASE_DECLINED`.
- The plan shows `LEGAL_HOLD` with the hold reference for every covered category.
