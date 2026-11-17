# Brief for the content researchers of the Organisation Administrator training

You research part of PeopleOS HCM and write the **content** of a training course for one audience: the
**Organisation Administrator of a client company** (in the application: the tenant's first administrator, role
*Tenant Super Admin*). They run their own organisation in PeopleOS. They are not Markedge staff.

Another process turns your content into slides with the real screenshots. You write facts and explanations only.

## Scope rules (strict)

- Only what the client's administrator can see and use inside their own organisation. The application already hides
  Markedge's platform screens (tenants, plans, billing, subscriptions, signups, platform readiness) from this role:
  never describe them, never send the reader to them.
- Never mention internal project matters: release gates, "NO-GO", owner decisions, defect codes such as F25, test
  databases, seeders, commits, "verified in the running application". Write for a customer.
- Where something is provided or maintained by the service provider (for example statutory rule versions that the
  provider publishes and verifies), say so plainly: "maintained by PeopleOS; you can view it" or "ask PeopleOS support".
- Where a feature is incomplete or behaves in a limiting way **today**, state the limitation and the workaround in
  plain words (no defect codes). Do not hide it, and do not invent a fix.
- Never invent screens, fields, buttons, menus, formulas, statutory rates or reports. Every statement must come from
  the source code, the captured interface data, or the training database. If you cannot confirm something, leave it out
  or put it in `gaps`.

## Your inputs (all paths from the repository root)

- `docs/training/organisation-admin/tools/.work/assignment.json`: your modules and their screens (`id`, `label`,
  `url`, `kind`: index / page / create / view / edit, `class` = the Filament resource or page, `path` = the menu path).
- `docs/training/organisation-admin/tools/.work/crawl.json`: the interface as rendered for the administrator, keyed by
  screen `id` (`ui.h1`, `ui.subheading`, `ui.tabs`, `ui.headerActions`, `ui.toolbar`, `ui.columns`, `ui.filters`,
  `ui.rowActions`, `ui.fields` with `required`, `ui.sections`, `ui.steps`, `ui.headings`, `ui.buttons`, `ui.empty`).
  A crawler is still filling this file; it finishes about an hour after you start. **Do your source research first and
  read crawl.json last.** If a screen is still missing, wait a few minutes and read it again (up to about 30 minutes);
  if still missing, describe that screen from source and list its id in `gaps`.
- The screenshots, if you want to look: `docs/training/organisation-admin/screenshots/<id>.png`.
- Source code: the `class` of each screen (e.g. `App\Filament\Resources\PayrollRuns\PayrollRunResource` lives in
  `app/Filament/Resources/PayrollRuns/`), its Pages/Schemas/Tables/RelationManagers, and the domain services it calls
  (`app/Domain/<Module>/Services`, `Models`, `Policies`, `Enums`). Permission keys and role templates:
  `config/peopleos.php`. Feature notes: `docs/architecture/`, `docs/streamline/`, `docs/hrone/` if present.
- Data (read-only, training database only): `php docs/training/organisation-admin/tools/q.php "SELECT …"`.
  It refuses anything but one SELECT/SHOW/DESCRIBE. The organisation is the fictional **Demo Group** (tenant slug
  `demo`); people and amounts are fictional.

## Hard safety rules

- Do **not** run `php artisan`, `php -r`, tinker, tests, migrations, seeders, `npm`, servers, or anything that writes to
  a database or the repository. Read files; use `q.php` for data. Never touch the `hcm` database.
- Do not modify any repository file. Your only write is your output file:
  `docs/training/organisation-admin/tools/content/<your-file>.json` (create the folder if needed).
- No git commands that change anything.

## Writing style

British English. Plain, short sentences in the second person ("Open …", "You can …"). Use the exact on-screen names
of menus, pages, tabs, fields and buttons (as in crawl.json, case included). Explain *why* as well as *how*. Numbers
from the training database are fictional examples: say "in the example organisation".

