# Unique codes: checked while typing, never a server error

Introduced 2026-11-19 after a duplicate department code ended in the server-error page (`UniqueConstraintViolationException` on `departments_tenant_id_code_unique`). Owner: a duplicate must be caught while the form is filled in, with the field itself saying the code is taken or available.

## How it works

- **`App\Filament\Support\UniqueCode`** (registered in `AppServiceProvider::boot()` through `TextInput::configureUsing`) checks every text field whose column the database keeps unique: per organisation (`unique(['tenant_id', column])` on a model using `BelongsToTenant`) or outright (`unique(column)`). The scope comes from the table's own unique indexes (`App\Support\Database\UniqueIndexes`, read once per process), so the check refuses exactly what the database would and nothing else:
  - every row of the organisation counts, archived (soft-deleted) rows and rows outside the viewer's access scope included, because the index counts them;
  - the record being edited is ignored, and a code that differs only in case counts as a clash on MySQL but not on SQLite (the database's collation decides, just as for its index).
- **While typing** (debounced 500 ms) the field is validated with all its rules (required, format, uniqueness) and only that field is re-rendered (Filament partial render), unless the form made the field live itself or added its own state hooks; then the whole form re-renders as before. The label shows *Available* (green) or *Already in use* (red, the full message as the icon's tooltip); the error under the field names the holder: "DM is already used by Digital Marketing. Choose another code." A holder the viewer may not see is "another department"; an archived one is "Old Sales (archived)".
- **On save** the same rule runs, so a clash never reaches the database from a form.
- **Where it applies automatically:** record forms only, meaning resource create and edit pages and their Create / Edit drawers (schema operation `create` or `edit`, whose record matches the form's model). Fields inside repeater or builder items, other actions' modals and custom pages are left alone unless they opt in.
- **Opting in:** `TextInput::make('code')->uniqueCode(Model::class)` (model may be a closure; `column:` and `ignore:` optional). Used by the Organisation Designer's *Add unit* (model from the chosen unit type) and the policy wizard (ignores the policy being edited).
- **Opting out:** `->uniqueCode(enabled: false)` for a field with its own check. Only the hire wizard's employee code uses it: its own check ignores case on every database.
- Composite unique keys beyond tenant + column (e.g. workforce reasons per kind, service definitions per effective date) are not checked automatically; keep their own `->unique(…, modifyRuleUsing: TenantUnique::rule())`, and the guard below still catches what slips through.

## Safety net: `DuplicateEntryGuard`

Registered next to `UniqueCode`, it listens to Livewire's `exception` hook on every component. A `UniqueConstraintViolationException` that still reaches the database (two people saving the same code at once, or a key spanning several fields) no longer shows the error page. Instead:

- the page stays as it was, with everything typed kept (Filament's pages and actions, and `DB::transaction()`, have already rolled back);
- the clashing field (in the open drawer or modal first, then the page form) gets the error, and a persistent *Already in use* notice says what to change ("Another department already has the code DM. Change the code and try again.");
- a `notice` log line records the component, table and columns (not the values), so forms that rely on the guard can be found and given a proper check.

`App\Support\Database\DuplicateEntry` reads the table, columns and values from the MySQL/MariaDB, SQLite and PostgreSQL wordings.

## Tests

`tests/Feature/Experience/UniqueCodeCheckTest.php`: taken / available while typing (partial render), format errors, refused save, edit keeps its own code, other organisations ignored, hidden holder not named, drawers, both opt-in pages, the guard, and the parser.
