# PHASE 6 — STATUTORY VERIFICATION & PRODUCTION READINESS

**STATUS:** Implementation complete. Statutory production readiness **NOT** declared.
**STARTING HEAD:** `126b77e` (Phase 5 end)
**ENDING HEAD:** the phase-6.7 documentation commit (the commit that adds this report)
**BRANCH:** `main`
**PUSHES:** 0 (40 commits ahead of `origin/main`, 0 behind)

| Measure | Result |
|---|---|
| Tests | 465 |
| Assertions | 4,357 |
| Failures | 0 |
| Pint | PASS |
| Architecture tests | 18 PASS (12 existing + 6 compliance invariants) |
| Security tests | 7 dedicated (ComplianceSecurityTest 4, StatutoryIsolationTest 3) PASS, plus scope / SoD cases in the other compliance suites |
| Migration replay | PASS — `migrate:fresh --seed` on a temporary MySQL database: 207 tables, columns and indexes identical to dev except the pre-existing dev drift `tenants.base_currency`; temporary database dropped |
| Audit verification | PASS — platform 6 events, demo 582 events |

| Inventory | Count |
|---|---|
| Rules | 22 |
| Verified | **0** |
| In review | 1 (ESI v1) |
| Draft | 21 |
| Rejected | 0 |
| Open regulatory notices | 2 (EPF v1, TDS v2) |
| Export layouts | 5 |
| Verified | **0** |
| Unverified | 5 (all DRAFT) |
| Establishments (dev demo tenant) | Configured 2 · Verified 0 |
| Registrations (dev demo tenant) | 0 recorded (the legacy profile has no numbers) |
| Parallel payroll | Completed 0 · Reconciled 0 · Outstanding differences — none recorded |

## 1. DISCOVERY

Baseline matched the brief: clean tree, HEAD `126b77e`, 33 ahead / 0 behind, 82 migrations.
Phase 5 began from `079952a` (the brief lists `0213b1d`, its first commit). The baseline suite had
one failure: `AuditTrailTest › masks sensitive attributes`. It is time-dependent and pre-existing;
`password_changed_at` shows as changed only when user creation and update straddle a second. It
was made deterministic without touching product code. Full gap analysis:
`docs/PeopleOS-Phase-6-Discovery.md`.

## 2. RULE VERIFICATION

Maker-checker kept and strengthened: official-domain evidence, a stored evidence document,
parameter coverage and no open regulatory notice are now required, as is a verifier other than the
submitter who is not relying only on their own uploads. Verification is by platform
administrators only; tenant administrators cannot verify. UI terms are "Submit evidence",
"Attach evidence document", "Verify", "Reject" and "Publish corrected version"; there is no
"Approve" that means verify.

## 3. EVIDENCE MANAGEMENT

`compliance_evidence_documents` stores each document privately with its SHA-256, retrieval date,
source URL and uploader. Documents are append-only and cannot be added once a version is VERIFIED;
a correction is a new version. `compliance_rule_verifications` now records the retrieval date.

## 4. PARAMETER COVERAGE

`compliance_rule_parameters` records one row per payload parameter per submission: covered (with
excerpt), not confirmed, or not applicable (with justification). Anything not confirmed or
unjustified blocks verification. Phase 5 submissions were backfilled from their mapping.

## 5. EPF

Validated against official EPFO and Ministry sources. The ECR structure, the revamped-ECR rules and
the supplementary / revised handling stand as built in Phase 5. **New finding:** the Ministry of
Labour & Employment (PIB, 16 Sep 2026) and an EPFO release (PIB 2313829, 23 Sep 2026, citing
Gazette Notification **S.O. 5109(E)**) raise the wage ceiling from ₹15,000 to ₹25,000 per month
from **17 September 2026**. The pensionable wage ceiling is also ₹25,000.

Not established from the legal text:
- the EDLI ceiling;
- how the September 2026 wage month is treated, since the change takes effect mid-month;
- the gazette text itself, which was not retrieved.

