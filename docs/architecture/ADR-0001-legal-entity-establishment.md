# ADR-0001 — Company, Legal Entity and Establishment

- **Status:** Accepted (Phase 0.2, 27 September 2026); **implemented in Phase 5.1 (28 September 2026)** — see "Implementation (Phase 5)" at the end
- **Deciders:** PeopleOS architecture; to be ratified in Phase 0.3 (Architecture Contract)
- **Implementation phase:** Compliance / organisation hardening, not before Phase 0.3 sign-off

## Context: current implementation

PeopleOS models the employing organisation as:

```
Tenant
  └── Company (companies)               — name, legal_name, country_code, currency, effective dates
        ├── CompanyStatutoryProfile     — one per company: jurisdiction, pt_state, lwf_state,
        │                                  pf/esi/pt/lwf/tds applicability, pf_establishment_code,
        │                                  esi_code, pt_registration, tan, pan
        ├── BusinessUnit / Division / Department / Team / CostCentre / ProfitCentre (company_id)
        └── Location (company_id, city, state_code, country_code)
```

Payroll runs, bank files, payslips and payroll periods are **per company**. The statutory engine
resolves rates by `jurisdiction` (+ `state` for professional tax and labour welfare fund) from the
platform-owned `compliance_rules` table, and takes the applicable state from the company's
statutory profile, not from the employee's work location.

So today **Company = Legal Entity = Establishment**: one legal identity, one set of statutory
registrations, one PT/LWF state per company.

## Problem

In Indian labour and tax law (and in most jurisdictions) these are three different things:

| Concept | What it is | Examples of what hangs off it |
|---|---|---|
| Company (group / brand) | The organisational unit HR and reporting think in | org structure, policies, budgets, dashboards |
| Legal Entity | The registered employer that signs contracts and files returns | PAN, TAN, CIN, bank accounts, Form 16 issuer, payroll bank file, letters |
| Establishment | A registered place of work under a state/central act | PF establishment code, ESI sub-code, Shops & Establishments registration, PT and LWF state, state-wise registers and returns |

Why the collapse matters:

- **Payroll**: an employer with offices in Karnataka and Maharashtra owes professional tax and LWF
  under two different state schedules; one `pt_state` per company cannot express that.
- **Statutory registration**: PF/ESI codes are per establishment; multi-establishment employers
  file per code.
- **Compliance filings and registers**: ECR, ESI returns, PT returns, labour registers are per
  establishment; Form 16 / 24Q are per TAN (legal entity).
- **Locations**: a location is a physical site and should belong to an establishment; today it
  belongs to a company and carries `state_code`, which the engine ignores.
- **Reporting**: people cost and headcount need roll-ups by legal entity and by establishment,
  not only by company.
- **Banking**: salary disbursement accounts are per legal entity.
- **Policies**: leave and working-hour rules under state Shops & Establishments acts vary by
  establishment state.
- **Employee identity**: employment contracts are with a legal entity; transfers between legal
  entities are a different event from a department move.

## Decision

Keep the current model in Phase 0.2. Record the target architecture below as the canonical
direction, and implement it as an **additive** change in the compliance/organisation hardening
phase after Phase 0.3 has ratified it. Phase 0.2 only hardens what exists: the statutory rules
now carry a verification status, and payroll cannot be finalized on illustrative rules in
production.

Rationale for deferring: no security defect depends on it; the change touches payroll, compliance,
organisation, reporting and letters at once; and Phase 0.3 must first decide how the rest of the
foundation (ABAC dimensions, reporting roll-ups, integration mappings) refers to these entities.

## Target architecture

```
Tenant
  └── Company                          (brand / management view; unchanged)
        ├── LegalEntity  1..n           legal_name, registration ids (PAN, TAN, CIN, GST optional),
        │     │                         country, currency, bank accounts, letter signatory
        │     └── Establishment 1..n    state, registrations (PF code, ESI code, PT, LWF, S&E),
        │           └── Location 1..n   physical sites (existing locations table)
        └── Organisation structure      BU / division / department / team (unchanged)
```

- `EmployeePosition` gains `legal_entity_id` and `establishment_id` (nullable, effective-dated with
  the position), derived by default from the location.
- `CompanyStatutoryProfile` is split: entity-level identifiers (PAN, TAN) move to `legal_entities`;
  establishment-level applicability and registrations move to `establishments`. The existing row
  becomes the profile of the default legal entity + default establishment.
