# Record forms as wizards

Every record form is filled in step by step, the way the policy wizard (`App\Filament\Pages\PolicyWizard`) works: one section per step, Previous / Next, and a last **Review** step that lists what was filled in (create) or changed, before → after (edit), with the reason for change and the Create / Save button. Introduced 2026-10-29 at the owner's request ("a wizard to follow for all, not one long form").

## How it works

- **`App\Filament\Support\FormWizard`** turns a form's top-level components into steps: each titled `Section` (or `Fieldset`) is a step; consecutive loose fields become one step named after the record ("Shift", "Attendance device"); `Hidden` fields and the reason for change (`AuditReasonField`) go to Review. The steps reuse the form's own components, so fields, validation, relationships and the save path are unchanged. A step is shown while something in it is visible.
- **`App\Filament\Support\Forms\PeopleWizard`** is Filament's wizard with one change: Next always validates the step being left, also when steps can be opened freely.
- **Record pages** (`PeopleCreateRecord`, `PeopleEditRecord` via `Pages\Concerns\FollowsFormWizard`): always a wizard. Creating goes in order (earlier steps can be reopened); editing can open any step from the step list. The page's submit and cancel buttons move to the wizard's last step. A page that builds its own wizard (Filament's `HasWizard`, e.g. the hire wizard `CreateEmployee`) keeps it, with the same layout. A page can opt out by overriding `usesFormWizard()`.
- **Drawers** (`PeopleCreateAction` / `PeopleEditAction`, bound in place of Filament's `CreateAction` / `EditAction` by `PeopleOsUi::register()`, via `Actions\Concerns\FollowsFormWizard`): a wizard when the form has at least `FormWizard::DRAWER_MINIMUM` (2) steps; short forms stay one screen with the review panel underneath. An action given its own `->steps()` keeps them.
- **Custom pages** can use the same engine: `SecurityPolicyPage` builds its sections into the schema, then wraps them with `FormWizard::steps($schema, FormWizard::EDIT, …)` and a "Save policy" submit action.
- **Review** (`resources/views/filament/shell/wizard-review.blade.php`) uses the browser-side `posFormReview` component (`resources/js/peopleos.js`): it reads every step of the wizard, groups the fields by step, masks secrets, and treats what the form shows before the person first changes a field as the starting point (selects and date pickers that finish loading late are not changes). Its baseline resets only after a save (`save` / `create` calls), not after Next.
- **Layout** (`theme.css`, section J): on record pages the steps sit in a sticky rail beside the step (from 1024 px); in drawers they wrap above the step and the buttons stay in view; on phones every step is a numbered dot with the current one named.

## Conventions for new forms

- Put fields in titled `Section`s, 2–6 fields each, named the way an HR administrator would say it. Put the reason for change at the top level (`AuditReasonField::make()->visibleOn('edit')`); the wizard moves it to Review.
- A drawer form with 8 or more fields must have at least two sections. `tests/Feature/Experience/FormWizardTest.php` ("keeps long drawer forms in sections") fails otherwise.
- Never wrap fields into a section that has `->relationship()` or `->statePath()` just to make a step: that changes where the data is saved.

## Not converted

- **Setup centre** (`SetupCentre`): a settings hub whose form is rebuilt when another module is picked; its per-module settings are short and grouped already.
- **Goals** (`GoalResource`): its form was being changed by another work stream at the same time; it gets the page wizard from its existing sections.
- Short drawers (fewer than two sections, e.g. exchange rates, organisation contacts) and action modals that are not record forms.
