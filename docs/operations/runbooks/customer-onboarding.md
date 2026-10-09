# Runbook: customer onboarding after self-service signup

Audience: Markedge customer success, and the customer's Account Owner. It describes what a newly registered
organisation sees and does, and what Markedge does alongside.

## 1. Registration (the applicant)

1. **Start.** Go to `/admin/signup` (only when Markedge has opened registration). Choose your country and a plan, then
   give your work e-mail address, your name and the organisation's name.
2. **Confirm.** Open the e-mail and press **Continue**. The link works once and expires after 24 hours; start again with
   the same address for a new one.
3. **Details.** Give the organisation's legal name, the name shown in PeopleOS, the time zone and how many employees you
   plan to manage. Accept the Terms of Service and Privacy Notice (the versions are shown), and confirm that you may
   register the organisation.
4. **Approval.** Markedge reviews registrations (the default). You receive an e-mail when it is decided.
5. **Activation.** When your organisation is ready you receive an e-mail to choose your password. That link works once;
   ask for a new one from your registration page if it expired.

One e-mail address signs in to one organisation. If you already use PeopleOS, sign in, or register with another
address.

## 2. First sign-in: Getting started

**Admin → Getting started** (also offered on Home until set-up is complete) shows five separate states:

| State | Meaning |
|---|---|
| Your account | Active once you chose your password |
| Organisation in PeopleOS | Provisioned and isolated; `Trial` or `Active` |
| Organisation set-up | How many set-up steps are done |
| Subscription | Your trial and its end date, or "Not started" (Markedge agrees it with you). Your plan's employee limit |
| Payroll and statutory calculations | Not active until someone has the Payroll Admin role and the statutory rules are verified by Markedge |

Steps, in a sensible order:
1. company;
2. organisation structure;
3. legal entities and establishments;
4. locations;
5. working days and holidays;
6. policies;
7. employees;
8. inviting colleagues;
9. giving roles.

A step you cannot do yourself names the role it needs.

## 3. What the Account Owner can and cannot do

| Can | Cannot (needs an explicitly given role) |
|---|---|
| Company, organisation structure, legal entities and establishments, locations, people set-up | Payroll, statutory returns, compensation (Payroll Admin, Tenant HR Admin) |
| Employees (non-sensitive fields), import | Sensitive personal data (bank, identifiers) |
| Invite users, give them roles | Working days, shifts and holidays (Attendance Admin) |
| Tenant settings and security (MFA policy, IP allow-list), resetting another user's MFA | Edit roles' permissions, import blueprints |
| The audit trail, the organisation's data export | Give anyone Tenant Super Admin (only Markedge can) |

Giving a specialist role (to a colleague or to yourself) is recorded in the audit trail with who gave it. We recommend
switching on MFA for administrators in **Settings → Security**.

## 4. Markedge alongside

- **Approve or reject** the application (`signup-operations.md`).
- **Offers without a trial:** start the agreed subscription on Platform → Subscriptions.
- **Statutory verification:** payroll stays closed for statutory calculations until Markedge has verified the rules
  (`peopleos:readiness`).
- **Trial ending:** the subscription lifecycle (SaaS.6) applies; there is no automatic conversion to paid.
