# Statutory Output Lifecycle

For: compliance and payroll operators who prepare, approve and file statutory returns in PeopleOS.

Every statutory output — EPF ECR, ESI monthly contribution, professional tax, labour welfare fund
and the TDS quarterly statement (Form No. 138) — moves through the same lifecycle, held on a
`statutory_returns` header. Type-specific content lives in its own tables.

## States

```
DRAFT → CALCULATED → VALIDATED → APPROVED → EXPORTED → SUBMITTED → ACKNOWLEDGED → RECONCILED
            │              ▲
            └──────────────┴── RECONCILIATION_REQUIRED (payroll or filing mismatch)
APPROVED and later ──(approved revision)──→ REVISED        before submission ──→ CANCELLED
```

| Status | Set by | What it means |
|---|---|---|
| DRAFT | Generation start | Header exists; content not built yet |
| CALCULATED | Generate | Lines built from **finalized** payroll; may have blocking issues |
| VALIDATED | Validate & reconcile | Zero blocking issues and a balanced payroll reconciliation |
| RECONCILIATION_REQUIRED | Validate, or filing reconciliation | Return totals differ from payroll, or from what the authority acknowledged |
| APPROVED | Approve | Content frozen; immutable snapshots captured |
| EXPORTED | Export | A file was produced. **Nothing has been filed.** |
| SUBMITTED | Record submission | A person recorded the portal reference of an actual filing |
| ACKNOWLEDGED | Record acknowledgement | The authority's acknowledgement reference is recorded |
| RECONCILED | Reconcile filing | Acknowledged amounts match the return totals |
| REVISED | Approving a revision | A later approved return replaces this one |
| CANCELLED | Cancel (reason required) | Withdrawn before submission; its uniqueness slot is freed |

PeopleOS never files anything and never marks a return filed on its own. SUBMITTED needs an
external reference typed in by a person with `compliance.returns.file`.

## Separation of duties

| Step | Permission | Who may not do it |
|---|---|---|
| Generate, validate, cancel | `compliance.returns.generate` | — |
| Approve | `compliance.returns.approve` | The person who generated the return |
| Export | `compliance.returns.export` | — |
| Record submission / acknowledgement | `compliance.returns.file` | The generator and the approver |
| Reconcile filing | `compliance.reconcile` | — |

Every step writes a `statutory_return_actions` row (user, time, company, establishment, action,
reason, source ui/api/job/cli, content version) and a `STATUTORY_OUTPUT_*` audit event in the
tenant hash chain. Viewing a return or its entries writes `STATUTORY_OUTPUT_ACCESSED`.

## Immutability

- Content (header totals, rule versions, payroll runs, validation, lines) can change only in DRAFT,
  CALCULATED, VALIDATED or RECONCILIATION_REQUIRED. After approval the model refuses changes.
- Snapshots (`statutory_snapshots`) are written once, at approval: payroll run and entry, employee,
  establishment, legal entity, regime, rule version and checksum, inputs, calculated values and
  output values. They cannot be updated or deleted.
- A correction is a revision: a new return that references the original. Its snapshots point to the
  ones they supersede. EPF revisions follow EPFO's rules (see the EPF / ECR guide).
- The export file's checksum is stored; exporting an approved return again must produce the same
  bytes.

## Uniqueness and idempotency

- One live return per type, establishment or legal entity, state, period, kind and sequence
  (`uniqueness_key`, cleared when cancelled).
- Generating again while the return is editable rebuilds it in place; once approved, generation is
  refused ("create a revision").
- Per-return unique keys on employee and on the hashed identifier (UAN, ESI IP number). A repeated
  identifier is kept but reported as a blocking duplicate.
- Queued jobs (`GenerateEpfReturn`, `GenerateEsiReturn`, `GenerateProfessionalTaxReturn`,
  `GenerateLwfReturn`, `GenerateTdsReturn`, `ReconcileStatutoryReturn`) are tenant-bound and unique
  per scope and period.

## Validation severities

- **Blocking** issues stop approval (missing registration, missing or invalid identifier,
  duplicates, missing wages, inconsistent basis, unfinalized payroll, rule mismatch, unverified rule
  when enforcement is on, deposit shortfall for TDS, state from the legacy company profile for PT).
- **Warnings** are shown and kept but do not stop approval (unverified export format, fractional
  non-contributory days, establishment resolved at return time, chronological gaps, unmodelled
  eligibility rules).

## Production gate

A return is production-ready only when all of these hold (Compliance control room, API `readiness`):

1. ADR-0001 legal entity (and establishment, except TDS) on the output.
2. Registration with the authority present for the period.
3. Every rule version used is VERIFIED and its checksum intact.
4. Rule versions captured in immutable snapshots.
5. Payroll reconciliation balanced.
6. Zero blocking issues.
7. Approved by someone other than the generator.
8. Tenant audit chain intact.
9. Export format verified against the authority's current layout.

"Ready" means the controls are satisfied. It does not certify legal correctness; that is what rule
and format verification record.
