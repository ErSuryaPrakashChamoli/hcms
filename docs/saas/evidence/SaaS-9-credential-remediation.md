# SaaS.9 closure: credential remediation evidence

Date: 9 October 2026. Branch `feature/oct_1_phase_1`.

This file contains no password, hash, token or session identifier. Every check was a yes/no test run in a process's
memory:
- **The leaked value** was read from git history (`3379c17:database/seeders/DatabaseSeeder.php`) into that memory.
- **The replacement** was a 64-character random value generated in that memory, verified, then discarded. Nobody knows
  it.

## 1. What was exposed

| Item | Finding |
|---|---|
| Value | A seeded password for `admin@fynnedge.com` (the "leaked value") |
| Introduced | `d75226e` (28 Sep 2026, "user"), in `database/seeders/DatabaseSeeder.php` **and** `docs/architecture/phase-1-foundation.md` |
| Removed | `8f54d4d` (6 Oct 2026, "fix: no credentials in seeders (SaaS.2)"), from both paths. It is not in the current tree |
| Published | Both commits are on GitHub: `origin/feature/sep_28_phase_1` and `origin/feature/oct_1_phase_1` |
| Repository visibility | **Public**: an unauthenticated request to the repository page and to its API returned HTTP 200 on 9 Oct 2026 |
| Real or fixture | The account's domain is Markedge's. Whether the same password protects anything else (e-mail, hosting, other systems) cannot be known from here: **treat it as compromised everywhere it was ever used** |
| Other secrets in history | A full scan of all 264 commits found none: no application keys, cloud or payment-provider keys, private keys, tokens, `.env` files or dumps. The pattern matches were cast declarations (`'hashed'`, `'encrypted'`), an anonymisation rule, audit labels, environment-variable names, fictional test keys, and a public monitoring key inside a government press-release page saved as compliance evidence |
| `.env` | Gitignored; never committed |

## 2. State before remediation (yes/no)

| Database | Account | Kind | MFA | Leaked value accepted | Fixture word accepted | Sessions / remember token / reset tokens |
|---|---|---|---|---|---|---|
| `hcm_ux_showcase` (served on 127.0.0.1:8090) | `admin@fynnedge.com` | tenant user (tenant 1) | no | **yes** | no | 0 / none / 0 |
| `hcm_ux_showcase` | `platform@markedge.local` | **platform operator** | no | no | **yes** | 0 / none / 0 |
| `hcm` (development) | `platform@markedge.local` | platform operator | no | no | no | 2 / none / 0 (not affected) |
| `hcm` | `admin@fynnedge.com` | absent | | | | |
| `hcm_p12_replay`, `hcm_p13_replay`, `hcm_p14_replay` (Phase 12–14 migration-replay copies) | `admin@fynnedge.com` | tenant user | no | **yes** | no | 0 / none / 0 |
| same three | `platform@markedge.local` | platform operator | no | no | **yes** | 0 / none / 0 |

**Reuse check, read-only.** `admin@fynnedge.com` exists in six databases of another local project (Fynn). The leaked
value opens none of them. No other project's database was changed.

## 3. Remediation (the application's own mechanisms)

For each affected account, in one transaction per account:
1. Set a new random password through the User model's `hashed` cast, the application's own hasher. The three
   pre-SaaS.2 replay copies used `Hash::make` without model events, because today's auditing needs tables they lack.
2. Revoke sessions with `SessionSecurity::revokeUser`: the session epoch advances, which invalidates every session on
   every device, and the remember-me token is cleared. On the replay copies, whose schema has no epoch, the remember
   token was cleared directly.
3. Delete any database session rows and password-reset tokens for the account.
4. Audit `PASSWORD_CHANGED` and `SESSIONS_REVOKED` (current schema only).

## 4. Verification after remediation (yes/no)

| Database | Account | Old credential rejected | New credential authenticates (`Auth::validate`) | Remember token cleared | Session epoch advanced | Sessions / reset tokens left | Reset flow can reach the account |
|---|---|---|---|---|---|---|---|
| `hcm_ux_showcase` | `admin@fynnedge.com` | yes | yes | yes | yes | 0 / 0 | yes |
| `hcm_ux_showcase` | `platform@markedge.local` | yes | yes | yes | yes | 0 / 0 | yes |
| `hcm_p12_replay`, `hcm_p13_replay`, `hcm_p14_replay` | both | yes | yes | yes | n/a (old schema) | 0 / 0 | n/a |

**Other accounts unchanged.** Hash digests, remember-token presence and session epochs were snapshotted before and after
for every other account:
- `hcm_ux_showcase`: 0 of 14 others changed;
- each replay copy: 0 changed.

**Audit chains.** After the rotation, `hcm_ux_showcase`'s audit chains verify: tenant 1 with 1,126 events, platform
with 5. The newest events are `PASSWORD_CHANGED` and `SESSIONS_REVOKED`.

**Final sweep.** No PeopleOS database accepts the leaked value. The only platform operators that accept the fixture word
are the test-factory accounts (`concurrency-*@example.test`) in the disposable `*_concurrency` MySQL test databases,
which the test suite creates by design.

## 5. Not changed, and why

| Item | State | Reason |
|---|---|---|
| Showcase demo personas (14 of the 16 showcase accounts) | Accept the documented fixture word | By design of `UxShowcaseSeeder`, which refuses any database not named `*_showcase`. No operator is among them. The server is bound to `127.0.0.1` only |
| `PEOPLEOS_SEED_PASSWORD` in `.env` | Neither the fixture word nor the leaked value; shorter than 16 characters | Local development seed. A reseed does not bring the problem back. Recommended: leave it unset (a random one is then generated per run) or make it long |
| Git history | Still contains the value (public) | Rewriting shared history needs your explicit authorisation (§6). Rotation is the real containment: the value no longer opens any PeopleOS account here |
| Production | Untouched | There is no production environment in this workspace |

## 6. Owner actions

1. **Treat the leaked value as compromised.** Change it on every system where it was ever used, outside this machine
   too.
2. **Set new passwords for the two showcase accounts, if you still need them.** Use the application's reset flow at
   `http://127.0.0.1:8090/admin/password-reset/request`. Mail is the `log` driver locally, so the reset link appears in
   `storage/logs/laravel.log`, and you complete it yourself. The password policy applies, and every session is ended
   again.
3. **Decide on history cleanup** (optional hygiene; the value is already public):
   1. Run `git filter-repo --replace-text <file listing the literal>` on a fresh mirror clone, covering both affected
      paths and every commit from `d75226e`.
   2. Force-push `feature/sep_28_phase_1` and `feature/oct_1_phase_1`. This rewrites 264 commits' identifiers.
   3. Every collaborator must re-clone.
   4. Ask GitHub Support to purge cached views of the old commits; forks and existing clones keep the old history.

   Do not rewrite before step 1 is done everywhere.
4. **Consider making the GitHub repository private** if it is not meant to be public.
5. **Optionally drop the three replay copies.** `hcm_p12_replay`, `hcm_p13_replay` and `hcm_p14_replay` are obsolete
   Phase 12–14 artefacts, now rotated. Ask before dropping them.
