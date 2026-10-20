# SaaS.9 closure: retention decision register (D-8)

Status on 9 October 2026, branch `feature/oct_1_phase_1`.

**Current configuration:**
- No retention policy, contractual term or legal hold exists in the development database (`hcm`) or the showcase
  (`hcm_ux_showcase`).
- Every customer category is therefore `RETENTION_POLICY_NOT_CONFIGURED`, so **nothing can be deleted**, even before the
  deletion switch (off in every environment).
- This is the safe state. It is a business and legal decision still open, not a technical failure.

**The three inputs, kept distinct in the data model and on the pages:**

| Input | Where | Rule |
|---|---|---|
| A. Verified legal minimum | `retention_policies.legal_minimum_*` (Retention policies page) | Entered only with basis and reference. **Verified** only with authoritative source, reviewer and verification date; otherwise `LEGAL_MINIMUM_PENDING_VERIFICATION`, which keeps the category from deletion. None entered: `LEGAL_MINIMUM_NOT_CONFIGURED` (never zero) |
| B. Markedge company policy | `retention_policies.policy_months` + `start_event` (Retention policies page) | Never below a configured legal minimum. Counted from the tenant's closure or the end of the financial year in which it closed. A category without an approved policy is never deleted |
| C. Customer contractual requirement | `tenant_retention_terms` (Offboarding page, per tenant) | A minimum extends retention. A deletion deadline that retention runs past is a recorded conflict, never an automatic deletion |

**Lifecycle for each input:**
1. An operator proposes it (draft).
2. For A, verification means recording the source, reviewer and date.
3. A different operator approves it.
4. It becomes active from its effective date.

Decided versions are immutable; a new value is a new version. Retention for a closed tenant uses the longer of the version
in force at closure and the version in force today. An approved deletion plan is never rewritten: what a later policy
keeps is skipped, not deleted.

## 1. Verified legal facts (authoritative sources)

Read in the official text on 9 October 2026, unless marked otherwise. These are **facts about the law, not
recommendations**, and none is configured in the system. Applying them needs your decision and professional
confirmation.

| Ref | Instrument and provision | Retention text (quoted) | Period / start | Obligated party | Source | Verification |
|---|---|---|---|---|---|---|
| L1 | CGST Act 2017, s.36 | "shall retain them until the expiry of seventy-two months from the due date of furnishing of annual return for the year pertaining to such accounts and records" (+ proviso: one year after final disposal of an appeal, proceeding or investigation) | 72 months from the annual return's due date | Every registered person (Markedge, for its own accounts) | taxinformation.cbic.gov.in (CGST Act, chapter 8, s.36) | Verified (official text read) |
| L2 | Income-tax Rules 2026 (G.S.R. 198(E), under the Income-tax Act 2025), r.46(9) | "shall be kept and maintained for a period of seven tax years from the end of the relevant tax year" | 7 tax years from the end of the tax year | Persons required to keep books (Markedge; employers for their own books) | incometax.gov.in: notified Income-tax Rules 2026 | Verified (official text read) |
| L2a | Income-tax Rules 2026, r.46(8) | books in electronic mode "shall remain accessible in India at all times, and the backup … shall be kept on a daily basis in servers physically located in India" | — | Same | Same | Verified. **Hosting implication for the lawyers** if PeopleOS holds books of account |
| L3 | Code on Wages (Central) Rules 2026 (G.S.R. 343(E), 8 May 2026), r.51(4) | "The registers maintained under these rules shall be preserved for a period of five years after the date of last entry made therein." | 5 years after the last entry | The employer, where the Central Government is the appropriate government. State rules govern most private employers; **not researched** | labour.gov.in: G.S.R. 343(E) | Verified (official text read) |
| L4 | Social Security (Central) Rules 2026 (G.S.R. 344(E), 8 May 2026), r.53(1)(e) | "all the registers and other records shall be preserved in original for a period of five calendar years from the date of last entry made therein" | 5 calendar years from the last entry | The employer | labour.gov.in: G.S.R. 344(E) | Verified (official text read) |
| L5 | Digital Personal Data Protection Act 2023, s.8(7) | "A Data Fiduciary shall, unless retention is necessary for compliance with any law for the time being in force,— (a) erase personal data … (b) cause its Data Processor to erase any personal data …" | Erase when the purpose is no longer served or on withdrawal of consent | The customer (data fiduciary), which must make Markedge (processor) erase | meity.gov.in: DPDP Act 2023 | Text verified. **When s.8 starts applying was not verified** |
| L6 | Companies Act 2013, s.128(5) | books "relating to a period of not less than eight financial years immediately preceding a financial year" kept in good order | 8 financial years | Every company (Markedge) | mca.gov.in | Reported verified by research; **not re-verified here** (site refused access) |
| L7 | GDPR Art. 28(3)(g) (EU customers) | the processor "deletes or returns all the personal data to the controller after the end of the provision of services … and deletes existing copies unless Union or Member State law requires storage" | At the end of the services | Markedge as processor, by contract | EU Official Journal L 119 | Reported verified by research; **not re-verified here** (EUR-Lex refused access) |

