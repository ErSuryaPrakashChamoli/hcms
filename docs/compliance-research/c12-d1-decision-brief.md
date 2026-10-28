# C12-D1 decision brief: settling a correction that moves payroll money

Prepared 2026-10-28 for the owner and the payroll lead. **Status: OPEN. Nothing here is decided.** The full paper is
"C12-D1" in `compliance-owner-decisions.md`.

**In one paragraph.**
- A finalised payroll month can no longer be changed by anyone.
- Ordinary pay mistakes are put right with an arrear or recovery in a later open month. The owner confirmed on
  2026-10-28 that these stay available.
- What remains undecided is narrower: how to settle money when the *statutory set-up* itself was wrong for a finalised
  month (EPF, ESI, PT, LWF or TDS applied or not applied wrongly). Those corrections can be requested and approved, but
  any money they move is blocked.
- Recommendation (not a decision): settle them with a linked forward adjustment in a later open month (option B), and
  keep them blocked for any statute the legal reviewer does not clear (§4.8 of the legal review pack, questions
  S1–S6).

## The question

A month's payroll is finalised. Later it turns out a statutory profile was wrong for that month: for example, EPF was
deducted where it did not apply, or ESI was not deducted where it did. Fixing the profile changes money. How is the
difference settled, and how do payroll, the statutory returns and the compliance pack show it?

## Where things stand (2026-10-28)

- **Every route that would move money is blocked.**
  - A correction that changes amounts can be requested and approved, but applying it is refused (C12-W, "payroll impact").
  - Since 2026-10-28, a finalised or paid payroll run can no longer be reopened, recalculated, changed or deleted. This
    holds for anyone, including holders of the finalise permission. The refusal happens in the service (on the locked
    row) and again in the models, and every refused attempt is audited.
  - Only an approved run that is not finalised yet can still go back to draft, and then it needs a new independent
    approval.
- **What still works:** ordinary arrears and recoveries in an open month (`PayrollAdjustment`), for pay mistakes. They
  need their own approval. The owner confirmed on 2026-10-28 that they stay available and that only corrections caused by
  a wrong statutory profile wait for this decision. Nothing links an adjustment to a statutory correction yet.
- **Not built:** supplementary or off-cycle runs; refunds or recoveries computed from a correction; amended returns for
  ESI, PT, LWF or TDS triggered by a correction. EPF has a revised-ECR flow, but under conditions and not linked to
  corrections. Interest, damages and penalties are not represented at all.

## The options

The letters match the full paper.

| | **B. Forward adjustment** (later month) | **D. Reopen and correct the original month** | **C. Supplementary or off-cycle run** | **A. Keep blocked; settle outside PeopleOS** |
|---|---|---|---|---|
| **What happens** | Apply the profile correction (later packs disclose it). Post a linked recovery or arrear in the next open month | Reopen the finalised run, change the profile, recalculate, re-finalise | A separate run for the past month carries only the difference (reverse and re-post the statutory lines) | The correction stays approved but unapplied. The settlement happens outside PeopleOS, with its evidence referenced on the correction |
| **Original results kept?** | **Yes.** The finalised month is untouched | **No**, unless a snapshot of entries, lines and payslips is added first. Payslips are issued at finalisation, so "never reopen once payslips are issued" means never reopening a finalised run at all | **Yes.** The original run stays; the difference is its own record | **Yes** |
| **Approval and separation of duties** | Existing: correction requester ≠ reviewer, and the adjustment's own approval. New: the adjustment must match the approved correction exactly | New: a reopen request with independent approval, on top of the run's normal re-approval | New: preparer ≠ approver for the supplementary run, plus approval of the correction | Existing: the C12-W approval only |
| **Statutory returns** | The original month's returns stay as filed; the difference appears in a later month's figures. **Whether a later-month adjustment is acceptable is a per-statute question** for the legal reviewer (EPF and ESI contributions are reported by wage month; TDS can usually be evened out within the year, but confirm) | The month's filed returns must be revised: EPF revised ECR (only under conditions), plus ESI, PT, LWF and the TDS correction statement. Ledgers and packs are superseded | Supplementary or revised returns per statute, where the authority allows them | Unchanged in PeopleOS; any revision is filed outside, so PeopleOS shows the original |
| **Recovery, refund and accounting** | Recovery from, or refund to, the employee in the next month's payroll and journal, referencing the original month. Employer-share refunds go through the authority, outside payroll | The original month's journal is reversed and re-posted. Unpaid runs only; a paid month would need payment recovery | A separate journal for the difference, dated as the policy decides | Entirely outside PeopleOS; records can diverge from reality |
| **Audit** | Correction → adjustment link; the adjustment's create and approve events exist today | A new reopen request and approval, a snapshot before any change, and supersession records | The run's own audit and its link to the correction | The correction record and an external evidence reference |
| **Engineering work** | **Medium**:<ul><li>a nullable link from adjustment to correction (schema change; rollback drops the links);</li><li>per-statute settlement rules;</li><li>per-employee amounts from the affected lines;</li><li>disclosure of the settlement in the pack;</li><li>tests</li></ul> | **Medium to high**:<ul><li>a snapshot table (schema change; rollback loses the snapshots);</li><li>a request and approval workflow;</li><li>return-revision triggers;</li><li>undoing today's guards for that path</li></ul> | **High**: a new run type, period rules, supplementary payslips, return revisions and tests | **None** |
| **Main risks** | A statute may not accept later-month settlement. Mid-year changes to tax regime or slabs | Losing the original figures. Returns and pack drift. It re-opens the bypass just closed | Size; a wrong design spreads into returns and payslips | The pack and the payroll keep a treatment now known to be wrong; settlement has no record in PeopleOS |

## Recommendation (not a decision)

- **B, forward adjustment,** for the general case. It keeps the finalised month as issued, reuses the arrear and
  recovery mechanism that already has approval and audit, and needs the least new engine work.
- **A** for any statute and direction the legal reviewer does not clear for B.
- **Not D.** Reopening is what was just closed, and it cannot keep issued payslips intact.
- **C** only if supplementary runs are wanted for other reasons.

## What a decision needs

- **Owner:** choose the option.
- **Payroll lead:** the adjustment and accounting treatment.
- **A qualified Indian labour-law and payroll professional, per statute and direction:**
  - EPF, ESI, PT and LWF: whether a later-month settlement is acceptable, who refunds, and when a revised return is
    needed;
  - TDS: in-year adjustment versus a correction statement;
  - interest, damages and penalties.

Until all three are recorded, money-moving corrections stay blocked, and no settlement policy is implemented.
