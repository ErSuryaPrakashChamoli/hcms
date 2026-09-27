# Phase 14 — Analytics

Blueprint §56, §84, §85, §121 Phase 14. Built 2026-09-27.

## What exists

**Datasets (`App\Domain\Analytics\Datasets`)** — one class per reportable subject, each declaring the permission(s) that unlock it, an optional sensitive permission, a tenant-scoped Eloquent query with eager loads, and a field catalogue (`label`, `type` string / number / date / boolean, `value` closure, `sensitive`). Nine datasets: employees (headcount, demographics, tenure, org dimensions, CTC behind `employee.sensitive.view`), attendance, leave, payroll (finalized / paid entries), exits, performance, learning, assets, service desk. `DatasetRegistry` resolves them and filters by user.

**Report builder (§84)** — `Report` stores `dataset` + `definition` JSON: `fields`, `filters` (`field`, `operator`, `value`; operators equals / not equals / any of / contains / gt / gte / lt / lte / between / empty / not empty / last N days / this month / this year), `group_by` + `aggregations` (count / sum / avg / min / max with optional labels), `calculated` fields (safe `FormulaEngine` formulas over numeric row values, usable in filters, grouping and charts), `sort`, `limit`, `visualization` (table / bar / line / pie / kpi with x and y). `ReportRunner` maps rows in memory (bounded by `analytics.max_rows`), so every dataset behaves identically; it returns a `ReportResult` (columns, rows, total, grouped flag, chart series, kpi). `ReportExports` writes UTF‑8 BOM CSV (Excel-compatible) to the documents disk and records a `ReportRun` with an `EXPORT` audit event. `ReportSchedule` (daily / weekly / monthly at a time, recipients) is run by `peopleos:reports:run-due` hourly; recipients get an in-app notice.

**Metrics (`WorkforceMetrics`)** — headcount as of a date (joining / exit aware), joiners and exits in 30 days, 12‑month attrition rate (exits ÷ average headcount), absenteeism (absent ÷ scheduled days, 30 days), on leave today, people cost and cost per head from the last finalized payroll, average tenure, women share, high performers (final rating ≥ 4 in the latest cycle), mandatory learning completion, open tickets / grievances / exits / approvals; monthly series for headcount, joiners, exits, people cost, absenteeism; critical skills (fewest advanced / expert holders).

**Dashboards (§85)** — `Dashboard` with role audience (`role_ids`; empty = everyone with `analytics.view`), default flag and ordered widgets: `kpi` (metric), `trend` (12‑month series), `chart` / `table` / `leaderboard` (a shared report), `alerts` (Needs Attention counts for the viewer). `Dashboards` resolves a board for a viewer and reports per-widget errors instead of failing the page. Every tenant is provisioned with six shared reports and the default **HR overview** dashboard.

## Admin UI ("Analytics" group)

- **Workforce Command Centre** (`analytics.executive`): twelve KPIs, four 12‑month trends, critical skills table; open positions shown as pending the RMS integration.
- **Dashboards**: viewer with a switcher across the boards the user may see. **Dashboard builder** (`analytics.manage`): audience, widgets repeater.
- **Reports**: list (shared or own), builder form (dataset → fields → filters → grouping → calculated → visualization), run page with grouped table, Chart.js chart widget for bar / line / pie, KPI number, CSV export; Schedules and Runs tabs (download).

**Permissions** — `analytics.view|reports|manage|export|executive`. New **Executive** role template. HR Admin gains view / reports / export; Auditor gains view and the command centre.

## Conventions

- Datasets are the only place that knows how to derive values; reports never touch models directly. Add a dataset by subclassing `Dataset` and registering it in `DatasetRegistry`.
- Sensitive fields are stripped by `Dataset::fieldsFor()` for viewers without the dataset's sensitive permission, so a saved report cannot leak them to a less-privileged viewer.
- Report runs and exports are audited; scheduled runs carry `run_by = null` and the schedule id.
- Compare date columns with `whereDate` in metric queries: SQLite stores dates with a time part, so `between` on plain date strings drops the last day.
- Tests: `tests/Feature/Analytics/ReportingTest.php`, `tests/Feature/Admin/AnalyticsPagesRenderTest.php`.

## Known gaps / deferred

- Excel (`.xlsx`) and PDF exports; only CSV is produced (no spreadsheet or PDF library installed).
- In-memory filtering and grouping are bounded at 10,000 rows per run; very large tenants will need pushed-down SQL aggregation.
- Heatmap, funnel and calendar widgets; dashboards render bars and tables with inline HTML rather than Chart.js (the report page uses Chart.js).
- Open positions require the RMS integration (Phase 16 scope for external feeds).