**Who carries the obligation.**
- **The employer (Markedge's customer)** keeps:
  - wage and attendance registers (L3);
  - social security records (L4);
  - its own books (L2).

  These instruments put no retention duty on a software vendor. The customer meets them with its own systems and the
  export it receives.
- **Markedge** keeps its own invoices, payments and books (L1, L2, L6). These are never deleted with a tenant.
- **Data-protection law points the other way for customer data.** At the end of the service Markedge, as processor,
  erases or returns it unless a law requires storage (L5, L7). So a long company policy (B) for customer HR data needs a
  documented legal basis.

**Not verified, or not researched:**
- State labour rules (most private employers);
- the EPF Scheme's record period;
- whether the ESI Regulations are still in force;
- when DPDP s.8 starts;
- UK GDPR text (blocked);
- every non-India jurisdiction (US, UK, UAE).

All of these stay `LEGAL_MINIMUM_NOT_CONFIGURED`.

## 2. Register by category (36 categories, plus copies outside the database)

The same entries apply in every jurisdiction today: `IN` and `*` are both unconfigured.
- **Verified legal minimum (A):** not configured anywhere.
- **Company policy (B):** awaiting your decision.
- **Contract (C):** recorded per tenant when a customer's contract has one.
- **Reviewer and verification date:** none yet.

| Category | Owner | Obligated party | Legal reference (A) | Verified legal minimum (A) | Company policy (B) | Customer contract (C) | Start event / calculation | Hold | Deletion / anonymisation | Approval | Status (IN and *) |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `identity` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Deleted; anonymised instead when kept records still reference sign-in accounts | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `people` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `employment` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `organisation` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `attendance` | tenant | The employer (Markedge's customer); Markedge as processor via the contract | Employer: attendance register-cum-muster roll 5 years after last entry (Code on Wages (Central) Rules 2026 r.51(4), central sphere) [L3] | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `leave` | tenant | The employer (Markedge's customer); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `payroll` | tenant | The employer (Markedge's customer); Markedge as processor via the contract | Employer: wage registers 5 years after last entry (Code on Wages (Central) Rules 2026 r.51(4), central sphere; State rules otherwise, not researched); books 7 tax years (Income-tax Rules 2026 r.46(9)) [L2, L3] | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `statutory` | tenant | The employer (Markedge's customer); Markedge as processor via the contract | Employer: social security registers and records 5 calendar years from last entry (Social Security (Central) Rules 2026 r.53(1)(e)); EPF period not found [L4] | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `tax` | tenant | The employer (Markedge's customer); Markedge as processor via the contract | Employer: books of account 7 tax years (Income-tax Rules 2026 r.46(9)); no TDS-specific period found [L2] | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `banking` | tenant | The employer (Markedge's customer); Markedge as processor via the contract | None specific identified (part of the employer's payroll records)  | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `documents` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `performance` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `goals` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `learning` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `career` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `talent` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `compensation` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `assets` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `service_desk` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `grievances` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `engagement` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `communication` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `notifications` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `workflows` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `configuration` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `analytics` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `integrations` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `api` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `audit` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Deleted only as a whole chain, newest first, by its own step | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `billing` | markedge | Markedge (its own records) | Markedge: as invoices, for the records that support them [L1, L2] | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Never deleted with the tenant (retained) | Two operators | Retained: outside the deletion engine |
| `invoices` | markedge | Markedge (its own records) | Markedge: GST accounts 72 months from the due date of the annual return (CGST Act s.36); books 7 tax years (Income-tax Rules 2026 r.46(9)); Companies Act s.128(5) 8 financial years (reported, not re-verified) [L1, L2, L6] | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Never deleted with the tenant (retained) | Two operators | Retained: outside the deletion engine |
| `payments` | markedge | Markedge (its own records) | Markedge: as invoices (CGST Act s.36; Income-tax Rules 2026 r.46(9); Companies Act s.128(5) reported) [L1, L2, L6] | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Never deleted with the tenant (retained) | Two operators | Retained: outside the deletion engine |
| `subscriptions` | markedge | Markedge (its own records) | Markedge: commercial records; no specific period identified beyond the books above | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Never deleted with the tenant (retained) | Two operators | Retained: outside the deletion engine |
| `platform_operational` | platform | Markedge (platform) | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |
| `offboarding_records` | platform | Markedge (platform) | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Retained (the exit's evidence) | Two operators | Retained: outside the deletion engine |
| `warehouse_exports` | tenant | The customer (data fiduciary / controller); Markedge as processor via the contract | None identified | LEGAL_MINIMUM_NOT_CONFIGURED | Owner to decide | Per tenant, if any | Closure (or FY end); start + months; longer of closure-time and current versions | Overrides | Hard delete once eligible | Two operators | RETENTION_POLICY_NOT_CONFIGURED: never deleted |

**Copies outside the tenant tables:**

| Copy | Handling today | Status in a deletion |
|---|---|---|
| Export packages | Deleted after 7 days (`PEOPLEOS_EXPORT_EXPIRY_DAYS`), and removed by the deletion itself if still stored. The record, checksums and manifest are kept | Listed in the certificate (`export_packages_removed`) |
| Warehouse snapshots | A deletable category (`warehouse_exports`); the purge keeps a configurable window (`warehouse.retention_days`, default 30) and respects holds | Deleted with the tenant when eligible |
| Database backups | Outside PeopleOS (35 daily and 12 monthly targets in the DR runbook) | External copy: `PENDING` until an operator records `REQUESTED` / `RETENTION_REQUIRED` (with a date) / `FAILED` / `CONFIRMED` |
| File storage backups and replicas | Outside PeopleOS | External copy, as above |
| Integration systems, webhook receivers, payment provider, delivered e-mail | Third parties | External copies; never claimed deleted until confirmed |

## 3. Decisions you must provide or approve

Facts are in §1. Everything below is a decision, and none is made for you.

| # | Decision | Category / jurisdiction | Verified legal minimum available | Proposed company policy | Current state | If unresolved | Deletion |
|---|---|---|---|---|---|---|---|
| D8-1 | Company policy (B) for customer HR data after closure | All customer categories / IN (then each other market) | None on Markedge as processor. The employer's own periods (L3, L4, L2) bind the customer; L5 and L7 point to deletion at the end of the service unless law requires storage | None proposed: owner and counsel decide. Consider whether any retention beyond delivery of the export is justified | `RETENTION_POLICY_NOT_CONFIGURED` | Customer data is kept indefinitely after closure (an over-retention risk under L5 and L7) | Blocked |
| D8-2 | Whether Markedge keeps anything **on the customer's behalf** for its statutory periods | payroll, attendance, statutory, tax / IN | L3 5 years, L4 5 calendar years, L2 7 tax years (employer's duty) | Contractual (C), per customer, if offered | No contract terms | No change | Not affected |
| D8-3 | Markedge's own records (B for its records; SaaS.7 B-16) | invoices, payments, billing, subscriptions / IN | L1 72 months from the annual return due date; L2 7 tax years; L6 8 financial years (to confirm) | Owner and accountant | Always retained by the deletion engine | No deletion schedule for Markedge's own records | Never deleted with a tenant |
| D8-4 | Audit trail after exit | audit / all | None identified | Owner | Not configured | Chain retained | Blocked |
| D8-5 | Backup retention and the evidence that a deleted tenant has aged out of backups | external copies | None identified | Confirm the DR targets (35 daily, 12 monthly) and how ageing out is proven | DR targets documented; production backups not verified | A deletion can never be shown complete outside PeopleOS | Certificates stay "not complete including external copies" |
| D8-6 | Export package expiry | export packages | — | Confirm 7 days | 7 days default | — | — |
| D8-7 | India-hosting of books (L2a) | hosting | L2a | Counsel | Unknown | Possible non-compliance if PeopleOS holds customers' books outside India | — |
| D8-8 | State labour rules and non-India markets | all | Not researched | Commission advice | Unconfigured | Unconfigured stays safe | Blocked |

**How to configure, once decided:** on the Retention policies page:
1. One operator proposes each category with A (with source, reviewer and date if it is a verified legal minimum), B and
   the start event.
2. A second operator approves.

Nothing applies before the effective date and the approval.
