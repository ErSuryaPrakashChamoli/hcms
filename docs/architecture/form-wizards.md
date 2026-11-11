# Record forms as wizards

Every record form is filled in step by step, the way the policy wizard (`App\Filament\Pages\PolicyWizard`) works: one section per step, Previous / Next, and a last **Review** step that lists what was filled in (create) or changed, before → after (edit), with the reason for change and the Create / Save button. Introduced 2026-10-29 at the owner's request ("a wizard to follow for all, not one long form").

## How it works

- **`App\Filament\Support\FormWizard`** turns a form's top-level components into steps: each titled `Section` (or `Fieldset`) is a step; consecutive loose fields become one step named after the record ("Shift", "Attendance device"); `Hidden` fields and the reason for change (`AuditReasonField`) go to Review. The steps reuse the form's own components, so fields, validation, relationships and the save path are unchanged. A step is shown while something in it is visible.
- **`App\Filament\Support\Forms\PeopleWizard`** is Filament's wizard with one change: Next always validates the step being left, also when steps can be opened freely.
- **Record pages** (`PeopleCreateRecord`, `PeopleEditRecord` via `Pages\Concerns\FollowsFormWizard`): always a wizard. Creating goes in order (earlier steps can be reopened); editing can open any step from the step list. The page's submit and cancel buttons move to the wizard's last step. A page that builds its own wizard (Filament's `HasWizard`, e.g. the hire wizard `CreateEmployee`) keeps it, with the same layout. A page can opt out by overriding `usesFormWizard()`.
- **Drawers** (`PeopleCreateAction` / `PeopleEditAction`, bound in place of Filament's `CreateAction` / `EditAction` by `PeopleOsUi::register()`, via `Actions\Concerns\FollowsFormWizard`) and **action modals** (`PeopleAction` / `PeopleBulkAction`, bound in place of `Action` / `BulkAction`): a wizard when `FormWizard::wants()` — two titled sections or more (`DRAWER_MINIMUM`), or four fields or more (`MODAL_MINIMUM_FIELDS`). A short note, status change or quick edit stays one screen (drawers keep the review panel underneath). Action modals use mode `action` ("…then confirm"); read-only modals and the small modals of a form's own controls (a select's "create option", rich editor blocks, repeaters: `getSchemaComponent()` set) never become wizards. An action given its own `->steps()` keeps them, in the same `pos-wizard pos-wizard-drawer` style. Rule set by the streamline programme (2026-11-05) and narrowed by its review (2026-11-10: "do not force a wizard onto every small form").
- **Long runs of fields** are split into steps of at most `FormWizard::LOOSE_STEP_MAXIMUM` (6), in the form's order: the first named after the record ("Details" in action modals), then "More details", "More details (2)".
- **Custom pages** use the same engine: `SecurityPolicyPage`, `SetupCentre` (each module's settings; ungrouped settings in parts of six), `PayCyclePage` and `ExpenseFormSetup` build their sections into the schema, then wrap them with `FormWizard::steps($schema, FormWizard::EDIT, …)` and a submit action on Review. `DeclareInvestments` and the hire wizard build their own steps with the same page rail; the hire wizard ends in the same Review (`FormWizard::review()`). The guided payroll run (`RunPayroll`) and the policy wizard keep a step rail drawn the same way.
- **Review** (`resources/views/filament/shell/wizard-review.blade.php`) uses the browser-side `posFormReview` component (`resources/js/peopleos.js`): it reads every step of the wizard, groups the fields by step, masks secrets, and treats what the form shows before the person first changes a field as the starting point (selects and date pickers that finish loading late are not changes). Its baseline resets only after a save (`save` / `create` calls), not after Next.
- **Layout** (`theme.css`, section J): on record pages the steps sit in a sticky rail beside the step (from 1024 px); in drawers they wrap above the step and the buttons stay in view; on phones every step is a numbered dot with the current one named.

## Conventions for new forms

- Put fields in titled `Section`s, 2–6 fields each, named the way an HR administrator would say it. Put the reason for change at the top level (`AuditReasonField::make()->visibleOn('edit')`); the wizard moves it to Review.
- A drawer form with 8 or more fields must have at least two sections. `tests/Feature/Experience/FormWizardTest.php` ("keeps long drawer forms in sections") fails otherwise.
- Never wrap fields into a section that has `->relationship()` or `->statePath()` just to make a step: that changes where the data is saved.

## Not converted

- Action modals with fewer than four fields (a reason, a date, one choice): a wizard would only add a click.
- Auth and signup screens (sign-in, password change, invitation, the public signup pages): short, outside the panel shell.
- Hand-written one-line inputs (comments, discussions, the assistant's chat box) and filter bars.