## Output: one JSON file per agent, an object keyed by module code

```json
{
  "M13": {
    "intro": "2–3 sentences: what this module is for an organisation administrator.",
    "why": "Why it matters / what goes wrong without it (1–2 sentences).",
    "who": "Which roles use it (from config/peopleos.php), in plain words.",
    "where": "Menu paths, e.g. People › Pay › Payroll runs.",
    "objectives": ["three short 'you will be able to' outcomes"],
    "depends_on": ["M10 Attendance: processed attendance feeds paid days", "…"],
    "subs": [
      { "id": "13.1", "name": "Short name", "desc": "One line.", "screens": ["people/payroll-desk", "people/run-payroll", "…"] }
    ],
    "screens": {
      "<screen id from assignment.json>": {
        "purpose": "What this screen is for (1–2 sentences).",
        "when": "When the administrator uses it (1 sentence).",
        "elements": [["On-screen name", "what it shows or does"]],
        "fields": [{ "label": "Exact label", "meaning": "what to enter and why", "rule": "Required|Optional|Automatic|Locked|null" }],
        "after": "What happens after the main action (saved as draft, sent for approval, …) or null.",
        "permission": "Permission keys needed, e.g. payroll.view to see, payroll.run to run.",
        "depends": "What must exist first, or null.",
        "limits": "A limitation the administrator must know today, or null.",
        "source": "main source file(s) you used"
      }
    },
    "procedures": [
      { "sub": "13.1", "title": "Run payroll for a month", "before": "What must be ready first",
        "steps": [["Short step heading", "What to click/enter and what you see"]],
        "result": "What you have at the end", "screens": ["ids that illustrate it"], "source": "files" }
    ],
    "calculations": [
      { "sub": "13.2", "title": "…", "intro": "…", "lines": [["", "Basic", "40,000.00"], ["+", "HRA", "20,000.00"]],
        "total": ["Gross earnings", "60,000.00"], "explain": [["Formula", "…"], ["Where you see it", "…"]],
        "source": "service/class and, if used, the q.php query" }
    ],
    "warnings": [ { "sub": "13.1", "title": "…", "level": "warning|blocked|important", "headline": "…", "body": "…", "points": ["…"], "todo": "…" } ],
    "troubleshooting": [["What you see", "Why it happens", "What to do", ""]],
    "checklist": [["Checklist item", "short detail"]],
    "summary": ["4–6 key points"],
    "captures_needed": [ { "title": "Hire wizard step 2", "url": "/admin/…", "how": "what to click/fill to reach the state to photograph" } ],
    "gaps": ["anything you could not confirm, features that are unavailable or incomplete, screens not captured"]
  }
}
```

Rules for the content:

- `subs` must cover **every screen** assigned to the module (each screen id in exactly one sub). 2–7 subs per module,
  in the order an administrator works.
- `screens` must have an entry for **every** assigned screen id (index, page, create, view, edit). For create/edit
  screens, list every form field the crawl shows (`fields`), with its meaning; mark `Required` only when the crawl or
  the form schema (`->required()`) says so.
- `elements`: 3–8 items per screen, taken from what is on that screen (tabs, header actions, toolbar buttons, columns,
  filters, row actions, sections, wizard steps). Explain what each does.
- `calculations`: only formulas the code really implements; numbers must add up exactly; say which numbers are from
  the example organisation and which are illustrative. Never present statutory rates as legal advice.
- Keep each string short enough for a slide: purpose ≤ 220 characters, element/field explanations ≤ 140, step body
  ≤ 200, summary points ≤ 140.
- Valid JSON (no comments, no trailing commas). Validate it with `node -e "JSON.parse(require('fs').readFileSync('<file>','utf8'))"`.

When finished, reply with: the output file path, counts (screens described, procedures, calculations, warnings,
troubleshooting rows), and the five most important `gaps`.
