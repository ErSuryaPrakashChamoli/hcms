# Statutory Production Readiness (Phase 6)

For: platform administrators, compliance reviewers and engineers who move PeopleOS statutory
payroll from "implemented" to "production eligible".

```
SOFTWARE READY  ≠  STATUTORY RULES VERIFIED  ≠  PRODUCTION PAYROLL READY  ≠  RETURN FILED
```

Only verified evidence and controlled operational reconciliation move the system between those
states. Tests prove software behaviour; they never establish legal correctness. Nothing in PeopleOS
marks a rule, layout, establishment or registration verified automatically, fabricates evidence or
references, or records a filing.

## 1. Rule verification workflow

```
DRAFT ─(submit evidence)→ REVIEW ─(verify, second platform admin)→ VERIFIED ─(verified correction)→ SUPERSEDED
   └────────────────────────(reject)──────────────────────────→ REJECTED
```

| Step | Who | Requires |
|---|---|---|
| Submit evidence | Platform administrator (maker) | Official HTTPS source on an authoritative domain, title, effective date, retrieval date, quoted requirement, and coverage for **every** payload parameter |
| Attach evidence document | Platform administrator | The stored official document; SHA-256 and retrieval date kept; append-only |
| Verify | A **different** platform administrator (checker) | At least one evidence document not only uploaded by the verifier, complete parameter coverage, no open regulatory notice, intact checksum, notes |
| Reject | Platform administrator | Reason |
| Correct | Platform administrator | New version with `corrects_rule_id` and a reason; the corrected version is never edited |

Evidence can be resubmitted while in REVIEW; verified evidence can never change. Every step is a
`compliance_rule_verifications` row and a platform audit event (`STATUTORY_RULE_CREATED`,
`_REVIEWED`, `_EVIDENCE_ATTACHED`, `_VERIFIED`, `_REJECTED`, `_SUPERSEDED`,
`_NOTICE_RECORDED`, `_NOTICE_RESOLVED`).

## 2. Evidence requirements

- **Allowed sources:** the authority's own site or the gazette (`peopleos.compliance.authoritative_domains`).
  Blogs, payroll vendors, news, legal portals, search snippets and AI summaries are refused.
- **Recorded:** authority, source title, URL, publication date when stated, retrieval date, stored
  document (SHA-256), quoted requirement, parameter coverage, notes, submitter, verifier, time.

## 3. Parameter coverage

Each submission writes one `compliance_rule_parameters` row per payload parameter:

| Status | Meaning | Verification |
|---|---|---|
| covered | Requirement excerpt from the source supports the value | allowed |
| not_confirmed | The source does not establish the value (mapping text starts with "NOT CONFIRMED") | **blocks** |
| not_applicable | The parameter does not apply, with a justification | allowed only with a justification |

## 4. Regulatory change notices

When official evidence shows a version is out of date or wrongly based but the replacement
parameters are not yet established from the legal text, a notice is recorded
(`database/data/compliance/notices/*.php`, insert-only). While open it:

- blocks verification of the affected versions;
- raises `statutory_change_pending` in payroll on or after its effective date (blocking under
  enforcement) and fails the per-run finalization gate;
- fails `rules_verified` in the return production gate.

It is resolved only by linking a new version that is effective on the notice date (that version
still needs its own verification). Current notices: EPF v1 (wage ceiling ₹25,000 from 17 Sep 2026,
S.O. 5109(E)) and TDS v2 (tax year 2026-27 under the Income-tax Act, 2025).

## 5. Export layout verification

Calculation verified ≠ export layout verified. Layouts are versioned, immutable, checksummed
specifications (`statutory_export_layouts`: delimiter, quoting, header, encoding, line ending,
field order, required fields, value formats). Maker-checker verification requires a stored copy of
the authority's upload specification from an official domain; a newer verified version supersedes
the older one. Each return records the layout version used. Every export is structurally validated
against its layout before the file is stored; a failing file is refused. Files from unverified
layouts carry `UNVERIFIED-FORMAT_`.

## 6. Establishment, legal entity and registration verification

Configured ≠ verified. Legal entities and establishments are verified against their certificates by
someone with `legal_entity.verify` / `establishment.verify` who did not submit them; changing an
identity field (name, state, address, identifiers…) resets verification. Registrations are verified
against the allotment letter or certificate by someone other than their recorder, and reset when
their name or jurisdiction changes.

