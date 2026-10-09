# SaaS.11 — Signup security regression report

Date: 10 October 2026. Code at `62f2603`. This report re-checks the SaaS.10 guarantees against **today's** code, after
this phase's changes. It decides none of D10-1 to D10-11 (see `SaaS-11-Release-Gate-Register.md` §3).

## 1. Result

| Guarantee (§9) | Evidence today | Result |
|---|---|---|
| **Public registration disabled** | Unset in `.env` and in the 8090 server's environment; default `false`. With it off, the browser run shows only a notice, and every signup action is refused (mutant S01). Production refuses it on without a recorded authorisation (mutant R4) | **Holds** |
| **The Account Owner never gets operator privileges** | 35 explicit keys, no wildcard, all in the catalogue. The catalogue has no platform permission; operator power is the `is_platform_admin` flag, which signup never sets (architecture test). In the browser run the owner receives 403 on `/admin/platform-signups` and `/admin/tenants`. Mutant S12 (owner given Tenant Super Admin) killed | **Holds** |
| **The three escalation fixes stay covered** | E1 user form (S19, S20, S31), E2 blueprint import (S21), E3 SSO default (S22), and the role editor ceiling (S23): all killed against today's code. SaaS.11 adds the audit of permission changes (R1–R3) | **Holds** |
| **Provisioning idempotent and concurrency-safe** | MySQL races 1–7 (`SignupConcurrencyTest`) passed in today's full MySQL run at `62f2603` (135/135): one open application per address, one link use, one claim and one tenant per application, one decision among competing ones, unique slugs, lease recovery that waits for a committing run, and four simultaneous provisionings with InnoDB's deadlock counter unchanged. Mutants for the fencing token (S30) and the address re-check (S11) killed | **Holds** |
| **Unapproved commercial configuration fails safe** | No approved offer, terms or notice: signup refuses with a stated reason. A plan without entitlements is not offered (S27). The employee limit (S09) and the terms version (S10) are enforced | **Holds** |
| **No paid entitlement without the lifecycle** | `startSignupTrial` is the only path; no paid subscription is started; the plan is re-checked at approval and provisioning (S14 trial, S11 re-check). Live payments are refused in production (R6, R7) | **Holds** |
| **Registration, approval, retry, hold, rejection and cancellation authorised and audited** | Every `SignupReview` action refuses a non-operator (S17) and a null actor (S18). Transitions follow the state machine (S28); a provisioned application cannot be cancelled (S29). Each transition writes a signup audit event (`SignupProvisioningTest`, `SignupApplicationsTest`) | **Holds** |

## 2. Mutation testing (today's code)

Mutants were run on private copies of the tree, one at a time, each against the tests that should catch it. The
copy was compared file by file with the working tree afterwards: identical.

| Set | Result |
|---|---|
| SaaS.10 S01–S32 (switch, links, enumeration, limits, honeypot, review policy, domain flag, plan limit, terms, provisioning re-check, owner role, slug, trial, failure summary, contact minimisation, operator guard, null actor, the escalation ceilings, Getting started, Signups page, entitlements, state machine, cancellation, fencing token, session regeneration) | **32/32 killed** |
| SaaS.11 R1–R12 (permission-change audit ×3, release gates ×6, X-Robots-Tag, operator MFA ×2) | **12/12 killed** |

**Total: 44/44.** R1 and R2 are killed by an error rather than an assertion failure. The error is the test's
`firstOrFail` finding no permission-change event: a real detection, reproduced by hand, not a broken mutant.

## 3. Browser and accessibility

The SaaS.10 browser flow was re-run against a disposable fixture database (`hcm_saas10_ui_showcase`). Registration was
switched on **only** for that process, on port 8096, with a local SMTP sink. It was stopped afterwards. The 8090
showcase stayed off.

**Result:** 34/34 checks; axe-core (WCAG 2.0 and 2.1, levels A and AA) on 12 states with **0 violations**; 0 console
errors.

The flow covered:
- the notice when registration is off;
- the start page on desktop and on a phone, with no horizontal scroll;
- "check your inbox", with the link read from the sink and never logged;
- opening a link offers only Continue (no GET changes state);
- the details step, showing the terms and privacy versions;
- a used link reading as invalid;
- review by an operator, with no IP address shown;
- approval and provisioning;
- the activation invitation and the owner's password;
- the tick marking the application active;
- the set-up banner and Getting started;
- the owner refused on platform pages (403);
- no application without the confirmed session.

**Headers, live on both servers:**
- `X-Robots-Tag: noindex, nofollow`;
- `robots.txt` disallowing everything;
- `X-Frame-Options: SAMEORIGIN`;
- HSTS correctly absent over plain HTTP.

## 4. What this does not show

- That registration is safe to open. That needs the independent review (G2) and the owner's decisions (G10, G11).
- Behaviour under a real mail provider, real DNS (SPF, DKIM) or distributed bot traffic.
- Anything about a production environment.
