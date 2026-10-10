# SaaS.13 — Owner action pack

Date: 10 October 2026. **For:** the owner of Markedge PeopleOS.

The decision IDs are those of `SaaS-12-Owner-Decision-Register.md`; nothing here renumbers them. Each section says:
- exactly what to do;
- what engineering does after your authorisation (and only then);
- what evidence closes the gate.

No step here has been done for you, except where marked **done in SaaS.13**.

## 1. Leaked credential (O-1) — the most urgent action

**Facts, re-verified on 10 October 2026, as yes/no checks with the value never displayed:**
- The value is still readable in the public branch `feature/sep_28_phase_1` (tip `9bf682f`), in
  `database/seeders/DatabaseSeeder.php` and `docs/architecture/phase-1-foundation.md`, and in the history of that branch
  and of `feature/oct_1_phase_1`.
- On this machine it is accepted by **no** PeopleOS account (19 databases), **no** MySQL account and **no** `.env` value.
- Engineering cannot check external systems. There is no authorised access to GitHub, e-mail, hosting or other
  applications. Their data was not read.

**Treat every system where you ever used this password as compromised until you have done the following.** For each
system:
1. Change the password to a new, unique one (a password manager).
2. End all other sessions ("sign out of all devices").
3. Review and revoke tokens, app passwords, OAuth grants and SSH keys you do not recognise.
4. Turn on two-factor authentication where available.
5. Check the account's security log for sign-ins you do not recognise since **28 September 2026** (the day the value was
   committed).

Systems to consider:
- the GitHub account `ErSuryaPrakashChamoli` (personal access tokens, SSH keys, OAuth apps, sessions);
- the e-mail accounts used with PeopleOS and GitHub;
- hosting and cloud accounts;
- domain registrar and DNS;
- databases you run elsewhere;
- the other applications on this workstation and elsewhere;
- any device or Wi-Fi password.

**Evidence that closes it:** a written confirmation per system: system, date, password changed, sessions ended,
tokens reviewed, 2FA state, anything unusual found. Engineering records it in the master register. It cannot verify
the external systems itself.

## 2. Repository exposure (O-2, O-3)

| Option | What it achieves | Command engineering runs **after** you authorise it | Impact |
|---|---|---|---|
| Make the repository private | Stops new viewers | You change it in GitHub → Settings → General → Danger Zone (engineering has no GitHub access) | Existing clones and forks (0 forks today) keep the history |
| A. Delete the remote branch `feature/sep_28_phase_1` | The value leaves current public files | `git push origin --delete feature/sep_28_phase_1` | That branch's own commits remain reachable by SHA until GitHub purges them; its work is in `feature/oct_1_phase_1` already |
| B. Commit a removal on that branch | Same, keeping the branch | A commit replacing the value in the two files, then `git push origin feature/sep_28_phase_1` | History still holds it |
| C. Rewrite history | The value leaves history | Plan in `SaaS-11-Security-Findings.md` §2.3: `git filter-repo --replace-text` on a mirror, force-push of every affected branch, PR #1 closed and recreated, GitHub Support asked to purge cached views and unreferenced commits | Every clone must re-clone; commit SHAs in reports change (a `commit-map` is kept); your own commits are rewritten; this branch's 75 unpushed commits are rewritten too |

**Collaborator and CI/CD impact:**
- Collaborators and deploy keys cannot be observed without GitHub access. Please review Settings → Collaborators and
  Deploy keys.
- CI (`.github/workflows/ci.yml`) uses **no** secrets. A history rewrite invalidates PR #1's head; CI re-runs on the new
  head.
- No deployment depends on the repository yet.

**Order:** O-1 (rotation) first. Then a visibility decision. Then A, B or C. **Verification afterwards:** engineering
re-runs the yes/no scan on every public ref (`git ls-remote`, then a scan of each tip and, after C, of all history), and
re-checks visibility through the public API.

**To authorise**, write for example: "Authorised: option A on <date>". Engineering performs only that operation and
reports the result.

## 3. Platform operator MFA (O-14)

**Enforcement is in place** (re-verified):
- checked on every request;
- remember-me and SSO sessions challenged;
- protected downloads included;
- recovery codes single-use and audited;
- resets audited and ending sessions;
- readiness FAILs in production while an operator is unenrolled.

**Enrolment is not:** 0 of 1 operators in the development and showcase databases.

**Production (when it exists):**
1. Create each operator account and send the invitation.
2. The operator signs in **immediately**. PeopleOS sends them to authenticator set-up before anything else.
3. They scan the code with an authenticator app, enter a code, and store the recovery codes in a password manager
   (never e-mail, chat or documents).
4. Run `php artisan peopleos:readiness`. "Every platform operator has MFA enrolled" must show PASS.

**Showcase (local):** the operator's password was rotated to an unknown random value in SaaS.9. To use the account:
1. Open `http://127.0.0.1:8090/admin/password-reset/request`.
2. Take the link from `storage/logs/laravel.log` (log mailer).
3. Set a new password, then enrol as in steps 2 to 4 above.