EPF v1 is therefore blocked by a regulatory notice, and no v2 was authored with guessed values.

## 6. ESI

Remains in REVIEW. The Phase 5 pack evidence (esic.gov.in) leaves `round` not confirmed and has no
stored document, so verification is refused until a reviewer supplies both. The ₹25,000 limit for
persons with disability and the ₹176 daily-wage exemption are still not modelled.

## 7. TDS

The FY 2026-27 rule (v2) cites the Finance Act 2025 "carried forward". The Finance Bill, 2026 as
introduced (indiabudget.gov.in, SHA-256 `5c987017…abf`) shows the correct basis for tax year
2026-27: tax on salaries deducted under **section 392 of the Income-tax Act, 2025** at the rates in
Part III of the First Schedule, with the new regime under section 202. The enacted Finance Act,
2026 text was not retrieved, so TDS v2 is blocked by a regulatory notice and no corrected version
was authored. Forms stand as in Phase 5: Form No. 138 (formerly 24Q) and Form No. 130 (formerly
Form 16), filed under Rule 219. The utility / FVU schema and the certificate layout remain
unverified, so exports are working schedules.

## 8. PROFESSIONAL TAX

State-specific rule versions, establishment state and PT returns are unchanged from Phase 5.
Verification scope is now derived from the tenant's establishments (RequiredRules). For the demo
tenant that is PT/KA, which is DRAFT; states without a verified rule stay blocked.

## 9. LWF

Unchanged and establishment / state-aware. The demo tenant's legacy profile has LWF off, so no LWF
rule is required there. All LWF rule versions are DRAFT.

## 10. EXPORT LAYOUT VERIFICATION

`statutory_export_layouts` makes layouts versioned, immutable, checksummed specifications with
maker-checker verification against a stored copy of the authority's specification. Returns record
the layout version used. Every export is structurally validated before the file is stored:
encoding, line endings, header, field count and order, required fields and value formats. All five
layouts are DRAFT, and files are marked `UNVERIFIED-FORMAT_`.

## 11. ESTABLISHMENT VERIFICATION

Legal entities and establishments now distinguish configured from verified. The maker submits the
certificate reference (optional stored copy); the checker has the new `*.verify` permission and did
not submit. Editing an identity field resets verification. Registration verification is audited as
`REGISTRATION_VERIFIED` and resets when the name or jurisdiction changes.

## 12. PRODUCTION GATE

`ComplianceReadiness` was extended in place to 14 checks (payroll finalized, legal entity,
establishment, registration verified, explicit assignments, rules verified without notices,
versions captured, evidence complete, layout verified, file valid, reconciled, no blocking
exceptions, approval SoD, audit chain). There is no override. Under enforcement an export must pass
it, and enforcement is forced on in production. RequiredRules and the control room show what the
tenant needs and what is verified.

## 13. PARALLEL PAYROLL

Built: `parallel_payroll_runs` / `_lines`. Reference CSV is imported by a person for a finalized
run and compared per employee × component and per statutory return total. **No parallel run has
been performed:** that needs the business's own reference payroll, which is outside this work.

## 14. RECONCILIATION

Return-to-payroll reconciliation (Phase 5) still blocks approval on any mismatch. Parallel
reconciliation needs every open line resolved with reason and resolution by someone other than the
importer, sign-off by someone other than the importer, and at least one matched return total.
Offsetting differences that leave totals equal still block.

## 15. RETURN CONTROL

Lifecycle, immutability after approval and revised / supplementary handling are unchanged. The
return view shows the layout version, file-structure result and portal validation.

## 16. FILING CONTROL

The chain is exported → portal-validated (human-entered result and reference) → submitted
(portal reference, filer ≠ generator and approver) → acknowledged. No reference is fabricated and
nothing is filed automatically.

## 17. API