Employees must be explicitly assigned to establishments (`employee_establishment_assignments`);
statutory state always resolves Employee → Establishment → State. Lines resolved through the
company's principal establishment or at return time fail the `employees_assigned` gate check.

## 7. Production gate

`ComplianceReadiness::forReturn()` — one gate, no override:

| Check | Passes when |
|---|---|
| payroll_finalized | Every payroll run behind the return is still finalized or paid |
| legal_entity_verified | The legal entity is verified |
| establishment_verified | The establishment is verified (not applicable to TDS) |
| registration_verified | The registration on the return is verified |
| employees_assigned | Every line's employee was explicitly assigned when payroll ran |
| rules_verified | Every rule version used is VERIFIED, intact, without an open notice |
| rule_version_captured | Rule versions and checksums captured in snapshots |
| evidence_complete | Every rule has a stored document and full parameter coverage |
| export_layout_verified | The layout version used is verified and intact |
| reconciled | Payroll reconciliation balanced |
| no_blocking_exceptions | Zero blocking validation issues |
| approved_with_separation_of_duties | Approved by someone other than the generator |
| audit_chain_intact | Tenant audit hash chain verifies |
| export_locally_validated | The exported file passed its layout (after export) |

Under enforcement an export must pass every check except the file check that export performs.
Enforcement is **always on in production**; `PEOPLEOS_ENFORCE_VERIFIED_RULES=false` only works in
development and tests and is never a readiness mechanism.

## 8. Parallel reconciliation

One complete payroll month per production-like population:

1. Finalize the month in PeopleOS.
2. Import the business's reference values (Compliance → Parallel payroll) as CSV
   `level,employee_code,scope,component,amount`: `employee` rows for every employee × component
   (GROSS, NET, BASIC, PF_EE, ESI_EE, PT, TDS…), `return` rows for statutory totals with scope
   `TYPE:ESTABLISHMENT_CODE` (e.g. `EPF:MAIN,ee_share`).
3. Compare. Every key on either side becomes a line: matched, difference, missing reference,
   missing in PeopleOS, or (return totals not supplied) not compared.
4. A reviewer who did not import the file records each difference's reason and resolution. Never
   change a rule to make totals match; understand the difference first.
5. Sign off (someone other than the importer). Allowed only when every open line is resolved and at
   least one statutory return total matched. Offsetting differences that leave totals equal still
   block. A reconciled run is immutable.

## 9. Filing controls

```
file generated → file locally validated → file accepted by portal → return filed → acknowledged → reconciled
```

- **Export** produces the file after structural validation; it is not filing.
- **Portal validation** records the authority's validation result and reference (human-entered).
- **Submission** needs the portal reference and a filer who is neither generator nor approver
  (`compliance.returns.file`); **acknowledgement** records the authority's reference.
- PeopleOS never fabricates a reference and never marks anything filed automatically.

## 10. Troubleshooting

| Symptom | Cause | Action |
|---|---|---|
| Payroll exception `statutory_change_pending` | Open regulatory notice for a rule used | Author the new version from the legal text, resolve the notice, verify the version |
| `unverified_statutory_rule` / finalization refused | Rule version not VERIFIED | Complete evidence and coverage; a second platform admin verifies |
| Verify refused: "Parameter coverage is incomplete" | A parameter is not confirmed or unjustified | Obtain the source that establishes it, resubmit |
| Export refused: "production gate blocks" | Gate checks failing (listed) | Fix each failing check; there is no override |
| Export refused: "failed structural validation" | File does not match the layout | Fix the data (identifiers, amounts) or publish a corrected layout version |
| Gate `employees_assigned` fails | Employees without explicit establishment assignment | Assign them (Organisation → Establishment assignments) and recalculate payroll |
| Establishment turned unverified | Identity field edited | Resubmit against the certificate |

## 11. Operational checklist (per tenant, before production)

1. Legal entities and establishments configured, submitted and verified against certificates.
2. Registrations recorded and verified for every establishment and applicable statute.
3. Statutory profiles per establishment; every statutory employee explicitly assigned.
4. Compliance control room → "Rules this tenant needs": every required rule VERIFIED, no open notice.
5. Every export layout in use VERIFIED against the authority's current specification.
6. One parallel month reconciled end to end.
7. Enforcement on (production), audit chains verified.
8. Filing performed by a person on the portal; references recorded in PeopleOS.