Engineering never sees or records the secret or the codes.

## 4. Recovery objectives (O-6) — options for your decision

No target is chosen here. The DR document's figures (RTO 4 h; RPO 15 min with binary logs, or 24 h with daily dumps)
were engineering planning assumptions and **are not approved**.

| Decision | Options | Trade-off |
|---|---|---|
| **RPO** (data you can afford to lose) | (a) about 24 h: daily full backups only; (b) minutes: daily backups plus continuous binary-log or point-in-time recovery; (c) near zero: a synchronous replica | Each step down costs more storage and operational complexity; (b) is the usual managed-database feature |
| **RTO** (time to be back) | (a) a business day; (b) a few hours: a scripted restore with tested runbooks; (c) under an hour: a standby environment | Shorter needs pre-provisioned infrastructure and rehearsals |
| Backup frequency | Daily full; plus binary logs (b); plus object-storage versioning | Must match the RPO |
| Backup retention | Linked to **D8-5** (how a deleted tenant ages out of backups) and to counsel's view | Longer retention lengthens the time deleted data survives in backups |
| Encryption | At rest by the provider; plus client-side for exported dumps | Key custody: the secret store |
| Access | Named operators only, MFA, logged | — |
| Restore testing | For example quarterly, and after any storage, encryption or topology change | An untested backup is not a backup |
| Escalation and ownership | Who declares an incident, who restores, who approves go-live after a restore | Needs names (O-10) |

**Evidence that closes it:** your written choice per row, with a date. The DR exercise then measures against it.

## 5. Gratuity (O-12 and O-5) — assessment and what a decision triggers

**Current capability:**
- PeopleOS **does not calculate gratuity**.
- A gratuity rule is stored (Payment of Gratuity Act 1972, as amended 2018; parameters `rate`, `ceiling` and
  `minimum_years`, all **unverified**), but no code uses it.
- The exit final settlement computes:
  - final-period salary;
  - leave encashment;
  - notice recovery;
  - asset recovery.

  An operator could only add gratuity as a **manual** line.
- A payroll component classification labelled "Gratuity provision" exists for tenants' own components. It is not a
  statutory calculation.

**It must not be described as implemented or compliant.**

**Decision needed from you** (with the statutory specialist): is gratuity calculation required for the launch scope?
- **If not required for launch:** record that. Customers must be told that gratuity is outside PeopleOS's
  calculations; any manual settlement line is the customer's own figure.
- **If required:** engineering follows this sequence, and nothing is built before step 4:
  1. Discovery: done above; no partial implementation exists.
  2. The specialist names the applicable jurisdictions and legal requirements.
  3. Engineering drafts a requirements specification from the specialist's answers. It needs:
     - eligibility conditions, and the exceptions that waive them;
     - the definition of the wage base;
     - how service is counted (completed years, part years, continuous service, rejoining);
     - the formula and its divisor;
     - any statutory ceiling;
     - the effective dates of each parameter;
     - the treatment by exit type;
     - forfeiture;
     - tax treatment (exemption limits are a separate tax rule);
     - payment timing;
     - interaction with employer gratuity schemes.

     Every one of these values comes from the specialist, not from engineering.
  4. The specialist approves the specification.
  5. Engineering implements it as an effective-dated, verified-rules-gated statutory rule consumed by the final
     settlement, with calculation, boundary, effective-date and regression tests.
  6. The specialist verifies the implemented behaviour. The rule is verified in the product by a second operator.
  7. Until then, the calculation stays unavailable in production through the existing verified-rules gate.

## 6. Infrastructure (O-7, O-8)

Nothing has been provisioned, and nothing billable will be without your authorisation.

The repository contains a skill for **Laravel Cloud** (`.claude/skills/deploying-to-cloud`). That is an option it
suggests, not a decision.

Requirements and environments: `SaaS-12-Production-Infrastructure-Requirements.md`.

**What engineering needs from you:**
- the provider;
- the account access to use, delegated with least privilege;
- the region (D10-11, D8-7);
- the mail provider;
- the secret store;
- the monitoring and error-reporting provider (O-11);
- the on-call names (O-10);
- the budget.

Costs must come from the provider's current pricing at decision time. None is estimated here.

## 7. Done in SaaS.13 (no further action needed from you)

- The development `.env` was world-readable (664). It is now 600: only `administrator` runs PeopleOS on this machine,
  and the group has no other members. The application was verified afterwards (8090 serves the login page). The file
  was never committed.
- **Exposure assessment:** while it was world-readable, any other account on this machine (`root`, the `postgres`
  service account, system services) could read it. SSH listens on all interfaces. No login record of another person was
  available to check. If you consider that anyone else had access to this machine, rotate the development database
  password and `APP_KEY` (development only: production must never reuse them).
