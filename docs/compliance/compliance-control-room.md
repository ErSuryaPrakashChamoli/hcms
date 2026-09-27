# Compliance Control Room Guide

For: compliance managers and reviewers.

Compliance → Control room is the statutory counterpart of the Payroll control room. It needs
`compliance.returns.view` or `compliance.view`; reporting lines never grant access.

## What it shows

- **Subheading:** whether verified-rule enforcement is on, and the reminder that an exported file
  is not a filing.
- **Cards:** rule versions verified (with counts in review and draft); returns with blocking
  issues; returns awaiting approval; returns exported but not filed; returns needing
  reconciliation; TDS certificates waiting to be issued.
- **Establishments:** for each active establishment, the statutes that apply (from its statutory
  profiles, or "Legacy company profile" when it has none) and the registrations still missing for
  applicable statutes.
- **Return register:** every EPF, ESI, PT, LWF and TDS output with form (and legacy form), period,
  establishment, status, blocking count, reconciliation status and the **production gate** icon.
  Hover the icon to see which controls fail. "Open" goes to the return's page.

## Daily routine

1. Resolve red cards first: blocking issues and reconciliation required.
2. Approve validated returns (not ones you generated).
3. After filing on the authority's portal, record the submission with the portal reference, then
   the acknowledgement. Only these steps move a return beyond EXPORTED.
4. Reconcile acknowledged returns against the amounts the authority accepted.

## The production gate

A return's gate turns green only when all nine controls pass: legal structure, registration,
VERIFIED and intact rule versions, captured rule versions (snapshots), balanced reconciliation,
zero blocking issues, approval with separation of duties, intact audit chain, verified export
format. With the rule library at 0 VERIFIED versions, **no return can pass the gate yet** — this is
expected and correct.

## Related screens

| Screen | Purpose |
|---|---|
| Organisation → Legal entities / Establishments / Establishment assignments | ADR-0001 structure and employee history |
| Compliance → Statutory registrations | Registration numbers (masked), verification against certificates |
| Compliance → Statutory profiles | Which statute applies to which establishment and when |
| Compliance → Compliance rules / Rule verification | Rule versions, evidence and verification history |
| Compliance → EPF / ESI / PT / LWF returns, TDS statements | Generate and run the lifecycle |
| Compliance → TDS deductor profiles / certificates / investment proofs | TDS set-up, Form No. 130, proofs |