Unchanged endpoints, still tenant-scoped, permission-controlled and masked. Return detail now
carries the 14-check readiness. No evidence documents or internal verification data are exposed.

## 18. SECURITY

- Compliance and establishment services no longer call `withoutGlobalScopes()`; the tenant scope
  always applies.
- A new `establishment` access-scope dimension limits users to chosen establishments' statutory
  data.
- Tests cover:
  - tenant, company and establishment isolation;
  - API IDOR;
  - maker-checker bypass attempts;
  - filing authority;
  - masking.

## 19. AUDIT

New actions:
- `STATUTORY_RULE_EVIDENCE_ATTACHED`, `STATUTORY_RULE_NOTICE_RECORDED` / `_RESOLVED`
- `EXPORT_LAYOUT_SUBMITTED` / `_VERIFIED` / `_REJECTED`
- `LEGAL_ENTITY_VERIFIED`, `ESTABLISHMENT_VERIFIED`, `REGISTRATION_VERIFIED`, `VERIFICATION_SUBMITTED`
- `STATUTORY_PORTAL_VALIDATION_RECORDED`
- `PARALLEL_RUN_IMPORTED`, `PARALLEL_DIFFERENCE_REVIEWED`, `PARALLEL_RUN_RECONCILED`

Return actions keep the Phase 5 names: `STATUTORY_OUTPUT_APPROVED`, `_EXPORTED`, `_SUBMITTED`
(filed) and `_RECONCILED`. Everything goes through the existing hash-chained `AuditRecorder`.

## 20. DOMAIN EVENTS

`ComplianceEvent` fires for `compliance.establishment_verified`, `return_approved`,
`return_exported`, `return_filed` and `return_reconciled`, consumed by the existing webhook bridge.
Rule and layout events are platform-level with no tenant consumer, so they are audited only.

## 21. PAYROLL VERSION COMPATIBILITY

Tested:
- An approved 2.0 run is refused at finalization, reopened with a reason, recalculated on 2.1 and
  finalized.
- A finalized 2.0 run stays immutable.
- A 2.1 calculation records the establishment context.

## 22. TESTS

Phase 6 suites:
- Compliance: `RegulatoryReadinessTest` (7), `ExportLayoutTest` (4), `LegalStructureVerificationTest` (4), `ProductionGateTest` (9), `ParallelPayrollTest` (4), `StatutoryIsolationTest` (3)
- Payroll: `PayrollVersionCompatibilityTest` (3)
- Architecture: `ComplianceInvariantsTest` (6)

Adapted on purpose:
- `RuleVerificationTest`, `StatutorySafetyTest`: evidence documents and resolved notices are now required.
- `ComplianceControlRoomTest`: the gate has new checks.
- `AuditTrailTest`: de-flaked.

## 23. MIGRATION VALIDATION

Four additive migrations, `2026_10_05_100001`–`100004`; no earlier migration was modified.
`migrate:fresh --seed` ran on a temporary database and matched dev apart from the known drift. The
dev database was migrated incrementally and never reset.

## 24. DOCUMENTATION

- `docs/architecture/statutory-production-readiness.md` (new)
- `docs/PeopleOS-Phase-6-Discovery.md` (new)
- `docs/PeopleOS-Phase-6-Report.md` (this report)
- Updated: `docs/compliance/india-statutory-rule-verification.md`, `docs/architecture/payroll-compliance-architecture.md`

## 25. VERIFIED RULES

None.

## 26. UNVERIFIED RULES

All 22:
- **REVIEW:** ESI v1 (coverage gap on `round`, no stored document).
- **DRAFT, blocked by a notice:** EPF v1 (wage ceiling from 17 Sep 2026), TDS v2 (FY 2026-27 legal basis).
- **DRAFT, no evidence yet:** TDS v1 (FY 2025-26), PT (AP, GJ, KA, KL, MH, MP, TG, TN, WB), LWF (DL, GJ, HR, KA, MH, TG, TN), GRATUITY, UAE SS.

