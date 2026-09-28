# PeopleOS Phase 6 — Statutory Verification & Production Readiness: Discovery

- **Date:** 28 September 2026
- **Baseline:** branch `main`, HEAD `126b77e`, clean tree, 33 commits ahead / 0 behind `origin/main`,
  82 migrations applied. Phase 5 began from `079952a`; `0213b1d` is its first commit (the brief lists
  0213b1d as the starting HEAD).
- **Baseline tests:** 425 tests, 424 passed, 1 failed: `AuditTrailTest › masks sensitive
  attributes`. The failure is time-dependent and pre-existing: `password_changed_at` appears as a
  changed field only when user creation and update straddle a one-second boundary. It passes in
  isolation (3/3). The test was made deterministic; no product code changed.

## 1. What Phase 5 already provides (kept, not rebuilt)

Rule versions with immutability, checksum and a maker-checker verification workflow; per-run
verified-rule gate; registrations with a verify step; returns with lifecycle, separation of duties,
snapshots, reconciliation, exported ≠ submitted; control room with a nine-control gate; masked API.

## 2. Gaps against the Phase 6 brief

| Brief | Gap found | Decision |
|---|---|---|
| §6 retrieval date, evidence document | Verification rows keep URL/title/text/mapping, but no retrieval date and no stored document | Add evidence documents (stored privately, SHA-256, retrieval date); verification requires at least one |
| §7 parameter coverage | Coverage is a free-text mapping; "NOT CONFIRMED" does not block verification | Add parameter-level coverage rows (covered / not confirmed / not applicable with justification); verification refused unless every payload parameter is covered or justified |
| §8 corrections | A new version can be published, but nothing records that it corrects a verified version, or why | Add `corrects_rule_id` + `correction_reason`; required when the corrected version is VERIFIED |
| §10, §12 current law | EPF and TDS rules are known to be out of date (below), but nothing in the system says so | Add platform **regulatory change notices** that block verification and (under enforcement) payroll for affected dates until resolved by a new version |
| §9 scope | No view of which rule versions the tenant's establishments actually need | Derive required rules from active establishments, their statutory profiles and states |
| §16–17 export layouts | Layouts live in config with a static `verification_status`; no maker-checker, versioning or structural validation | Versioned, immutable export-layout registry with maker-checker verification; returns record the layout version; exports are structurally validated against it |
| §18–20 production gate | Gate does not check establishment/legal-entity verification, registration **verification**, payroll still finalized, explicit employee assignments, local layout validation, or pending regulatory change | Extend `ComplianceReadiness` (no new gate class) |
| §19 establishment verification | No verification state on legal entities or establishments | Add maker-checker verification with certificate reference; identity edits reset it |
| §22–23 parallel run | Not present | Parallel payroll runs: human-imported reference values, employee × component comparison, statutory and return totals, every difference needs reason, resolution and an independent reviewer |
| §24 portal validation | Only submission/acknowledgement are recorded | Record portal validation (accepted / rejected, reference) as a separate human-entered fact |
| §31–32 audit, events | Rule / output audit exists; no layout, establishment, notice or parallel-run audit; no compliance domain events | Add audit actions; add `ComplianceEvent` for tenant-level outcomes consumed by the existing webhook bridge |
| §33 environment gate | `PEOPLEOS_ENFORCE_VERIFIED_RULES=false` also works in production | Enforcement is forced on in the production environment regardless of the variable |
| §34 payroll-2.1 | Behaviour exists; no explicit 2.0 → 2.1 test | Add tests |
| §39 invariants | Partly covered | Add compliance architecture invariants |

## 3. Official evidence reviewed in this phase (government sources only)

- **EPF — wage ceiling changed.** Ministry of Labour & Employment / PIB, "Cabinet Approves Higher
  EPFO Wage Ceiling of Rs. 25,000" (posted 16 Sep 2026; PDF on labour.gov.in, SHA-256
  `a31038ee…0677`): ceiling for mandatory coverage raised from ₹15,000 to ₹25,000 per month with
  effect from **17 September 2026**. PIB release 2313829 (23 Sep 2026, EPFO RO Berhampore): follows
  **Gazette Notification S.O. 5109(E)**; pensionable wage ceiling also ₹25,000 (maximum employer EPS
  contribution ₹2,083). Not established: the EDLI ceiling, the treatment of the September 2026 wage
  month (change mid-month), and the gazette text itself (not retrieved).
  **Consequence:** EPF v1 (₹15,000, from 2014) must not be verified for dates on or after
  17 Sep 2026, and no new version is authored with guessed parameters.
- **TDS — legal basis for tax year 2026-27.** The Finance Bill, 2026 as introduced (indiabudget.gov.in,
  SHA-256 `5c987017…abf`) provides that tax on "Salaries" deducted under **section 392 of the
  Income-tax Act, 2025** is at the rates in **Part III of the First Schedule**, with the new regime
  under **section 202** of that Act (amended by clause 47). The enacted Finance Act, 2026 text was not
  retrieved. **Consequence:** TDS v2 (citing the Finance Act 2025, "carried forward") has the wrong
  legal basis and must not be verified; a corrected version needs the enacted text.
- **ESI, EPF ECR, Form 138 / 130:** as recorded in Phase 5 (no change found).

## 4. Principles for this phase

- Nothing is marked VERIFIED by the software or by this work. Tests prove behaviour, not law.
- No evidence, reference, acknowledgement or filing is fabricated.
- Where the law changed and the parameters are not established, the system blocks rather than
  guesses.
- Phase 5 architecture is extended in place; no parallel gate, audit or lifecycle is created.

## 5. Commit plan

6.1 evidence documents, parameter coverage, corrections, regulatory notices, environment gate ·
6.2 export-layout registry and structural validation · 6.3 legal-entity / establishment
verification, registration-verified gate, assignment coverage · 6.4 production gate, portal
validation, compliance events, required-rule scope, control room · 6.5 parallel payroll ·
6.6 payroll-version and architecture invariants, security · 6.7 documentation and report.