- `PayrollRun`/`PayrollPeriod` become per legal entity (a company with one legal entity behaves
  exactly as today). Bank files are per legal entity bank account.
- `StatutoryEngine` takes PT/LWF state from the employee's establishment, falling back to the
  legal entity's default establishment.
- `compliance_rules` stays jurisdiction + state (platform-owned); `Establishment.state` selects the
  state rules. No `legal_entity_id` on rules.
- Access scopes (Phase 0.2 `user_access_scopes`) gain `legal_entity` and `establishment` dimensions.

## Migration strategy

1. **Additive tables**: `legal_entities`, `establishments`, `legal_entity_bank_accounts`; nullable
   FKs on `employee_positions`, `locations`, `payroll_runs`, `payroll_periods`,
   `company_statutory_profiles`.
2. **Backfill**: one legal entity per company (from `legal_name`, `country_code`, `currency`) and
   one establishment per legal entity from the statutory profile (`pt_state` as the state). Every
   location and every current position points at them. Historical positions are backfilled from
   their location, never rewritten otherwise; the backfill runs inside an audit operation so it is
   traceable.
3. **Effective dating**: new columns follow the position's own effective dates; changing an
   employee's establishment is a new position row (transfer), never an update.
4. **Backward compatibility**: `Company` keeps `legal_name`, `country_code`, `currency` as denormalised
   defaults; services accept a company and resolve its default legal entity until every caller is
   migrated; the API adds `legal_entity` and `establishment` fields without removing any.
5. **Reporting**: datasets expose the new dimensions; existing reports keep working because the
   company dimension is unchanged.
6. **Letters and documents**: templates gain `legal_entity.*` variables; existing `company.*`
   variables remain.
7. **Rollback**: the additive columns and tables can be dropped without touching existing data.

## Consequences

- Until implemented, single-state, single-entity employers are modelled correctly; multi-state or
  multi-entity employers must be onboarded as separate companies (one per establishment), which
  fragments reporting but is safe for payroll.
- The compliance phase must not build filings (ECR, PT returns, Form 16) before this ADR is
  implemented, because the filing unit does not exist yet.
- Recorded in the Phase 0.1 report as TD-04/TD-20 and in the Phase 0.2 report as a deferred item.


## Implementation (Phase 5)

Implemented additively in migration `2026_10_04_100001_create_legal_entities_and_establishments`:

| Element | As implemented |
|---|---|
| `legal_entities` | `company_id`, `code` (unique per tenant), `legal_name`, `trade_name`, `legal_form`, `country`, `incorporation_identifier`, `is_primary`, `status`, effective dates |
| `establishments` | `legal_entity_id`, denormalised `company_id` (always copied from the legal entity, never from input), `code` (unique per legal entity), `name`, `address`, `country`, `state`, `district`, `postal_code`, `establishment_type`, `is_primary`, `status`, effective dates |
| `locations.establishment_id` | nullable; the transition attaches every location to its company's principal establishment |
| Transition | `Company::created` calls `LegalEntities::ensureFor()`; existing data via `peopleos:legal-entities:backfill` (idempotent, one audit operation per tenant; establishment state from the legacy profile `pt_state`, else the first located state) |
| Access | Both models use the `company` dimension through `company_id`; record-level checks (`AccessScopes::allows`) now apply the company constraint to any model with an organisation dimension and a `company_id` |
| Deletion | Never: policies refuse delete; records are closed with `effective_to` / `inactive` |

Deviations from the original plan, decided in the Phase 5 discovery:

1. **Employee ↔ establishment** is a separate effective-dated table (`employee_establishment_assignments`,
   Phase 5.2), not columns on `employee_positions`.
2. **Registrations** move to `statutory_registrations` (Phase 5.2) keyed by legal entity and, where
   applicable, establishment; the legacy columns on `company_statutory_profiles` are kept, backfilled and
   no longer read by new code.
3. **Payroll runs and periods stay per company** (Phase 4 is frozen). Each payroll entry records the
   legal entity and establishment it was calculated for; statutory returns are built per establishment
   (EPF, ESI, PT, LWF) or per legal entity (TDS) from those entries.
4. **`legal_entity_bank_accounts`** and the `legal_entity` / `establishment` access-scope dimensions are
   not built in Phase 5 (bank payments are a Phase 5 non-goal); company scoping covers them.