## 27. VERIFIED EXPORT LAYOUTS

None.

## 28. UNVERIFIED EXPORT LAYOUTS

EPF_ECR v1, ESI_MC v1, PT_RETURN v1 (working schedule), LWF_RETURN v1 (working schedule),
TDS_FORM_138 v1 (working schedule, not the FVU file) — all DRAFT.

## 29. PARALLEL RUN RESULTS

No parallel run has been performed. It requires one finalized production-like month and the
business's reference values.

## 30. DEFERRED ITEMS

- Authoring EPF v2 from the gazette text of S.O. 5109(E) (EDLI ceiling, September 2026 treatment).
- Authoring TDS for tax year 2026-27 from the enacted Finance Act, 2026 and the Income-tax Act, 2025.
- Obtaining and verifying the upload specifications: ECR Help File, ESIC template, state PT/LWF forms, Form No. 138 utility.
- Recording and verifying each production establishment's registrations and certificates.
- A parallel month with the business's reference payroll.
- Portal validation of generated files.

## 31. KNOWN LIMITATIONS

Carried from Phase 4 and 5:
- Split-month PF/ESI eligibility uses the latest segment; split-month fixed components are paid once.
- SQLite tests do not exercise row locks.
- A finalized but unpaid run can be reopened with a reason.
- Pending leave does not reduce payroll; rehire accrual is unresolved; the leave year and payroll period are independent.
- A mid-month establishment transfer is attributed on the period end.
- ESI coverage continuity is detected but not applied.
- EPS eligibility, the EPF 10% option, the ESI disability limit and the ₹176 exemption are not modelled.
- No FVU / TRACES / challan / portal integration.

New in Phase 6:
- A payroll month straddling a regulatory notice date is blocked as a whole, not split.
- Parallel reference values are imported as CSV only.
- The `establishment` scope hides legal-entity-level outputs (TDS) from establishment-scoped users by design.

## 32. GIT COMMITS

| Commit | Scope |
|---|---|
| `2c3218d` phase-6.1 | Discovery; evidence documents, parameter coverage, corrections, regulatory notices, production enforcement |
| `0559231` phase-6.2 | Versioned export layouts, structural and portal validation |
| `b0302b4` phase-6.3 | Legal entity, establishment and registration verification; compliance events |
| `ade3442` phase-6.4 | Production gate, required-rule scope, control room |
| `4496503` phase-6.5 | Controlled parallel payroll reconciliation |
| `953bc66` phase-6.6 | Tenant-scope hardening, establishment isolation, payroll-version and invariant tests |
| phase-6.7 | Documentation and this report |

## 33. FINAL PRODUCTION READINESS ASSESSMENT

| State | Status |
|---|---|
| Software ready (controls implemented and tested) | **Yes** |
| Statutory rules verified | **No** (0 / 22, 2 open notices) |
| Export layouts verified | **No** (0 / 5) |
| Establishments / registrations verified | **No** |
| Parallel payroll reconciled | **No** (not performed) |
| Production payroll ready | **No** — finalization under enforcement is blocked |
| Returns filed | **None** — PeopleOS never files |

The production gate is stronger than the implementation gate. Only evidence reviewed by two people
and a reconciled parallel month can open it.

## 34. RECOMMENDED NEXT PHASE

A controlled **evidence and pilot phase**, run by the project owner's compliance reviewers, not by
code:

1. Obtain the S.O. 5109(E) gazette text and the enacted Finance Act, 2026, then author and verify
   EPF v2 and the TDS tax-year 2026-27 version.
2. Verify ESI, EPF v1 (for dates before 17 Sep 2026), and the PT/LWF states actually in use.
3. Obtain and verify the upload specifications.
4. Verify the pilot tenant's legal entities, establishments and registrations.
5. Run and reconcile one parallel month.

No new HCM module should start until this is reviewed.
