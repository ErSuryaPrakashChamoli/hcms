# Salary structure builder

A salary structure is set up in five plain steps instead of formulas, sort orders and parameters. Introduced 2026-11-11 at the owner's request: the old screens (structure form → Versions tab → ⋮ → "Edit draft" drawer with formula overrides, parameters, a CTC balancing component and a preview with eight inputs) were confusing for a first-time administrator.

## The five steps (`App\Filament\Pages\SalaryStructureBuilder`, slug `salary-structure-builder`)

1. **Name and company** — name, company (or all companies), optional narrowing by grade / department / … (`StructureApplicabilitySchema::fields()`), start date. The date is checked at once against finalized payroll and the version in use (`StructureBuilder::dateProblem`), not only at approval.
2. **How pay is set** — "I give a yearly CTC, and this structure splits it" (`ctc_mode = ctc`) or "I give an amount for each component, and the CTC is added up" (`components`). A new structure also picks a start: ready-made (`StructureBuilder::TEMPLATES`, written in plain English and read by the converter), a copy of another structure, or an empty list.
3. **Components** — two ways in, one set of rows:
   - **Pick from a list**: a table — component (search the component list, or "+ Add “X” as a new component", offered only for text that reads as a name), *worked out as*, value ("40 % of CTC", "50 % of Basic", "1,600 a month", or a chip: "Typed in on each employee’s salary", "Balances to the CTC"; percentages get a min / max behind "Add a min / max"), and a Fixed / Variable switch. A stripe in the type's tone marks each row (earning, deduction, employer cost, reimbursement) and a component the rules add carries a "New · earning" chip that sets its type. Styles: theme.css section N2 (`.pos-rules`); on narrow screens rows become labelled cards.
   - **Write in plain English**: one component per line; **Turn into components** fills the same rows and reports, line by line, what each was read as (or why not). Switching to plain English shows the current rows as sentences.
4. **Check** — a sample yearly CTC (and sample amounts for per-employee components): monthly / yearly breakup, CTC, gross, employer PF, and plain warnings (does not add up, below zero, Code on Wages share).
5. **Save** — the structure read back as sentences in calculation order, the components that will be added, "what happens next" (approval → give to employees → payroll); **Save as draft** or **Send for approval**.

Entry points: Salary structures list → **New salary structure** / row click / **Change**; the old create route redirects here; on the record page (now "versions and approval") **Change components**, and the Versions table's **Change components** / **Edit draft** open the builder. The Edit-draft drawer and the New-version dialog are gone.

## The six ways (`App\Domain\Compensation\Support\StructureRules`)

| Way (`how`) | Formula written | Read back from |
| --- | --- | --- |
| `percent_ctc` | `ctc_monthly * P / 100` | `ctc_monthly * 0.4`, `ctc_annual * P / 100 / 12`, parameters put in as numbers |
| `percent_of` | `basic * P / 100` | `basic * 0.5`, `0.5 * basic`, … when `basic` is another row |
| `fixed` | `1600` | a bare number |
| `per_employee` | none (the amount on the employee's compensation) | no formula |
| `balance` | `StructureFormulas::balancingFormula()` (CTC less the other CTC lines and employer PF), and the version's `balancing_component_id` | the balancing component, or `max(0, ctc_monthly - a - b …)` |
| `formula` | as written | anything else |

Percentages may carry `min` / `max` → `max(…, min)`, `min(…, max)`, `clamp(…, min, max)`. Employee *input* components (`calculation_method = input`) keep `how = input`. `StructureRules::sentence()` writes each rule as the sentence the converter reads back to the same rule (tested both ways).

## Plain English (`App\Domain\Compensation\Support\PlainEnglishRules`)

Deterministic and offline — the same words always give the same rule, nothing leaves PeopleOS, and every line is shown back before saving. Understands "X is P% of CTC / of Y", a floor or cap said as "the higher / lower of 50% of CTC and 15,000", "… or 15,000, whichever is higher", "max(50% of CTC, 15000)" or "Max(Basic 50% of CTC, 15000)", "P% of CTC as X", "the rest goes to X", "fixed 1,600 a month", "15,000 a year" (÷ 12), "a quarter" (÷ 3), "Rs", "1.2k", "1.5 lakh", "half of basic", "at least / minimum / at most / up to / capped at", "variable", "(deduction)", "(employer contribution)" (gratuity by name), "entered for each employee", lists on one line ("Basic 50% of CTC, HRA 40% of basic and conveyance 1600"), and formulas written directly (`OT = if(grade == "M2", 5000, 0)`; commas inside a formula never split; a formula that has a plain shape, e.g. `max(ctc_monthly * 0.5, 15000)`, becomes that rule). A name that looks like a formula ("max(", operators) is never taken as a component name; "Leave travel allowance (LTA)" matches the LTA component by either part. PF / ESI / PT / TDS / LWF / EDLI lines are skipped with "worked out by payroll". "% of gross / net" and daily or hourly amounts are refused with what to write instead. A language model was considered and not used for the conversion: payroll rules must be predictable and explainable, and `peopleos.ai.provider` is `none` by default.

## Saving (`App\Domain\Compensation\Services\StructureBuilder`)

- `compile()` validates the rows in plain words (duplicates, missing percentages, a % of something not listed or of the balancing component, two balancing components, CTC rules in a component-amounts structure, a per-employee row on a component that has its own formula, one-off components, dependency loops, bad formulas), puts them in **calculation order** (each after what it uses, the balancing component last — people never set sort orders), writes the formulas, and runs `StructureFormulas::check()` on the result.
- `save()` creates components the rules introduce (needs `payroll.manage`; code from the name, e.g. `MEDICAL_ALLOWANCE`; flags as a standard Indian payroll treats them: Basic / DA are PF wages, earnings taxable and ESI wages, variable pay not prorated, deductions outside CTC and gross; the structure's formula becomes the component's own), then saves through `CompensationStructures`: a new structure (code from the name) with draft version 1, or the structure's draft (a new draft from the version in use when there is none). Parameters are no longer needed — formulas carry the numbers — and are cleared. The change note is written for the approver ("Changed: HRA is 50% of Basic → HRA is 40% of Basic. Added: …"). **Send for approval** submits it; approval by another person, effective dating, immutability of approved versions and the payroll calculation are unchanged.
- A structure with a version waiting for approval opens read-only.

## Guide

`SalaryFormulaUi::guideAction()` / `guideDocument()` (`resources/views/filament/payroll/formula-guide*.blade.php`): five steps, the six ways with examples and their plain-English form, writing in plain English, five copy-ready examples for different kinds of company, what payroll does automatically, the worked example, and the formula reference last ("for advanced users"). **Download guide** (builder header, guide footer) saves `salary-structure-guide.html`, which has a **Print or save as PDF** button (no PDF library is installed).

## Deploy note

A new Filament page is missing from a server whose Filament component cache (`bootstrap/cache/filament`) predates it, and the Salary structures list then fails building its link. `deploy.sh` runs `php artisan optimize`, which rebuilds it; locally run `php artisan filament:cache-components` (or `optimize:clear`).

Tests: `tests/Feature/Compensation/SalaryStructureBuilderTest.php` (converter, rule ↔ formula, matching, ordering and preview, plain problems, save / approve, change as a new version, the page walked through in plain English, narrowing, read-only while pending, entry points, guide and download).
