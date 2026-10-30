# HROne Setup vs PeopleOS — 08 Workforce · 10 Payroll (field by field)

> **Progress (2026-10-28):** built — confirmation policy and reviews (08.1 #1–#9, #13 except `applies_to`, #15),
> separation policy wiring (08.3 #1, #3, #5–#8, #13 clearance checklist, letters on start / completion; interviews by
> manager / HR and blacklist reasons in the reason master), transfer policy, requests and reasons (08.4, 08.5).
> **Progress (2026-10-29, WF-3 / HS-P13):** built — confirmation letters and feedback (08.1 #10–#12: `letter_on_terminate`,
> manager feedback before the decision, probation self-review, copy to the next level), induction programmes (08.2 #1,
> #4, #5: template `kind`, `starts_days_before_joining`, `peopleos:onboarding:start-due`, induction evaluation template and
> `feedback` item), exit interviews by the employee, the manager and HR on feedback templates (08.3 #10–#12), transfer
> allowances (08.6 #1–#2: component flag, allowances on transfer requests, `TransferAllowanceInput`). See
> `docs/architecture/hrone-parity.md` → "Feedback templates, exit interviews, induction …".
> Open: generic `applies_to` (08.1 #13, 08.3 #15); transfer allowances unpaid at exit are not in the full and final
> settlement. Payroll (section 10) follows package by package.


Prepared 2026-10-28, read-only. Recruitment is out of scope (RMS boundary).

**Inputs.** HROne control extraction in `scratchpad/hrone/spec/08_Workforce.md`, `10_Payroll.md` and
`common-settings-payroll.md` (the payroll Settings panel, about 100 switches). These sources fill in what the
outline left out, and each is marked *(supplementary)* where it is used:

- `scratchpad/hrone/components2.json`: the policy form steps `app-step-one/two/five-confirmation-policy`, `app-separation-information`, `app-step-two-separation`, `app-review-separation`, `app-pay-group-step1/tag/review`, `app-paygroup-formula`, the challan sidebars and `app-statutory`.
- `scratchpad/hrone/setup/*.txt`: the visible page text, including slot names, the seeded transfer reasons and the 30 seeded pay components.
- `docs/hrone-parity/hrone-configuration-catalogue.md`: the API field names of pay groups, pay components and the 105 "payroll general settings".

**PeopleOS** was checked in code: migrations, models, services, Filament resources, `config/peopleos.php`,
`config/peopleos_setup.php` and `database/data/compliance/in.php`. Uncommitted HP-1/HP-2/HP-3 work in the working tree is
counted as present.

**Status key**

- **HAVE**: an admin can get the same effect today.
- **PARTIAL**: something exists but does not fully cover the HROne setting. This includes behaviour that is fixed, with no switch, and generic engines that would cover the setting once a policy type or setting is declared.
- **MISSING**: nothing equivalent.
- **N-A**: not applicable, with the reason given.

*(inferred)* marks a meaning taken from the control name because HROne's label was not captured.

**Conventions used in the build notes** (all from PeopleOS docs and code):

- **Settings.** A setting is a key in `config/peopleos.php` → `settings` (its default) plus an entry in the module's `settings` list in `config/peopleos_setup.php`. It is listed only when code reads it, and `SetupCentreTest` checks it. Every default below reproduces today's behaviour.
- **Per-group options.** These are fields of a Policy Engine type in `peopleos.policies.types`. Name, description and status live on `policies`, settings on `policy_versions`, and applicability on `policy_assignment_rules` (conditions + priority). A new type is also added to the module's `policy_types` in `config/peopleos_setup.php`.
- **Field options.** `options_from` is resolved in `app/Filament/Support/PolicySettingsSchema.php::options()` and `app/Domain/Configuration/Services/SetupCatalogue.php::optionsFor()`. New sources must be added to both.
- **New models.** They are tenant-owned (`BelongsToTenant` + `Auditable`, plus `HasEffectiveDates` where history matters), access-scoped when linked to an employee, classified in `app/Domain/Offboarding/Support/DataInventory.php`, guarded by a policy and shown on PeopleOS base pages. Wiring goes in `App\Providers\ParityServiceProvider`.
- **Payroll extension points.** Money paid or recovered through payroll goes through a `PayrollInputSource` (tag `peopleos.payroll_inputs`). Money paid at exit goes through a `SettlementSource` (tag `peopleos.settlement_sources`).
- **Statutory values** (rates, ceilings, slabs, limits, statutory conditions) come only from rule versions in `database/data/compliance/in.php`, which are immutable and verified. They never come from settings. Finalized payroll is never reopened.
- **Permissions.** New keys are declared in `config/peopleos.php` → `permissions`, then `php artisan peopleos:sync-permissions` is run.

---

## Summary

| Section | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---|---|---|---|---|
| 08 Workforce (confirmation, induction, separation, transfer, reasons, allowances, embedded Settings drawer) | 53 | 9 | 18 | 18 | 8 |
| 10 Payroll: challan, pay groups, pay components, formula tags, out-of-CTC allowances, glossary, settings page (10.1–10.7) | 96 | 31 | 34 | 26 | 5 |
| 10 Payroll: payroll settings panel (`app-payroll-setting`, every switch, 10.8) | 56 | 7 | 20 | 27 | 2 |
| 10 Payroll: payroll fields only in HROne's API (appendix, 10.9) | 39 | 10 | 11 | 9 | 9 |
| 10 Payroll: slabs, statutory template, statutory page (10.10–10.12) | 57 | 17 | 17 | 15 | 8 |
| **Total** | **301** | **74** | **100** | **95** | **32** |

The counts were made by script from the tables below. Rows that only point to another table ("see 10.x") are not counted. Of the 08 Workforce N-A rows, 7 are the embedded Settings-drawer panels that belong to other sections' files.

**Biggest gaps**

1. **Workforce process policies.** There is no `confirmation` or `transfer` policy type. The `exit` policy type exists, but nothing reads it: notice comes from one setting. So there is:
   - no confirmation review (extend or terminate, extension limits, feedback, letters);
   - no transfer request or approval, transfer reasons or transfer allowances;
   - no notice period by probation or confirmed status, backdating windows or retirement age;
   - no configurable clearance checklist or manager/HR exit interviews.
2. **Pay component flags.** There are no wage flags for PT, LWF, bonus, gratuity or encashment. Show-on-payslip, show-in-register, joining-month and exit-month processing, rounding, the tax-exemption regime and the FBP fields are missing too. `is_arrear_eligible` is stored but nothing reads it.
3. **Minimum wages and the wage-code checks.**
   - There is no minimum-wage data (no `MIN_WAGE` rules) and no master of statutory skills (skill categories), so bonus on minimum wages is not possible.
   - The 50% wage rule is advisory only.
   - PT rules cover 9 states and LWF rules 7 states.
4. **Paying the salary.**
   - There are no pay modes (cash or cheque), no company debit bank and no bank-specific file layouts.
   - Payslips cannot be password-protected because there is no PDF library.
   - There is no payroll glossary and no translated component names.
   - There are no pay-group-level templates, and payroll runs cannot be split by pay group.
5. **Employee statutory templates and options.** There are no statutory templates, and these employee-level options are missing:
   - EPS applicability;
   - a separate employer PF wage basis;
   - LWF applicability per employee;
   - ESI dispensary and sub-unit;
   - PRAN and NPS elections;
   - the PAN–Aadhaar link.

   Contracts, 10(10AA) at settlement, FBP and the go-live year-to-date figures are also missing.

---

# 08 Workforce

## 08.1 Confirmation policy (`/workforce/confirmationpolicy`)

Page text: "Confirmation policy can be configured for employee confirmation process", ADD POLICY, card "Confirmation policy · ACTIVE · APPLICABLE ON Company". The form steps come from `app-step-one/two/five-confirmation-policy` *(supplementary)*.

| # | HROne setting (question/label, control(s), options) | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Policy list with count, ADD POLICY, filter "Policy" (Select Policy) and "Status" | No `confirmation` type in `config/peopleos.php` → `policies.types`. The generic Policies resource (`app/Filament/Resources/Policies`) lists, filters and activates policies of any declared type. | MISSING | Add type `confirmation` ("Confirmation policy") to `policies.types`, and add it to `workforce.policy_types` in `config/peopleos_setup.php`. The list, filter and status then come from PolicyResource. |
| 2 | Stepper "Setting / Advance setting / Applicability / Advance applicability / Review" | Policy (name, description, status), PolicyVersion (settings, effective dates) and PolicyAssignmentRule (conditions, priority). | HAVE | Engine. Available as soon as the type exists. |
| 3 | `confirmationPolicyName`, `confirmationPolicyDescription` | `policies.name`, `policies.description` | HAVE | Engine. |
| 4 | `confirmationPolicyBasedOn` "Based on" (options not captured; probation measured from…) | `employees.probation_end_date` = joining date + `employee.probation.default_months` (`HireEmployeeAction`, `CreatePreEmployeeAction`). | PARTIAL | Field `based_on` (select: `joining_date`, default). Add more bases only once their meaning is confirmed. |
| 5 | `probationPeriod` | Setting `employee.probation.default_months` (one value per tenant, Setup → Workforce), plus a per-employee date. | PARTIAL | Field `probation_months` (number). `HireEmployeeAction` and `CreatePreEmployeeAction` resolve the confirmation policy (`PolicyResolver::resolve('confirmation', …)`) and fall back to the setting, so today's behaviour is unchanged. |
| 6 | `isHideTermination` "During confirmation, give an option to terminate? Enable / Disable" | No confirmation review. Termination is a separate exit (`Exits::initiate`, type termination). | MISSING | Field `allow_terminate` (bool, default true). A `terminate` decision calls `Exits::initiate(type termination)`. |
| 7 | `isHideExtension` "Give an option to extend? Enable / Disable" | HR can edit `probation_end_date` on the employee form. There is no record of the extension and no limit. | PARTIAL | Field `allow_extend` (bool, default true). |
| 8 | `extensionPeriod` | — | MISSING | Field `extension_months` (number, default 3): the most one extension may add. |
| 9 | `noOfExtension` | — | MISSING | Field `max_extensions` (number, 0 = unlimited, default 0). Extensions are recorded on the new `confirmation_reviews` table (below). |
| 10 | `confirmationIsLetterRequired` "Would you like to send a letter?", `letters[]` (`letterTypeId`, `letterTemplateId`) | Letter type `confirmation` with a default body (`LetterDefaults`). Letters are generated by hand. The workflow `document` node is a stub (`WorkflowEngine` logs "skipped"). | PARTIAL → built (WF-1 letters on confirm / extend; WF-3 `letter_on_terminate`) | Fields `letters` (repeater: `on` = confirm / extend / terminate, plus `letter_template_id`). Use a new `options_from: letter_templates`. A decision calls `Letters::generate` and creates a draft for approval when the template `requires_approval`. Add letter types `extension` and `termination` to `letters.types`. |
| 11 | `confirmationIsFeedbackRequired`, `confirmationAssessments` "Would you like to add a feedback?" (Select feedback) | The Forms engine (`app/Filament/Resources/Forms`) can hold a questionnaire, but it is not bound to confirmation. | PARTIAL → built (WF-3: `feedback_template_id` on a `confirmation` feedback template, a My tasks item for the manager, the decision waits for it) | Field `feedback_form_ids` (new `options_from: forms`). The review creates a manager task to fill in the form before the decision. |
| 12 | `isCopyNextLevelFeedback` | — | MISSING → built (WF-3: `share_feedback_with_next_level` — the manager's own manager reads it; deciders always do) | Field `share_feedback_with_next_level` (bool, default false). The next decider sees the earlier submissions. |
| 13 | Review step: "You want to apply this policy on: New employee / All employees" | A policy version applies to everyone who matches on the date. | MISSING | Generic `policy_versions.settings.applies_to` (`all` (default) or `joined_from_effective_date`), checked in `PolicyResolver::resolve`. This serves every policy type. |
| 14 | Applicability / Advance applicability ("APPLICABLE ON Company") | `policy_assignment_rules.conditions` + `priority` | HAVE | Engine. |
| 15 | The process the policy drives (review at lead days, decide confirm / extend / terminate) | `peopleos:lifecycle:reminders` raises `employee.probation_ending` and `employee.probation_overdue` (also workflow trigger events). Confirmation is a manual lifecycle move on ViewEmployee (`LifecycleEngine::transition` → sets `confirmation_date`). | PARTIAL | Field `review_lead_days` (number, default = `employee.probation.reminder_days`). New table `confirmation_reviews`: employee, due_on, status, decision (confirm / extend / terminate), extended_to, extension_no, form submission ids, letter_id, decided_by, decided_at. Add a `ConfirmationReviews` service, a daily opener and a `ConfirmationApprovalSource` (Approval Center, tag `peopleos.approval_sources`). Confirm → `LifecycleEngine::transition(Confirmed)`. |

## 08.2 Induction policy (`/workforce/inductionpolicy`)

Page text: "Induction policy - 0 … Induction is a structured approach…", card "Induction policy · ACTIVE · APPLICABLE ON Company". The catalogue fields are `inductionPolicyName`, `inductionPolicyDescription` and `inductionPriorDays`.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Policy list, filter "Policy" (Select policy), "Status" | Onboarding templates (`OnboardingTemplateResource`; `onboarding_templates`: name, key, description, priority, conditions, status). They start automatically on preboarding, joined or onboarding (`AutoStartOnboarding`). | PARTIAL → built (WF-3: `onboarding_templates.kind`, list column and filter) | Treat induction as a kind of onboarding template rather than a new engine. Add `onboarding_templates.kind` (`onboarding` (default) or `induction`) and a list filter. |
| 2 | Steps "Settings / Applicability / Advance Applicability / Review" | Template form + `conditions` + `priority` (`Onboarding::templateFor`) | HAVE | — |
| 3 | `inductionPolicyName`, `inductionPolicyDescription` | `onboarding_templates.name`, `description` | HAVE | — |
| 4 | `inductionPriorDays` (start N days before joining) | Phase offsets are fixed in `config/peopleos.php` → `onboarding.phases` (preboarding −3). Each item has its own `due_offset_days`. | PARTIAL → built (WF-3: `starts_days_before_joining`; preboarding items due then; `peopleos:onboarding:start-due`) | Template field `starts_days_before_joining` (number, default 3 = today's preboarding offset), used by `Onboarding::start` for the anchor and due dates. |
| 5 | Induction evaluation and request flows (`app-induction-evaluation`, `app-induction-request` *(supplementary, runtime)*) | An onboarding item of type `form`, owned by the manager, collects an evaluation (`onboarding.item_types`). | PARTIAL → built (WF-3: library template "Induction evaluation" (process `induction`) answered through an onboarding item of type `feedback`) | No new engine. Ship an "Induction evaluation" template preset. Welcome note and profile completion stay with WF-07. |

## 08.3 Separation policy (`/workforce/separationpolicy/policy`)

Page text: "Separation policy may be configured for employee separation process. You may also configure exit interview and clearance process". Cards: T3 above, S0, T0, "T1 &T2" Separation Policy, all "APPLICABLE ON Grade". The form steps come from `app-separation-information`, `app-step-two-separation` and `app-review-separation` *(supplementary)*.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Policy list, ADD POLICY, filter Policy / Status | The `exit` policy type exists (`policies.types.exit`: `notice_period_days`, `notice_buyout_allowed`), but **nothing reads it**. `Exits::initiate` and `Exits::resign` use the setting `exit.notice_days`. | PARTIAL | Wire `Exits` to `PolicyResolver::resolve('exit', $employee)` and fall back to `exit.notice_days`, so today's behaviour holds. Extend the type with the fields below. Relabel it "Separation policy". |
| 2 | `separationPolicyName`, `separationPolicyDescription` | `policies.name` / `description` | HAVE | Engine. |
| 3 | `retirementAge` "Retirement age (in years)" | No retirement handling (WF-08). | MISSING | Field `retirement_age` (number, 0 = none, default 0) and `retire_on` (select: `birthday` or `month_end`). A daily job raises the trigger event `employee.retirement_due`, added to `workflows.trigger_events`. Behind an HR confirmation it opens `Exits::initiate(type retirement)`. |
| 4 | `separationPolicyBasedOn` "Based on" (options not captured) | — | MISSING | *(inferred)* the basis for notice. Covered by #5 and #6 once the options are known. |
| 5 | `noticeDaysOnProbationEmployee` | One notice period (`exit.notice_days`) | MISSING | Field `notice_days_probation` (number, blank = same as confirmed). `Exits` picks the value by `lifecycle_state`. |
| 6 | `noticeDaysOnConfirmEmployee` | `notice_period_days` declared but unused; `exit.notice_days` is used | PARTIAL | Use `notice_period_days` as the confirmed notice (see #1). |
| 7 | `isBackDaySeparationAllowed` + `backDaysSeparationValue` "Allow backdated resignation by employee" | `Exits::resign` always records today as the resignation date. | MISSING | Field `employee_backdate_days` (number, default 0 = today's behaviour). The self-service resignation form gains a resignation date inside that window. |
| 8 | `isBackDaySeparationAllowedByManager` + `backDaysSeparationByManagerValue` "Allow backdated resignation by HR/Manager" | `Exits::initiate($resignationDate)` from HR (`exit.manage`) accepts any date. | PARTIAL | Field `hr_backdate_days` (number, blank = no limit = today), enforced in `Exits::initiate`. |
| 9 | `separationIsLetterRequired`, `letters[]` (`letterTypeId`, `letterTemplateId`) | Letter types `relieving` and `experience` exist; letters are generated by hand. | PARTIAL | Field `letters` (repeater: `on` = accepted / relieved / completed, plus `letter_template_id`). Exit events call `Letters::generate` and create a draft. Add letter types `resignation_acceptance` and `termination` (WF-01). |
| 10 | `separationIsFeedbackRequired`, `separationAssessments` "Exit interview from an employee?" (Select feedback) | `ExitInterviews::submit` with a fixed questionnaire (`exit.interview_reasons`, `interview_dimensions`); source `employee`. | PARTIAL → built (WF-3: `interview_employee_template_id`; blank keeps the standard interview) | Fields `interview_employee` (bool, default true) and `interview_employee_form_id` (`options_from: forms`; blank = the built-in questionnaire). |
| 11 | `separationIsFeedbackRequiredManager`, `separationAssessmentsManager` "Exit interview from Manager?" | — | MISSING → built (WF-3: `interview_manager` + template; a My tasks item for the exit's manager, due on the last working day) | Fields `interview_manager` (bool, default false) and `interview_manager_form_id`. `exit_interviews.source = manager`; a task for the line manager. |
| 12 | `separationIsFeedbackRequiredHR`, `separationAssessmentsHR`, `separationAssessmentsHRRoleId` (Role) "Exit interview from HR?" | HR can record one interview (source `hr_inferred`). | PARTIAL → built (WF-3: `interview_hr` + template + `interview_hr_role`; read by `exit.interview`) | Fields `interview_hr` (bool, default false), `interview_hr_form_id` and `interview_hr_role` (`options_from: roles`). |
| 13 | `separationIsChecklistRequired`, `separationChecklists` "Map clearance checklist?" (Select clearance checklist). The checklist builder `app-clearance-add-task` *(supplementary)* has `checklistTypeId`, `checklistName`, and `taskList` [department, authority (role), dueDay, taskName, video link, taskDependentIds, mandatoryEmployeeSettlement, isAutoApproved, categoryStatus]. | Clearance stages are fixed in `config/peopleos.php` → `exit.clearance_stages` (manager / it / finance / asset / hr). The owners are the roles in settings `exit.it/finance/hr_clearance_role`. The asset stage checks custody. | PARTIAL | New tables `clearance_checklists` (name, status) and `clearance_checklist_items` (stage key, name, owner role, due offset days, depends_on item ids, required_for_settlement, auto_clear). Add a Filament resource under Setup → Workforce links. Field `clearance_checklist_id` (`options_from: clearance_checklists`; blank = the built-in stages). `Exits` seeds `exit_clearances` from it. |
| 14 | `isSeparationReplacementRequired` "Trigger a replacement request once the resignation has been approved?" | — | N-A | A replacement requisition is recruitment, which is out of scope (RMS). The occupied position already shows as vacant once the exit completes (Workforce → Vacancies). |
| 15 | Review step: "Apply this policy on New employee / All employees" | — | MISSING | Generic `applies_to` (08.1 #13). |
| 16 | Applicability "APPLICABLE ON Grade" | Assignment rules (grade, company, …) | HAVE | Engine. |
| 17 | Separation approval task *(supplementary, runtime `app-separation-task`)*: `approvedReason`, `noticeRecoverRelaxationDays`, `alumniStatus`, `blacklistReasonId`, `blacklistComments`, `replacementRequestBy` | Setting `exit.notice_recovery` (all or nothing). `Exits::createAlumni`. `exit_cases.is_rehire_eligible` (no reason master). | PARTIAL | Notice relaxation days and the approval step belong to WF-02 (resignation approval). The blacklist reason comes from the reasons master (08.5, `kind = blacklist`). |

## 08.4 Transfer policy (`/workforce/transferpolicy/policy`)

Page text: "Transfer policy can be configured from here. You may define what all can be changes as part of transfer process", card "Transfer Policy · ACTIVE · APPLICABLE ON Company".

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Policy list, filter "Policy name" (multi-select), "Status" | No `transfer` policy type. | MISSING | Add type `transfer` to `policies.types` and to the `workforce` module in `peopleos_setup.php`. |
| 2 | `transferPolicyName`, `transferPolicyDescription` | `policies.name` / `description` once the type exists | HAVE | Engine. |
| 3 | Advance setting (controls not captured). The catalogue's "Transfer change types — organizationKey" says which organisation dimensions a transfer may change. | HR's "Change position" action on ViewEmployee (`AssignPositionAction`, change_type `transfer`, any dimension) or `TransferEmployeeAction`. It applies at once. | PARTIAL | Fields: `changeable_dimensions` (multiselect over `EmployeePosition::DIMENSIONS` + reporting / functional manager; default all); `request_by` (multiselect employee / manager / hr; default hr); `approval_required` (bool, default false = applies at once as today); `notice_days`, `backdated_days` (WF-04 date windows); `salary_revision_allowed` (bool). |
| 4 | Applicability / Advance applicability | Assignment rules | HAVE | Engine. |
| 5 | Transfer request and approval *(supplementary runtime `app-transfer-request`, `app-do-transfer-details`)*: date, reason, position and organisation dimensions, managers, `isSalaryRevised`, `isGoalChanged`, `isRemoveGeoLocation`, allowances | Direct change only. `employee.transferred` is already dispatched (`AssignPositionAction::dispatchEvents`). Compensation change type `transfer` exists. | MISSING | New table `transfer_requests`: employee, requested_by, reason_id, suggested_date, final_date, dimensions JSON, manager ids, salary_revised + compensation_change_id, goals_changed, remove_geo_fences, allowances JSON [component_id, amount], status, workflow_instance_id. Add a `TransferRequests` service and a `TransferApprovalSource`. Approval calls `TransferEmployeeAction::handle`. Pre/post-transfer checklists reuse `clearance_checklists` (08.3 #13) with `kind`. |

## 08.5 Transfer reasons (`/workforce/transferpolicy/reason`)

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | "Reason - ##", ADD REASON. `reasonName`; filter Reason / Status. Seeded: Personal request by employee, Remedial transfer, Versatility transfer, Replacement, Organizational requirement, Tenure over, Shortage of workforce, Promotion. Catalogue extras (shared with separation reasons): `isForEmployee`, `isForApprover`, `isApproverRemarkMandatory`, `resignationType`. | The reason for a transfer is the free-text audit reason (`AuditReasonField`). Exit reasons are free text plus the config list `exit.interview_reasons`. | MISSING | New table `workforce_reasons` (kind = transfer / separation / blacklist / probation_extension, name, code, for_employee, for_approver, approver_remark_required, sort_order, status) with a Filament resource "Reasons" (Setup → Workforce links). Seed HROne's eight transfer reasons only when the tenant chooses to. |
| 2 | Active / inactive per reason | — | MISSING | `status` on the same table. |

## 08.6 Transfer allowances form (`/workforce/transferpolicy/allowances`)

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | "Transfer allowances — Fields / Label on fields" (Allowance1 … Allowance6, Rename, UPDATE), `fieldsName[]`, `allowanceDisplayName` | — | MISSING → built (WF-3: `salary_components.transfer_allowance`; the name is the label) | PeopleOS style, with no fixed slots: a salary component flag `transfer_allowance` (bool) on one-off earnings. The component name is the label. |
| 2 | Allowance on the transfer: `allowanceId`, `amount`, `allowanceVariableId` *(supplementary)* | — | MISSING → built (WF-3: `transfer_requests.allowances`, `TransferAllowanceInput`, settled on finalize) | Rows on `transfer_requests.allowances`. They are paid by a new `TransferAllowanceInput` (`PayrollInputSource`, key `transfer_allowance`, settled on `payroll.finalized`), which carries the component's own taxable, PF and ESI flags as `VariablePayInput` does. |

## 08.7 Settings drawer embedded in the Workforce pages (`app-common-settings`)

HROne opens the same cross-module "Settings" drawer on every Workforce page. Its panels belong to other sections and are mapped in those files. They are listed here so that every control in the input is accounted for.

| # | HROne panel / controls | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | "Delete Transaction" dialog: `performanceCycleId`, `taskTypeIds`, `remarks` ("This irreversible action will permanently delete all data…") | Business transactions are never hard-deleted (audit hash chain, finalized payroll). Disposal goes through retention, legal hold and tenant offboarding (SaaS.9). | N-A | It conflicts with the audit invariants. Covered by the Governance gap file. |
| 2 | `app-time-office-setting` (`enableArrear`, `arrearMonthUpTo`, `intervalIdForShiftSchedule`, `intervalValue`, `allowDefaultWorkingHours`, `allowAbsentWeekOffHolidayAbsent`, `payNewJoinerArrear`, `enableWeeklyOffLimit`, `numberOfWeeklyOffInWeek`, `isOvertimeHoursEditable`, `allowRequestAfterExtraBenefits`, `allowLeaveRequestOnPresentDay`, `isHolidayFinancialYear`, `settings`, `status`) | See the 09 Time office gap file (`common-settings-timeoffice.md`). | N-A | Belongs to another section. |
| 3 | `app-task-setting` (`groups`, `settings`, `status`, `declarationText`; "You want to apply this change to New Employee / All employee") | See `automation-forms-governance.md`. The "New / All employee" choice is the generic `applies_to` (08.1 #13). | N-A | Belongs to another section. |
| 4 | `app-payroll-setting` (49 controls) | Covered in full in **10.8** below. | see 10.8 | Not counted here. |
| 5 | `app-help-desk-settings` (`showSLAStatus`, `isFeedbackMandatory`) | See `expense-asset-helpdesk.md`. | N-A | Belongs to another section. |
| 6 | `app-timesheet-setting` (`groups`, `settings`, `status`, `conditionalTimesheetProjects`) | See `core-access-integration.md` (projects and timesheets, HP-5). | N-A | Belongs to another section. |
| 7 | `app-eng-setting` (`points`, currency) and `app-setting` (performance: `appraisalType`, `performanceStartMonth`, `isPublishPerRatingAuto`, `allowGoalEditDuringOnlyGoalPeriod`, `isKeyResultKeyPerformanceShared`, `okrIntervalIdForCompanyScheduler`, `okrIntervalValueForCompany`, `okrIntervalIdForTeamScheduler`, `okrIntervalValueForTeam`, `okrIntervalIdForEmployeeScheduler`, `okrIntervalValueForEmployee`, `showThreeSixtyFeedbackEmployeeProfile`, `showCustomRating`, `showReverseRating`, `settings`, `status`) | See `performance-engage.md`. | N-A | Belongs to another section. |
| 8 | `app-asset-setting` (`onboardingDueDays`, `exitDueDays`, `isConditionalWorkflow`, `isRequestAssetSubtypeVisibility`, `isManagerRequestAssetSubtypeVisibility`, `isHRRequestAssetSubtypeVisibility`, `isRequestProductVisibility`, `isManagerRequestProductVisibility`, `isHRRequestProductVisibility`, `isRecoveryMandatory`); `app-expense-settings` (`isProjectBasedApproval`, `expenseExpiryPeriodType`, `expenseExpiryPeriodBasedOn`, `expenseExpiryPeriod`, `isExpenseReportSubmissionWindow`, `reportSubmissionFirstCycleStartDay`, `reportSubmissionFirstCycleEndDay`, `reportSubmissionSecondCycleStartDay`, `reportSubmissionSecondCycleEndDay`, `isMoreThanOneExpenseCycle`, `isAllowGoogleMapEdit`, `extraPercentage`, `isAllowMultipleMonthExpenseReport`, `isAllowMultipleProjectReport`, `isAllowMultipleTravelPlan`, `isFinalApprovalPaymentRequired`, `expenseCategoryBasedOn`, `isAllowMultipleCategoryReport`, `conditionalCategory`, `isCheckDuplicateReceipt`, `receiptDuplicityPercentage`, `isEditTravelDistance`, `isShowExpensePaymentDetail`, `travelCategoryBasedOn`, `isAllowMultipleTravelCategoryReport`, `travelConditionalCategory`, `backdatedTravelDays`, `isMultipleTravelRequired`, `isProjectVisibleOnTravelPlan`, `isProjectRequiredOnTravelPlan`, `isAllowAdvanceInTravelRequest`, `IsShowTravelOnAttendanceCalendar`, `isShowBackdatedTravelDelayReason`, `isRestrictOverlapTravel`, `isAllowSameEndStartDateTravelOverlap`, `isExpenseAdvanceProject`, `isAllowExceptionalExpenses`, `isAllowExpenseCoverLetterDownload`, `isAllowDuplicateInvoiceNo`); `app-recruitment-settings` (no controls) | See `expense-asset-helpdesk.md`. Recruitment is out of scope. | N-A | Belongs to another section. |

---

# 10 Payroll

> **Progress (2026-10-29, HS-BP-2 / BP-9 / BP-13; `docs/architecture/hrone-parity.md` § "Payroll → CTC formula tags,
> loans / claims / variable pay, bank and pay modes").** Built: `input` components (CTC formula tags) read by formulas
> and never paid, the structure version's CTC balancing component (remainder formula generated) and CTC mode
> (components: CTC worked out from the amounts); the loan policy's request days, requests a year, EMI share of net
> pay, exceptions to the approver, approver edits of amount / instalments / first month / interest, several guarantors
> with a limit per guarantor and acceptance; one open claim per reimbursement, claims never above bills, reminders to
> claim; variable pay plans with the manager asked on the task day and the schedule on My compensation; pay modes
> (bank transfer / cash / cheque, reimbursements apart), paying bank accounts (CA-5 payment banks), versioned
> bank-specific file layouts, the cash and cheque register, IFSC / branch display and per-bank account number rules.
> Every default reproduces today's payroll (proved by a before / after run); engine version unchanged. Rows marked
> "→ built (HS-BP-…)". Still open in these packages: salary uploads keep an annual CTC column for structures built
> from components; no bank's file format is shipped (layouts are tenant data from each bank's specification).

## 10.1 Challan (`/payroll/challan`)

Page text: "Challan — You must review all settings to enable salary process". Cards: Provident fund, Employee state insurance, Labour welfare fund and Professional tax, each "Created · DETAILS · 2 sub-branches not mapped".

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Alert "Sub-branches not mapped to some items" (Sub-branch name / State name / Status or Action) and "N sub-branches not mapped" per statute | Establishments carry `state` (`establishments`). Employees are mapped through `EmployeeEstablishmentAssignment` (`EstablishmentAssignments::resolve`). `StatutoryEngine` raises a blocking `statutory_state_missing`. `ComplianceReadiness` requires "employees explicitly assigned". There is no list of the gaps before a run. | PARTIAL | A read-only "Statutory coverage" panel on `ComplianceControlRoom` listing: locations whose employees have no establishment, establishments without a state, and statutes without an `EstablishmentStatutoryProfile` or registration. No new data. |
| 2 | Statute cards PF / ESI / LWF / PT with "Created / DETAILS" | `EstablishmentStatutoryProfileResource` (applicable per statute, registration link, settings) + `StatutoryRegistrationResource`. Legacy `CompanyStatutoryProfile`. | HAVE | — |
| 3 | Provident Fund "Add challan group" (catalogue `allowGroupInChallan`: several sub-branches on one PF challan) | The EPF ECR is produced per establishment (`compliance.return_types.EPF.scope = establishment`, `EpfReturns`). Two establishments that share one EPF code give two returns. | PARTIAL | Let one `epf_establishment_code` registration be linked from several establishments. `EpfReturns` then groups by registration, and the return's `uniqueness_key` carries the registration id. No settings. |
| 4 | ESI card (no controls). Challan format "ESI / ESI with Sub unit code" *(supplementary)* | ESI profile, rule and `EsiReturns` (layout `ESI_MC`). No sub-unit code. | PARTIAL | Registration type `esic_sub_unit` in `compliance.registration_types` (per establishment) and an ESI layout variant (versioned `statutory_export_layouts`). |
| 5 | LWF "Add slabs / NO SLAB CREATED": `slabName` | LWF values come from rule versions (`database/data/compliance/in.php`, code `LWF` per state), which are immutable, verified and viewable on `ComplianceRuleResource`. | N-A | A tenant-entered statutory slab breaks the rule that statutory values come only from rules. The tenant view is the Compliance rules screen (and 10.10 #1). |
| 6 | LWF `minAmount`, `maxAmount` ("Minimum / Maximum LWF applicability"; validation "Minimum amount should be smaller than maximum amount") | Rule parameter `wage_ceiling` only (`StatutoryEngine` LWF block). No wage bands. | PARTIAL | Extend the LWF rule shape with `bands` [[up_to, employee, employer], …], read in the LWF block. The values go into the rule pack with evidence, not into settings. |
| 7 | LWF "Employee contribution for every month" April–March: `aprContribution`, `mayContribution`, `junContribution`, `julContribution`, `augContribution`, `sepContribution`, `octContribution`, `novContribution`, `decContribution`, `janContribution`, `febContribution`, `marContribution` | Rule `months` (deduction months) + `employee_amount`, or `employee_rate` / `employee_cap` (Haryana) | HAVE | Same amount in every listed month (states differ only by month list). |
| 8 | LWF "Employer contribution for every month" April–March: `aprEmployerContribution`, `mayEmployerContribution`, `junEmployerContribution`, `julEmployerContribution`, `augEmployerContribution`, `sepEmployerContribution`, `octEmployerContribution`, `novEmployerContribution`, `decEmployerContribution`, `janEmployerContribution`, `febEmployerContribution`, `marEmployerContribution` | Rule `employer_amount` or `employer_multiplier` | HAVE | — |
| 9 | LWF view: Inactivate / Edit | Rule versions are superseded or corrected ("Publish corrected version" on `ComplianceRuleResource`), never edited. | N-A | Immutable rule versions. |
| 10 | LWF state coverage (implied: any state can get a slab) | Rules exist for DL, GJ, HR, KA, MH, TG and TN only. | PARTIAL | Add verified rule versions for the remaining LWF states (e.g. AP, CG, CH, GA, KL, MP, OD, PB, WB). Data plus owner verification. |
| 11 | PT "Select state slab"; "No slabs created. Please click on the Create new slab button"; "Create new slab"; `professionalTaxSlabList` | PT rule per state (`PT`: `slabs`, `february_amount`, `march_amount`, `female_exempt_upto`), resolved for the establishment's or the employee's `pt_state_code`. | N-A | Statutory values only from rules. |
| 12 | PT "Minimum limit of PT" / "Maximum limit of PT" (`minLimit`, `maxLimit`; validation "Min limit should be smaller than max limit") | Rule `slabs` [[up_to, amount], …] | HAVE | — |
| 13 | PT `applicableTo` "PT slab is applicable for All / Male / Female" | Rule `female_exempt_upto` (gender read from `people.gender`) | HAVE | Female-specific bands only where the act has them (rule data). |
| 14 | PT "Employee contribution for every month" April–March: `apr`, `may`, `jun`, `jul`, `aug`, `sep`, `oct`, `nov`, `dec`, `jan`, `feb`, `mar` | One amount per slab plus February / March overrides. Half-yearly states (TN, KL) are stored as a monthly equivalent. | PARTIAL | Add an optional rule parameter `month_amounts` {month: amount} and `deduct_months` (for real half-yearly deduction) to the PT block. Rule data, not settings. |
| 15 | PT `taxRegime` "New tax regime / Old tax regime / Both" | — | N-A | PT liability does not depend on the income-tax regime. PT is deductible in the old regime only, which `TaxComputer` already handles (`$regime === 'old' ? $ptAnnual : 0`). |
| 16 | PT state coverage | Rules exist for AP, GJ, KA, KL, MH, MP, TG, TN and WB. | PARTIAL | Add verified versions for the remaining PT states (e.g. AS, BR, JH, ML, MN, MZ, NL, OD, PB, PY, SK, TR). |
| 17 | Challan generation and payment recording *(supplementary `app-generate-challan`, sidebars)*: format Challan / Excel; PF `submittedDate`, `paymentType`, `acknowledgementNo`; ESI `challanDate`, `acknowledgementNo`, `paymentType`; LWF totals; TDS `challanName`, `natureOfPayment`, `challanNumber`, `paymentType`, `challanDate`, `bank`, `branch`, `basicStatisticalReturnsCode`, `penaltyPaid`, `interestPaid`; returns `submittedDate`, `acknowledgmentNumber` | Statutory returns record `submitted_at`, `external_reference`, `acknowledged_at` and `acknowledgement_reference` (`compliance.returns.file`). The TDS quarterly return takes challans {bsr_code, deposit_date, challan_serial, amount} (`TdsQuarterlyReturns::generate`). Working sheets `pf_challan` and `esi_challan` (Compliance reports). | PARTIAL | PY-21: new table `statutory_challans` (statute, legal entity / establishment, period, challan no. / TRRN / BSR, date, payment mode, bank, amount, interest, penalty, status, attachment) with a resource under Compliance. `TdsQuarterlyReturns` picks challans from it. Permission `compliance.returns.file`. |

## 10.2 Pay group: list and "Create pay group" (`/payroll/paygroup/paygroup`)

Page text: "Pay group — Group employees based on shared payroll attributes", cards "Consultant Paygroup · 12 component(s) · Active · Company & Employee Type" and "Paygroup · 11 component(s)". The steps come from `app-pay-group-step1`, `-tag`, `-review` and `app-paygroup-formula` *(supplementary)*. In PeopleOS a pay group corresponds to a **salary structure**, which has effective-dated versions, ordered component lines and applicability.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | List with CREATE PAY GROUP; card with component count, Active and applicability | `SalaryStructureResource` (`salary_structures`: name, code, description, status; `VersionsRelationManager`) | HAVE | — |
| 2 | Stepper "Detail / Advance setting / Applicability / Advance applicability / Review" | Structure form + version draft (company, grades, `applicability`, components, parameters), then submit and approval by another person | HAVE | — |
| 3 | `name` "Name this group", `description` | `salary_structures.name`, `code`, `description` | HAVE | — |
| 4 | `salaryAnnexureTemplateId` | Letters print `{{ salary.annexure }}` in one layout (`Letters/Support/SalaryAnnexure`). | PARTIAL | Field `salary_structure_versions.annexure_letter_template_id` (nullable). `LetterContext` uses it when present. |
| 5 | "Select component you wish to include in this group (drag to change order)" | Version lines `salary_structure_components` (component, `sort_order`, `pay_nature`, `frequency`, `formula_override`) | HAVE | — |
| 6 | Formula per component "Define – FORMULA / FIXED VALUE" (`app-paygroup-formula`, label "Component") | `salary_components.calculation_method` (fixed / formula) + `formula`, a per-structure `formula_override` and version `parameters`. `FormulaEngine` with the guide (`FormulaVariables`). | HAVE | — |
| 7 | Formula label "Arrears" (arrear formula per component) | No revision arrears. `is_arrear_eligible` is stored but never read (PY-09). | MISSING | Not a second formula. A `RevisionArrearInput` (`PayrollInputSource`) pays the difference of the same formulas for months already finalized when a back-dated compensation becomes effective. It uses only components with `is_arrear_eligible`. |
| 8 | Advance setting "Do you want to enable FBP on this pay group? Yes / No" | — | MISSING | PY-03 (HP-3): version field `fbp_enabled` (bool, default false), component FBP fields (10.3 #33–35), table `fbp_declarations` (employee, FY, component, amount, status) with an approval source. Payroll pays the declared split through the compensation values. |
| 9 | Second "Yes / No" in Advance setting (label not captured; the catalogue lists `partOfPFLimitGross`, `partOfPFLimitPaycodes`) *(inferred: which components count towards the PF wage limit)* | PF wages are the components with `pf_applicable`, capped at the rule ceiling when the establishment or the employee restricts (`StatutoryEngine::restrictPf`). | PARTIAL | Only if confirmed: version field `pf_limit_component_ids`, the subset counted up to the ceiling. |
| 10 | "Select formula tags that you want to use to auto compute CTC for this pay group" | — | MISSING → built (HS-BP-2) | See 10.4. Include the `input` components in the version. |
| 11 | "Select out of CTC allowances that you want to enable for this pay group" | Add components with `include_in_ctc = false` to the version. | HAVE | — |
| 12 | "Select the salary component you want to use for ctc balancing" (catalogue `partOfCtcBalancerPaycode`, `isCtcBalancerEnabled`) | By convention a balancing formula (`SPECIAL = max(0, ctc_monthly − basic − hra − conv − pf_employer)`). `StructureFormulas::preview` warns when the breakup differs from CTC. | PARTIAL → built (HS-BP-2) | Version field `balancing_component_id`. `StructureFormulas::check` refuses a draft whose breakup cannot balance and can generate the remainder formula for that component. |
| 13 | Applicability "Company & Employee Type" + Advance applicability | `salary_structure_versions.company_id`, `grade_ids` and `applicability` {position column: ids} (designation, level, department, location, employment type, category, work mode, cost centre …). `mismatch()` is checked at proposal. | HAVE | — |
| 14 | Review | Draft → submit → approve (≠ preparer), checksum; immutable once approved | HAVE | — |
| 15 | Pay group templates (catalogue: `salaryTemplateName`, `taxTemplateName`, `reimbursementTemplateName`, `appointmentTemplateName`, `revisionTemplateName`, `contractTemplateName`) | One payslip layout. Letters are picked by hand. | MISSING | Version field `templates` JSON {payslip_layout, appointment, revision, contract → letter_template_id}. Read by the automatic letters (WF-01 / PY-28) and by `Payslips`. |
| 16 | Pay group as the unit a payroll run processes (PY-22) | Runs are per company and month (`payroll_periods.company_id`, one open run per period). | MISSING | Optional run population filter `payroll_runs.salary_structure_ids` (`PayrollRuns::populationQuery`). Several runs per period are allowed when their populations do not overlap. |
| 17 | Edit disclaimer: "changes will come in effect for all the transaction done after this point … salary processed / slip generated will have no effect" | Finalized payroll is immutable. Structure versions are effective-dated and frozen once approved. Component edits are audited and medium-risk configuration (`configuration` risk map → approval when `configuration.approval` is on). | HAVE | — |
| 18 | Captcha verification ("Enter the letters as they are shown") | Audit reason plus optional configuration approval instead | N-A | PeopleOS protects risky changes with reasons, configuration approval and separation of duties, not a captcha. |
| 19 | "Employees' CTC mapped to this component will become inconsistent … Download the list of employees (Download Excel)" | `PayrollRuns::finalize` refuses entries whose compensation fingerprint changed. No list of the employees on a component or structure. | PARTIAL | Action "Employees using this" on the SalaryComponent and SalaryStructure resources: an export of active `employee_salary_assignments` by structure or component (`compensation.export`, audited). |

## 10.3 Pay component (create / edit salary component)

The list shows 30 components (Basic, Bonus, Child education/hostel, Clearance, Conveyance, DA, Exgratia, Fundraiser, Gratuity, Hold release salary, HRA, Incentive, Leave encashment, Loan, Mid month increment, Notice amount / recovery, NPS salary, Other allowance / deduction, Overtime, Referral bonus / fees, Salary advance, Statutory bonus, TDS ×3). Each card shows TAXABLE / PARTIALLY TAXABLE / NON TAXABLE, FIXED / VARIABLE and in / not in CTC. The steps are Detail / Statutory / Tax / Review.

PeopleOS: `SalaryComponentResource`, which edits these columns of `salary_components`: name, code, type, classification, calculation_method, formula, taxable, pf_applicable, esi_applicable, include_in_ctc, include_in_gross, is_recurring, is_proratable, is_arrear_eligible, pay_on_claim, sort_order and status.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | List "Pay component · 30 Items", search, "Download"; tax badge TAXABLE / PARTIALLY TAXABLE / NON TAXABLE | Table: name, code, type, method, formula, taxable, PF, ESI, prorate, statutory, status; type filter. There is no partially-taxable state and no export. | PARTIAL | A "Tax treatment" column derived from #29–30 and a table export action. |
| 2 | Stepper Detail / Statutory / Tax / Review | One form with "Component" and "Properties" sections | HAVE | — |
| 3 | `payDescription` "Component name" | `name` (+ `code`, used in formulas) | HAVE | — |
| 4 | Placeholder "Component type" (head: basic, HRA, allowance, loan …; catalogue `headName`, `headCode`) | `classification` (`peopleos.payroll.classifications`, incl. nps_employee and nps_employer) | HAVE | — |
| 5 | `payType` "Addition / Deduction" | `type`: earning / deduction / employer_contribution / reimbursement | HAVE | — |
| 6 | `partOfCostToCompany` "Set this component as part of CTC" | `include_in_ctc` | HAVE | — |
| 7 | `partOfGross` "Set this component as part of gross" | `include_in_gross` | HAVE | — |
| 8 | `payWithSalary` "Want to pay this component in salary" | `is_recurring` (off = paid only through adjustments) and `pay_on_claim` for reimbursements | HAVE | — |
| 9 | `variablePayCode` "Nature of payment: Fixed / Variable" | `salary_structure_components.pay_nature` (descriptive only). `VariablePayRequests` accept any earning component. | PARTIAL | Component field `pay_nature` (fixed (default) / variable). When any variable component exists, `VariablePay::request` accepts only variable ones. |
| 10 | `presentDependent` "Prorate as per paid days" | `is_proratable` | HAVE | — |
| 11 | `arrearApplicable` "Arrear applicable" | `is_arrear_eligible` stored but not read | PARTIAL | Read by `RevisionArrearInput` (10.2 #7). |
| 12 | `joiningMonthProcess` "Do you want to process this in joining month?" | Always paid (prorated) in the joining month | MISSING | `pay_in_joining_month` (bool, default true). `PayrollCalculator` skips the component when the eligibility window starts after the period start and the flag is off. |
| 13 | `processDuringSettlement` "Do you want to process this in settlement month?" | Always paid in the exit month | MISSING | `pay_in_exit_month` (bool, default true). Skipped by `PayrollCalculator` in the exit month and left out of `FinalSettlements`. |
| 14 | `partOfWage` (Code on Wages "wages") | Taken from classification basic and DA (`peopleos.payroll.guardrails.wage_share.classifications`) | PARTIAL | `part_of_wages` (bool, default = classification in basic or DA). Read by the guardrail, `GratuityEntitlements` and `BonusRegister`. |
| 15 | `isEmployeeAccess`, `isManagerAccess`, `isHRAccess` (who may raise a request on this component) | `payroll.request` lets employees request variable pay and claims; `payroll.manage` for HR | PARTIAL | `requestable_by` (multiselect employee / manager / hr, default all three = today). Checked in `VariablePay::request` and `ReimbursementClaims::claim`. |
| 16 | `documentRequiredOnRequest` | One setting for all claims: `payroll.claims.attachment_required` | PARTIAL | `attachment_required` (nullable bool, null = the setting). |
| 17 | `prorateOnJoinAndLeave` | Covered by the same `is_proratable` flag as LOP | PARTIAL | `prorate_on_join_exit` (bool, default = `is_proratable`), used for the joining and exit segments in `PayrollCalculator::units`. |
| 18 | Edit dialog: joining / settlement month, prorate, arrear, show on payslip / register, rate, CTC, gross, pay with salary, PF, PT, ESI (wages and eligibility), bonus, LWF, gratuity, encashment | Same columns as the rows above and below; edits audited | PARTIAL | Follows from the rows above and below. |
| 19 | Translate dialog: `translations`, `language`, `translatedName` | — | MISSING | `salary_components.translations` JSON {locale: name}, used by the payslip and letters (10.6). |
| 20 | Step 2 `providentFundGrossPart` "Provident fund" | `pf_applicable` | HAVE | — |
| 21 | `partOfProfessionalTax` "Professional tax" (under "Select whichever is applicable on this component") | PT is on `PayrollComputation::gross()` (`include_in_gross`) | MISSING | `pt_wages` (bool, default = `include_in_gross`). Add `PayrollComputation::ptWages()` and use it in the PT block. |
| 22 | `stateInsuranceGrossPart` "Employee state insurance" | `esi_applicable` (ESI wages) | HAVE | — |
| 23 | `partOfStateInsuranceRate` "Include to check limit for ESI applicability" | Eligibility is checked on the same `esi_applicable` full-month sum | PARTIAL | `esi_eligibility_wages` (bool, default = `esi_applicable`), used for the ceiling check only. |
| 24 | `bonusApplicable` "Bonus" | `BonusRegister::WAGE_CLASSES` (classifications) | PARTIAL | `bonus_wages` (bool, default from classification). |
| 25 | `bonusForCalculation` "Include to check limit for bonus applicability" | The eligibility ceiling (BONUS rule) is checked on the same wages | PARTIAL | `bonus_eligibility_wages` (bool, default = `bonus_wages`). |
| 26 | `lwfGrossPart` "Labour Welfare Fund" | LWF is on gross | MISSING | `lwf_wages` (bool, default = `include_in_gross`). Add `PayrollComputation::lwfWages()`. |
| 27 | `gratuityApplicable` "Gratuity" | Gratuity wages by classification (`GratuityEntitlements`) | PARTIAL | `gratuity_wages` (bool, default = `part_of_wages`). |
| 28 | `leaveencashmentApplicable` "Leave encashment" | Basis settings `payroll.encashment_basis` and `exit.encashment_basis` (basic / basic_da / gross) | PARTIAL | `encashment_wages` (bool) plus a new option `flagged` for both basis settings (default unchanged). |
| 29 | `showOnPaySlip` "Show on salary slip Yes / No" | `Payslips::generate` prints every line | MISSING | `show_on_payslip` (bool, default true). Statutory lines are always shown. |
| 30 | `showOnSalaryRegister` "Show on salary register" | `WageRegister` and `PayrollComponentsDataset` show every paid line | MISSING | `show_in_register` (bool, default true). |
| 31 | `paySlipOrder` "Salary register sort order" ("Even if show on salary register is No, then also we need to specify salary register order as its used in reports to sort components") | `sort_order` | HAVE | — |
| 32 | `partOfRate` | `WageRegister` "rate" = full-month amounts of the earnings | PARTIAL | `part_of_rate` (bool, default true). |
| 33 | `createInboxTask` (monthly input task for this component) | — | MISSING | `monthly_input_task` (bool, default false). A monthly job asks HR or the manager for the month's amount, which becomes a `PayrollAdjustment`. |
| 34 | Loan type fields: `loanEligibilityCriteria`, `maxEmi`, `isRequestCountRestricted`, `loanRequestCountLimit`, `isExceptionAllow`, `loanType`, `isLoanTypeVisible`, `isLoanRequestRestricted`, `loanRequestRestrictedStartDay`, `loanRequestRestrictedEndDay`, `interestRate` | Loans are kept out of components: `loan` policy type (enabled, allowed_types, max_amount, max_installments, interest_rate, min_service_months, max_active, guarantor_required, disbursement) and `loans.types` | PARTIAL → built (HS-BP-9) | Add these loan policy fields: `max_emi_percent_of_net` (0 = off), `request_day_from` / `request_day_to` (0 = off), `max_requests_per_year` (0 = off), `allow_exception` (bool, default false: a request beyond the limits goes to the approver flagged). |
| 35 | Perquisite fields: `perquisiteApplicable`, `perquisiteComponent` ("Perquisite pay code"), `perquisitesSubtype`, `autoCalculate`, `engineCapacity`, `driverProvided`, `propertyCategory`, `perquisitesValue` | Perquisites are `tax_income_entries` (kind perquisite, `perquisite_type` from `compliance.perquisite_types`, amount, recovered), entered by payroll (PY-17). No automatic valuation. | PARTIAL | Component field `perquisite_type`: paying the component records the perquisite. Rule-3 valuation (car by engine capacity and driver, accommodation by city) is statutory, so it goes into TDS rule params `perquisite_valuation`, used by `TaxIncomeEntries::autoValue()`. |
| 36 | `payOnClaim` | `pay_on_claim` (PY-02 ledger and claims) | HAVE | — |
| 37 | `specifiedDays`, `restrictClaimWindow` (claim window) | — | MISSING | `claim_window_days` (number, 0 = none): the bill date must fall within N days. |
| 38 | `claimLimit` | Limited only by the accrued balance (`payroll.claims.allow_beyond_balance`) | PARTIAL | `claim_limit_per_claim` (number, 0 = none). |
| 39 | `isPlanLeavesRequired`, `leavesLimit` (LTA: leave must be taken for the claim) | No LTA (PY-04) | MISSING | With LTA claims (HP-3): component `lta` + `lta_min_leave_days`. `ReimbursementClaims` checks approved leave over the travel dates. |
| 40 | Step 3 `nontaxable` | `taxable` (reimbursement type is never taxed) | HAVE | — |
| 41 | `taxExemptionRegime` (regime in which the exemption applies) | Non-taxable is non-taxable in both regimes | MISSING | `exempt_in_regimes` (multiselect old / new, default both = today). `TaxComputer` reads the declaration regime. |
| 42 | `excludeExemptFromChallanIncome` | — | MISSING | Flag read by `TdsQuarterlyReturns` when it reports exempt allowances apart from salary. |
| 43 | `taxDeducted` (label not captured) *(inferred: tax deducted on payment of this component)* | TDS is projected over the year | MISSING | Unclear. Confirm before building. |
| 44 | `exemptIncomeForBillSubmitted` (exempt only against bills) | Pay-on-claim reimbursements are untaxed when claimed. Unclaimed balance at year end is open (PY-02). | PARTIAL | `exempt_only_against_bills` (bool). A year-end job taxes the unclaimed ledger balance in March payroll. |
| 45 | `partOfFlexiblePlan`, `minimumFlexiblePlan`, `maximumFlexiblePlan` | — | MISSING | PY-03: `fbp_enabled`, `fbp_min`, `fbp_max` (amount or % of CTC) with the FBP declarations of 10.2 #8. |
| 46 | Rounding (catalogue `rounding`) | Formula functions (`round`, `round_to`, `ceil_to`). Fixed amounts are not rounded. | PARTIAL | `rounding` (select none (default) / nearest_1 / up_1 / nearest_10), applied after proration in `PayrollCalculator`. |
| 47 | Mid-month increment (catalogue `partOfMidmonthIncrement`; seeded "Mid month increment" component) | `PayrollCalculator` splits the month into compensation segments (effective-dated) | HAVE | — |

## 10.4 CTC formula tags (`/payroll/paygroup/ctc-formula`)

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | "CTC formula tags — create/modify formulas to calculate total employment costs": Formula Tag 1 … 6, Rename, UPDATE (`fieldsName[]`, `displayName`; catalogue `sequenceNo`, `tagType`, `columnName`, `displayName`, `originalName`, `amount`) | Formulas can use CTC, employee attributes (`FormulaVariables::EMPLOYEE`), structure `parameters` and components above. A per-employee value exists only as a fixed component, which is also paid. | MISSING → built (HS-BP-2) | PeopleOS style, with no fixed slots: a new calculation method `input` ("Employee input used by formulas, never paid") in `peopleos.payroll.calculation_methods`. Its value is captured on the compensation change like a fixed amount (`component_values[CODE]`) and is available to formulas as its lowercase code. It is skipped as a line in `PayrollCalculator`, `StructureFormulas::preview` and `Payslips`. The component name is the "rename". |
| 2 | Per-pay-group selection of tags | — | MISSING → built (HS-BP-2) | Include the input components in the structure version (10.2 #10). |

## 10.5 Out of CTC allowances (`/payroll/paygroup/ctc-allowances`)

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | "Out of CTC allowances": Allowance 1 … 18, Rename, UPDATE (`fieldsName[]`, `displayName`) | Any salary component with `include_in_ctc = false`, fixed (per-employee amount) or formula. Its name is the label. `StructureFormulas` keeps it out of the CTC breakup. | HAVE | Optional: a filter "Outside CTC" on `SalaryComponentResource`. |
| 2 | Enable per pay group | Add it to the structure version | HAVE | — |

## 10.6 Payroll glossary (`/payroll/paygroup/add-language`)

Page text: "Configure different types of languages used in the system payroll. Note: applicable only for payslip and letters". Add language; cards Hindi, Odia, Marathi, Kannada, Malayalam, Tamil, Gujarati and Telugu, each "Translated components 106/106".

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Language list with translated-component counts, "Add language" | No translation of payroll terms. Payslips are English (`Payslips::generate`). There is no employee language preference (`people` has no language column; `experience_preferences.preferences` is JSON). | MISSING | New table `payroll_glossary_entries` (locale, original, translated, status) with a resource "Payroll glossary" (Setup → Payroll link). Add a payslip language: employee preference key `preferences.payslip_locale` plus setting `payroll.payslip.languages` (multiselect, default [] = English only). |
| 2 | Dialog: "Select language", "Search original word", "Enter original word", "Enter translated word" | — | MISSING | Same resource. `Payslips::generate` stores the translated labels in the snapshot, so the payslip stays immutable. `LetterContext` gets `{{ glossary.<word> }}`. Component names come from `salary_components.translations` (10.3 #19). |

## 10.7 Payroll settings page (`/payroll/setting`, `app-payroll-settings`)

Page text: "Payroll settings — You can configure enterprise level settings for payroll", with the menu Increment type / New join salary declaration / Salary Bank / Statutory Skills.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Page "enterprise level settings for payroll" | Setup centre → Payroll (`SetupCentre`, `config/peopleos_setup.php` `payroll`): 19 typed settings, loan policy type and links | HAVE | New settings below are added there. |
| 2 | Increment type: Increment type 1 … 5, Rename, UPDATE (`allowanceName[]`, `displayName`; catalogue `incrementTypeMasterName`, `sequenceNo`, `originalName`, `isActive`) | Compensation change types are config (`compensation.change_types`: annual_increment, promotion, market_adjustment, …). The tenant cannot rename or switch them off. | PARTIAL | Setting `compensation.change_type_labels`. This needs a new Setup type `labels` (key → label; keys from `compensation.change_types`; default {} = config labels) in `SetupCatalogue` and the Setup centre blade. Add `compensation.change_types_enabled` (multiselect, default all). Read wherever change types are labelled (CompensationChange resources, letters). |
| 3 | "New join salary declaration" (no controls captured) | New-joiner pay is a `CompensationChange` of type `hire` (proposed, reviewed, approved, executed) | PARTIAL | *(inferred: a declaration text for new joiners' salary)*. Setting `compensation.joiner_declaration_text` (textarea, default blank = none), shown once on MyCompensation with an audited acknowledgement. Confirm the meaning first. |
| 4 | Salary bank, pay modes (`payMode`: `online_transfer`, `cash`, `cheque`; catalogue "Pay modes: Cash, Cheque, Online transfer") | Everyone is paid by bank transfer: `BankFile` exports the primary account of every entry with net > 0 | MISSING → built (HS-BP-13) | Setting `payroll.pay_modes` (multiselect online_transfer / cash / cheque, default [online_transfer]). Employee field `employees.pay_mode` (default online_transfer). `BankFile` exports online only. Add a "Cash and cheque payments" register (dataset + template). |
| 5 | `configureSalaryBank` (company debit bank; catalogue `bankBranch`, `bankIndianFinancialSystemCode`) | — (PY-24) | MISSING → built (HS-BP-13) | New table `company_bank_accounts` (company, bank, branch, IFSC, account no. encrypted, file layout, is_default) with a resource. `BankFile` header and bank-specific layouts are versioned like `statutory_export_layouts`. |
| 6 | `showBankIndianFinancialSystemCodeBranchHRDesk` (show IFSC and branch on the HR desk) | `employee_bank_accounts.ifsc`, `branch_name` shown masked on the employee page | PARTIAL → built (HS-BP-13) | Setting `payroll.bank.show_ifsc_branch` (bool, default true). |
| 7 | Statutory skills: "Add Statutory Skill", `skillName`, Search | — (`skills` are competencies, not minimum-wage categories) | MISSING | New table `wage_skill_categories` (code, name such as unskilled / semi-skilled / skilled / highly skilled, status) with a resource. Position or employee field `wage_skill_category_id`. Used by the `MIN_WAGE` rule lookup (10.8 #11, 10.10 #4). |

## 10.8 Payroll settings panel (`app-payroll-setting`, every control)

The source is `common-settings-payroll.md`. Questions are in HROne's order; C = control name. "Setting" means a new key in `config/peopleos.php` → `settings` plus `config/peopleos_setup.php` → `payroll` (or `workforce`).

| # | HROne setting (question / control / options) | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Financial Year: "From which financial year you want to activate payroll" (UPDATE; catalogue `implementationDate`) | No go-live. A mid-year start has no opening year-to-date figures (PY-25). | MISSING | Setting `payroll.go_live_month` (month, default null = none). Table `payroll_opening_balances` (employee, FY, classification / component, amount, tds, pf, pt) read by `StatutoryEngine::yearToDate` and `TaxComputer`, plus a PayrollUploads type `opening_ytd`. |
| 2 | PF: "Do you want to process PF on Special Allowance? Yes / No" (C `specialAllowancePartOfProvidentFund`) | `salary_components.pf_applicable` on SPECIAL ("PF wages" toggle; default off) | HAVE | — (the Code on Wages add-back of allowances above 50% is a separate rule-driven item). |
| 3 | "Do you want to overwrite employer PF contribution rate at employee level?" (C `overwritePFEmployeeLevel`) | `employee_pf_options.wage_basis` (ceiling / actual) overrides the establishment basis for **both** shares. VPF via `vpf_mode` / `vpf_value` (`PfOptionResource`, `PfOptionImports`). Rates are the EPF rule's. | PARTIAL | Add `employee_pf_options.employer_wage_basis` (null = same as `wage_basis`), so the employer pays on the ceiling while the employee pays on actual (or the reverse). Rates stay with the rule, so there is no rate field. |
| 4 | "How do you want to process PF for September, 2026? PF Wage Ceiling / CTC" (C `effectivePFGross`) | EPF rule v2 (wage ceiling ₹25,000 from 2026-09-17) is picked by period end, so all of September uses the new ceiling. The CTC formula variable `pf_employer` is re-estimated, which shifts balancing components. There is no choice. | PARTIAL | Setting `payroll.pf.ctc_employer_pf_basis` (select `rule` (default = today) or `ctc`: hold the employer PF that balances CTC at the amount in the employee's approved compensation until the next change). Read by the `pf_employer` variable in `PayrollCalculator` and by `StructureFormulas::preview`. The statutory deduction always follows the rule. |
| 5 | (same group) C `arrearPFDeductRule` (PF on arrears) | `AttendanceArrearInput` lines carry no PF or ESI flags, so no PF is taken on arrears. | PARTIAL | Setting `payroll.pf.on_arrears` (bool, default false). When on, arrear lines get `pf_applicable` / `esi_applicable` for the PF and ESI share of the day rate (`PayrollInputs::dailyRate`). |
| 6 | CTC: 'Rename "Special Allowance" to' (C `specialAllowanceRename`) | Component name is editable (`SalaryComponentResource`); code SPECIAL stays | HAVE | — |
| 7 | "Pay arrear with the salary" (C `payArrearWithSalary`) | Attendance arrears are paid with the next salary (`AttendanceArrearInput`). Revision arrears are manual adjustments (PY-09). | PARTIAL | Setting `payroll.arrears.pay_with_salary` (bool, default true). Add `RevisionArrearInput` (10.2 #7). When off, arrears are held for an out-of-salary payment (PY-07). |
| 8 | "Do you want to show net take home to employee?" (C `showDetailsOnEmployee`) | `MyCompensation` shows the approved compensation only | MISSING | Setting `compensation.self_service.show_net_take_home` (bool, default false). MyCompensation shows an estimate from `StructureFormulas::preview`, `StatutoryEngine::contributionsOn` and the PT rule, labelled as an estimate. |
| 9 | "Do you want to allow assigning a salary structure when wages are less than exclusions?" (C `allowWagesNonCompliantSalaryStructure`) | `StructureFormulas::preview` warns when Basic + DA < 50% of gross (`payroll.guardrails.wage_share`). It never blocks, so today's answer is "Yes". | PARTIAL | Setting `compensation.wage_share_enforcement` (select `warn` (default) / `block`). `CompensationChanges::propose` and `submit` refuse in block mode. Move the 50% figure from config into a verified rule param (`WAGES.min_wage_share`), since it is statutory. |
| 10 | "Do you want to show wages and exclusions to Employee on profile CTC page?" (C `showWagesExclusionsToEmployee`) | — | MISSING | Setting `compensation.self_service.show_wage_split` (bool, default false). MyCompensation shows wages vs exclusions and the add-back. |
| 11 | "Do you want to ensure that employee is not assigned Wages less than minimum wages?" (C `ensureMinimumWage`; catalogue `allowMinWagesSalaryStructure`) | No minimum-wage data (the BONUS rule notes "minimum wage is not held by PeopleOS") | MISSING | Rule pack code `MIN_WAGE` (state, zone, scheduled employment, skill category, from date; verified with evidence). Skill category from 10.7 #7. Setting `compensation.minimum_wage_check` (select off (default) / warn / block), checked in `CompensationChanges` and as a payroll exception `below_minimum_wage`. |
| 12 | "Do you want to ensure that employee is not assigned basic less than 50% of CTC?" (C `ensureBasicWageLimitInCostToCompany`) | Advisory 50% check on **gross** (see #9) | PARTIAL | Setting `compensation.wage_share_basis` (select `gross` (default) / `ctc`), with the enforcement of #9. |
| 13 | "Do you want to show historical CTC to employees?" (C `historicalCostToCompany`) | MyCompensation always shows past rows when `compensation.self_service` is on | PARTIAL | Setting `compensation.self_service.show_history` (bool, default true). |
| 14 | C `isFormSixteen` (label not captured) *(inferred: show Form 16 to employees)* | `TdsCertificateResource` (Form 130/16) needs `compliance.returns.view` or `compliance.tds.manage`. Employees cannot see their own. | MISSING | Setting `payroll.tax.form16_self_service` (bool, default false) plus a "My tax certificates" page under Me (own `tds_certificates`, audited download). |
| 15 | "Do you want to show all the component in register?" (C `showAllComponentsInSalaryRegister`) | The `WageRegister` "Components" sheet lists only the components paid | PARTIAL | Setting `payroll.register.all_components` (bool, default false) adds zero columns for every active component of the run's structures. |
| 16 | "Do you want to disable CTC and auto calculation of special allowance?" (C `isCosttoCompanyNotApplicable`) | Every compensation carries `ctc_annual`. A structure of fixed components works, but the breakup is still checked against CTC. | PARTIAL → built (HS-BP-2) | Version field `salary_structure_versions.ctc_mode` (`ctc` (default) / `components`). In components mode `ctc_annual` is derived at proposal from the include-in-CTC amounts plus employer PF, and there is no balancing warning. |
| 17 | "Do you want to show remark and attachment option to employee on profile CTC page?" (C `showRemarkAttachmentCtc`) | — | MISSING | Setting `compensation.self_service.show_notes` (bool, default false). Shows the change `reason` (never `internal_notes`) and the attachments of #18. |
| 18 | "Do you want to make attachment mandatory while assigning salary structure?" (C `attachmentMandatorySalaryStructure`) | Compensation changes have no attachments | MISSING | `compensation_changes.attachments` JSON (private disk, like reimbursement bills; DataInventory sensitive). Setting `compensation.change_attachment_required` (bool, default false), enforced in `CompensationChanges::propose` and `submit`. |
| 19 | "Do you want to make comment mandatory while assigning salary structure?" (C `commentMandatorySalaryStructure`) | Always required: "A compensation change needs a reason." (`CompensationChanges`) | HAVE | Kept always on; it is part of the audit trail. |
| 20 | "Do you want to show perquisite details to employee & manager on profile CTC Page?" (C `showPerqToEmployeeAndManager`) | Perquisites (`tax_income_entries`) are visible to payroll only | MISSING | Setting `compensation.self_service.show_perquisites` (bool, default false). MyCompensation and the team compensation view list the FY's active perquisite entries. |
| 21 | "Do you want to hide employee statutory details from employee & manager on profile CTC page?" (C `hideStatutoryDetailsFromEmployeeCTC`) | MyCompensation shows no statutory amounts. The behaviour is fixed as "hidden", with no switch. | PARTIAL | Setting `compensation.self_service.show_statutory` (bool, default false). It matters once #8 shows PF and ESI. |
| 22 | 'Do you want to show "Salary Insight" option to employees under the CTC section?' (C `showSalaryInsightToEmployee`) | — | MISSING | Setting `compensation.self_service.show_insight` (bool, default false). Year-on-year change and the range band (`CompensationRanges::position`, band only, never others' pay). |
| 23 | Contract: "How many days before contract expiry do you want to initiate contract renewal card?" (C `daysBeforeContractRenew`) | No contract end dates (WF-05) | MISSING | New table `employee_contracts` (employee, type, start, end, renewal_of_id, status, letter_id) with a resource. Setting `workforce.contract.renewal_lead_days` (number, default 30). A daily job raises `employee.contract_expiring` (new trigger event) and a renewal task. |
| 24 | Investment: "Do you want to show tax estimator on Investment declaration?" (C `showTaxCalculatorOnInvestment`) | The last step of `DeclareInvestments` shows how far each section lowers taxable income. There is no tax amount and no old-vs-new comparison. | PARTIAL | Setting `payroll.tax.show_estimator` (bool, default false). Annual projection for both regimes from `TaxComputer` (current compensation + YTD + declaration). No new statutory values. |
| 25 | "Do you want it mandatory for an employee to submit proof at time of confirm investment declaration?" (C `mandatoryInvestmentProof`) | Proofs are a separate step in the proof window (`InvestmentProofs::submit`, `payroll.tax.proof_from` / `proof_to`). Submitting a declaration never asks for files. | PARTIAL | Setting `payroll.tax.proof_required_on_submit` (bool, default false). When on and the proof window is open, `InvestmentDeclarations::save($submit)` refuses items without `proof_files`. |
| 26 | "Do you want to enable landlord PAN verification?" (C `landlordPanVerification`) | `payroll.tax.landlord_pan_required` (PAN required, format only) | PARTIAL | Verification needs an external PAN-verification provider (EXT, owner decision). Build the seam now: contract `Compliance/Contracts/PanVerifier` with a null implementation, plus setting `payroll.tax.landlord_pan_verify` (bool, default false). |
| 27 | "Can approver edit investment declaration?" (C `investmentEditAllowed`) | Payroll (`payroll.manage`) can always edit in-scope declarations (`DeclareInvestments ?employee=`; `TaxSettings`: "Payroll can always change them") | PARTIAL | Setting `payroll.tax.payroll_may_edit` (bool, default true). When off, payroll only verifies or rejects (`InvestmentDeclarations::canEdit`). |
| 28 | "Restrict investment declaration request between calendar day of the month" From / To (C `salaryProcessDays`, `restrictedEndDay`) | A date range: `payroll.tax.declaration_from` / `declaration_to` | PARTIAL | Settings `payroll.tax.declaration_day_from` / `declaration_day_to` (number 0–31, 0 = off, default 0), combined with the date range in `TaxSettings::declarationOpen`. |
| 29 | "Do you want to allow employee to change the tax regime?" (catalogue `allowChangeTaxregime`) | `payroll.tax.regime_locked_after_submit` (the inverse; default false = may change until verified) | HAVE | — |
| 30 | "Do you want to assign a task to employee to submit investment declaration?" (C `assignInvestmentTask`) | — | MISSING | Policy type `tax_declaration` (fields `declaration_task` bool, `proof_task` bool). Its assignment rules decide who gets the task, which also covers #55. A daily command `peopleos:payroll:declaration-tasks` creates tasks when a window opens for those without a submitted declaration or proof. |
| 31 | Form 12BB display: "Do not show / Show only after confirm investment / Show on both confirm and propose investment" (C `showForm12bb`) | The Form 12BB action on `TaxDeclarationResource` is visible to anyone who sees the declaration, at any status (`Form12bbController`) | PARTIAL | Setting `payroll.tax.form12bb_visibility` (select `always` (default = today) / `after_proofs` / `hidden`; payroll always sees it). |
| 32 | Loan: "Can loan be edited by approver?" (C `loanEdit`) | `Loans::approve(note)` approves as requested | MISSING → built (HS-BP-9) | Loan policy field `approver_may_edit` (bool, default false). `approve` accepts principal, instalments and first month within the policy, re-plans the schedule, and audits before → after. |
| 33 | "Can dispense loan interest be edited by Task Owner?" (C `loanInterestEdit`) | The interest rate is copied from the policy at request time | MISSING → built (HS-BP-9) | Loan policy field `interest_editable` (bool, default false). Rate input on approve or disburse. |
| 34 | "Do you want to enable mapping of guarantor while requesting loan?" (C `enableLoanGuarantor`; catalogue `enableLoanGuarantorFeature`) | Loan policy `guarantor_required`; `Loans::request($guarantor)` | HAVE | — |
| 35 | "How many guarantors can be mapped by an employee?" (catalogue `guarantorRequiredCount`) | One `loans.guarantor_employee_id` | MISSING → built (HS-BP-9) | New table `loan_guarantors` (loan, employee, status accepted / declined, decided_at); keep the column for existing loans. Loan policy field `guarantors_required` (number, default 1). |
| 36 | "For how many employees, a single guarantor can take guarantee of?" (catalogue `guarantorCountLimit`) | — | MISSING → built (HS-BP-9) | Loan policy field `max_guarantees_per_guarantor` (number, 0 = no limit, default 0), checked in `Loans::request` against open loans. |
| 37 | Settlement: "Tax free leave encashment days, at settlement" (C `leaveCashDays`) | `FinalSettlements` pays the `ENCASH_*` lines fully taxable. There is no s.10(10AA) exemption. | MISSING | Statutory, so no setting. Add TDS rule params `leave_encashment_days_per_year` and `leave_encashment_exempt_limit` (new verified version). `TaxComputer` exempts exit encashment up to the lesser limits, and `FinalSettlements` records the basis. |
| 38 | "Lifetime leave exemption limit, at settlement" (C `leaveExemption`) | — | MISSING | The limit is a rule param (#37). Add a per-employee "exemption already used" amount in `employee_tax_declarations.previous_employer` (encrypted JSON). |
| 39 | "Settlement trigger (Days) after relieving date" (C `settlementTriggerDays`) | The settlement is created when the last clearance stage clears (`Exits::clearStage` → `FinalSettlements::ensure`) | PARTIAL | Setting `exit.settlement_trigger_days` (number, default 0 = today: on clearance). A value above 0 makes `Exits::tick` create and calculate the settlement that many days after `last_working_day`, flagged if clearance is pending. |
| 40 | "Do you want user to auto approve the settlement import request?" (C `submitApproveSettlementImportRequest`) | No settlement import | MISSING | PayrollUploads type `settlements` (a `RowImports` subclass, `payroll.manage` + `exit.settle`). Setting `exit.settlement_import_auto_approve` (bool, default false: imported settlements stay calculated for a second person to approve). |
| 41 | "By which mean do you want to share employee settlement slips generated using settlement import?" (C `shareSettlementSipBySettlementImport`) | No settlement slip | MISSING | Settlement slip (HTML snapshot like the payslip) plus setting `exit.settlement_slip_share` (select `none` (default) / `portal` / `email`). |
| 42 | Variable: "On what day of the month, do you want to assign variable payment task in inbox?" (C `dayCreateVariablePaymentPlanInboxRequest`) | `VariablePayRequests` are ad hoc. There are no plans and no scheduled tasks. | MISSING → built (HS-BP-9) | New table `variable_pay_plans` (employee, component, schedule rows: month + target). Setting `payroll.variable.task_day` (number, 0 = off, default 0). A monthly command asks the manager for the payout, which becomes a `VariablePayRequest`. |
| 43 | "Do you want to show variable payment schedule to employee & manager on profile CTC page?" (C `showVariableToEmployeeAndManager`) | — | MISSING → built (HS-BP-9) | Setting `compensation.self_service.show_variable_plan` (bool, default false). Needs #42. |
| 44 | Reimbursement: "Do you want to assign a task to employee to claim reimbursement?" (C `assignReimbursementTask`) | — | MISSING → built (HS-BP-9) | Setting `payroll.claims.reminder_task` (select off (default) / monthly / quarter_end / year_end). The job tasks employees whose pay-on-claim balance is above 0. |
| 45 | Miscellaneous: "Fixed bonus?" (C `fixedBonus`) | A BONUS component can be paid monthly. The statutory bonus register is provisional (PY-19; the BONUS rule awaits the owner). | PARTIAL | Setting `payroll.bonus.mode` (select `annual` (default) / `monthly_fixed`). Monthly bonus-classified payments count as bonus paid in `BonusRegister`. |
| 46 | "Do you want to allow users to submit bills for reimbursements in case of a new tax regime?" (C `hideNewRegimeReimbursementBillRequest`) | `ReimbursementClaims::claim` ignores the regime | MISSING | Setting `payroll.claims.new_regime_bills` (select `allow` (default) / `block`). Uses `exempt_in_regimes` (10.3 #41). |
| 47 | "Do you want to publish password protected pay slips?" (C `slipPassword`) | Payslips are on-screen snapshots. There is no PDF library in `composer.json` (PY-23). | MISSING | Choosing a PDF library with encryption is a dependency decision for the owner (EXT). Setting `payroll.payslip.password` (select `none` (default) / `pan_dob`), plus `Payslips::pdf()`. |
| 48 | "Do you want to automate contract letter generation?" (C `isGenerateContractLetter`) | Letters by hand. The workflow `document` node is a stub (WF-01, WF-05). | MISSING | With `employee_contracts` (#23) and the `document` node wired to `Letters::generate`: setting `letters.auto.contract` (bool, default false). |
| 49 | "Do you want to generate appointment letter for contractual employees?" (C `isGenerateAppointmentLetter`) | Letter type `appointment` exists and can be generated by hand for anyone | PARTIAL | Setting `letters.auto.appointment_employment_types` (multiselect of employment types, default [] = none). On `employee.joined` a draft letter is created. |
| 50 | "Do you want to allow to unrelease the salary even after challan generation?" (C `allowPaymentAfterChallan`) | A finalized run is never reopened (`PayrollRuns`, model guards). Corrections go to an open month as arrears or recoveries. | N-A | By design: statutory returns and audit are tied to the finalized run. |
| 51 | "Do you want to process Employee NPS in the system?" (C `processEmployeeNPScheme`) | Components classified `nps_employee` / `nps_employer` (formula, e.g. `basic * 0.10`). `TaxComputer` counts 80CCD(1) / 80CCD(2). Employer NPS can be entered on the declaration (`TaxDeclarationResource`). | HAVE | No switch needed: NPS is on when such a component is in the structure. Employee elections and PRAN are in 10.11. |
| 52 | "Do you want the Tax Regime popup to be compulsory for users to complete?" (catalogue `taxRegimePopupMandatory`) | The regime is chosen in the declaration; the default is new | MISSING | Setting `payroll.tax.regime_prompt` (select `off` (default) / `prompt` / `compulsory`). A banner on Home / My HR saves a draft declaration with the regime. `compulsory` keeps it in the way of the Me pages until answered. |
| 53 | "Do you want to show pay on default components in the reimbursement pay slip and reimbursement settlement slip?" (C `showPayByDefaultOnReimbursementReport`) | Reimbursements not paid on claim are ordinary payslip lines. There is no separate reimbursement slip. | PARTIAL | Only matters if a separate reimbursement slip is built (with #41). Otherwise nothing to do. |
| 54 | Header controls "Financial Year / UPDATE / PF / CTC / Contract / Investment / Loan / Settlement / Variable / Reimbursement / Miscellaneous" (group headings, Yes / No toggles) | Setup centre groups by module, typed fields | HAVE | — |
| 55 | "Exclude Employees / Add bulk employees / Clear all" (catalogue `excludedInvestmentEmployee`, `…By`, `…On`; `app-bulk-employee-search` `employeeCode`) | — | MISSING | Not a stored list of ids. Use the `tax_declaration` policy type (#30): assign a version with tasks off to the excluded group or employees. Who changed it and when comes from the policy audit. |
| 56 | Placeholders: `required`, From / To, Date, "Search by name, code, designation…" | — | N-A | UI chrome. |

## 10.9 Payroll general settings only in HROne's API (appendix)

These 58 fields are in the catalogue but have no captured control on the panel. Thirteen of them are already covered in 10.8: `guarantorRequiredCount`, `guarantorCountLimit`, `configureSalaryBank`, `allowChangeTaxregime`, `taxRegimePopupMandatory`, `excludedInvestmentEmployee` + By / On (#55; the policy audit records who changed it and when), `showBankIndianFinancialSystemCodeBranchHRDesk`, `enableLoanGuarantorFeature`, `submitAndApproveSettlementImportRequest`, `shareSettlementSlipsBySettlementImport` and `allowMinWagesSalaryStructure` (#11). The remaining 45 fields are below, in 39 rows.

| # | HROne field | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | `overtimePartOfGross` | Overtime component's `include_in_gross` | HAVE | — |
| 2 | `isGenerateLetterAfterSalaryRevision` | Letters by hand (PY-28) | PARTIAL | Setting `letters.auto.revision` (bool, default false). Listener on compensation effected → draft letter (with WF-01). |
| 3 | `bonusLimit` | BONUS rule `eligibility_ceiling` | N-A | Statutory, in the rule. |
| 4 | `implementationDate` | — | MISSING | See 10.8 #1. |
| 5 | `controlName`, `houseAnnuallySettingSaved` | — | N-A | HROne internal state. |
| 6 | `schedulePayroll` | Runs are opened and calculated by hand (`PayrollRuns`) | MISSING | Setting `payroll.auto_calculate_day` (number, 0 = off, default 0). A job opens and calculates a draft run; approval stays manual. |
| 7 | `houseRentExemptionUpToPaidRent` | `TaxComputer` HRA exemption = min(HRA, rent − 10% basic, 40/50% basic) | HAVE | Statutory formula in code and rule. |
| 8 | `calculateHouseAnnually` | HRA exemption is computed annually | PARTIAL | Setting `payroll.tax.hra_method` (select `annual` (default) / `monthly`). Monthly = sum of the month-wise minimum. |
| 9 | `costToCompanyPassword` | — | MISSING | With PDF (10.8 #47): `compensation.letter_password`. |
| 10 | `statutoryDetailsFromEmployee` | `ChangeStatutoryIdentityAction` behind `ProfileChangeGuard` (HR or permitted self-service) | PARTIAL | Setting `employee.self_service.statutory_details` (bool, default false): employees submit PAN / UAN / ESI for verification. |
| 11 | `verifyBankAccount` | `employee_bank_accounts.verified_at` (manual) | PARTIAL | Penny-drop verification is EXT (provider). Keep manual. |
| 12 | `showProvidentFundReport` | — | MISSING | "My PF" self-service view of the employee's PF lines by month (from `payroll_entry_lines`). Setting `payroll.self_service.pf_report` (bool, default false). |
| 13 | `hideViewDetails` | — | N-A | Unclear UI toggle. |
| 14 | `goodsServicesTaxPercentage` (consultant pay with GST) | No GST on payroll payments. The `Tax` domain is for SaaS billing. | MISSING | Out of payroll's scope. Consultants invoicing GST belongs to accounts payable. Note for the owner. |
| 15 | `atalPensionScheme` | — | PARTIAL | A deduction component with a new classification `apy` (fixed amount per employee). See 10.11. |
| 16 | `onlyOneReimbursementRequest` | — | MISSING → built (HS-BP-9) | Setting `payroll.claims.one_open_per_component` (bool, default false). |
| 17 | `restrictClaimMoreBill` | Claims carry bills; the approved amount can be lower | PARTIAL → built (HS-BP-9) | Setting `payroll.claims.not_above_bills` (bool, default false). |
| 18 | `attachmentsInReimbursement` | `payroll.claims.attachment_required` | HAVE | — |
| 19 | `numberOfComponentsAllow`, `numberOfPayGroup` | — | N-A | HROne licence limits. Plans are handled by SaaS entitlements. |
| 20 | `ptExemptionEnable` | `employee_statutory_details.pt_applicable` | HAVE | — |
| 21 | `numberOfLanderAllow` | `payroll.tax.max_landlords` | HAVE | — |
| 22 | `houseRentAllowanceFormDeclaration` | HRA rent declaration in `DeclareInvestments` (`HRA_RENT`, landlords) | HAVE | — |
| 23 | `enablePFProrateEmployee` | PF on prorated wages; the ceiling is not prorated | PARTIAL | See 10.11 #8. |
| 24 | `bankBranch`, `bankIndianFinancialSystemCode` | — | MISSING → built (HS-BP-13) | `company_bank_accounts` (10.7 #5). |
| 25 | `landlordPanMandatory` | `payroll.tax.landlord_pan_required` | HAVE | — |
| 26 | `allowGroupInChallan` | — | PARTIAL | See 10.1 #3. |
| 27 | `midmonthIncrement`, `midmonthIncrementType` | Compensation segments within the month | HAVE | — |
| 28 | `isCosttoCompanyNotApplicableOnOffer` | — | N-A | Offers are recruitment, which is out of scope. |
| 29 | `hideChallanEditButton` | — | N-A | UI. |
| 30 | `npsApplicableOnArrear` | No revision arrears yet | PARTIAL | With `RevisionArrearInput`: arrear lines carry the component's NPS treatment. |
| 31 | `useAzureForPayslip`, `useAzureForBackgroundProcess` | — | N-A | HROne infrastructure. |
| 32 | `autoPerquisite` (e.g. interest benefit on concessional loans) | — | MISSING | `TaxIncomeEntries::autoValue()` for loans (interest-free or concessional loan perquisite) at the rule's SBI rate param. |
| 33 | `verifyEmployeesPanAadharLinkage` | — | MISSING | EXT: needs an ITD verification provider. Store the result in `pan_aadhaar_linked` (10.11 #3). |
| 34 | `showPayComponentOnZeroPaidAmount` | Zero lines are added and shown (`fromComponent` always adds the line) | PARTIAL | Setting `payroll.payslip.show_zero_lines` (bool, default true). |
| 35 | `enableLoanTenureExtensionByOneEmi` | `Loans::defer` (adds one instalment at the end) | HAVE | — |
| 36 | `useOldCalculatorConcept` | — | N-A | HROne internal. |
| 37 | `enableSectionAct2025` | TDS rule v3 under the Income-tax Act, 2025 (s.392; Form 130 / 138) | HAVE | — |
| 38 | `wageComliantConfigured`, `isCtcBalancerConfigured` | — | N-A | HROne setup-state flags. |
| 39 | `hideNewWageCodeAlerts` | Wage-share warnings always shown in the preview | PARTIAL | Covered by `compensation.wage_share_enforcement` (10.8 #9). An `off` option could be added; keep the warning in the audit view. |

## 10.10 Slabs (`/payroll/slabs`)

Page text: "Slabs — Financial year 2026 - 2027". Cards: Labour welfare fund (Not created), Bonus (Minimum wages) (Not created), Professional tax (Not created), Tax (Created · DETAILS).

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Slabs page with financial-year selector and a card per statute | `ComplianceRuleResource` lists rule versions (code, state, version, dates, verification) with a Parameters modal. There is no FY card view. | PARTIAL | A read-only "Statutory slabs" page (Setup → Payroll link). For a chosen FY it shows PT, LWF, TDS and bonus data per state from `ComplianceRules::resolve`, with verification status. No editing. |
| 2 | Professional tax slabs | Same component as 10.1 #11–16 | see 10.1 | Not counted here. |
| 3 | Labour welfare fund slabs | Same component as 10.1 #5–10 | see 10.1 | Not counted here. |
| 4 | Bonus (Minimum wages): "Add slabs / NO SLAB CREATED / INACTIVATE"; `slabList`, `minimumWages` (per compareWith select) | BONUS rule `calculation_ceiling_rule = max_with_minimum_wage`, but minimum wages are not held, so the comparison is skipped | MISSING | `MIN_WAGE` rule pack (10.8 #11) keyed by state and skill category (10.7 #7). `BonusRegister` uses max(₹7,000, applicable minimum wage). Rule data, not tenant slabs. |
| 5 | Tax slab: Serial / Tax regime / Lower limit / Upper limit / Tax percentage (%) | TDS rule versions v1–v3 (new and old regime slabs, surcharge, cess, rebate) in `database/data/compliance/in.php`, viewable read-only | HAVE | Not tenant-editable, by design. |
| 6 | "You must review all settings to enable salary process" | Payroll calculation raises blocking exceptions for missing or unverified rules (`ComplianceRules::enforced`), and `ComplianceReadiness` gates outputs | HAVE | — |

## 10.11 Statutory template (`/payroll/template_statutory`, `app-employee-statutory`)

Page text: "You can create different statutory templates for different states / CTC. These templates can be used while assigning employee statutory setting to pre fill basic information". Add Statutory template; filter Template / Status.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Template list, "Add Statutory template", filter Template / Status | — | MISSING | New table `statutory_templates` (name, description, status, `defaults` JSON over the employee fields below) with a resource (Setup → Payroll). Action "Apply template" on the employee statutory section (`ChangeStatutoryApplicabilityAction` + `PfOptions::set`), plus a bulk apply upload. |
| 2 | `statutoryTemplateName`, `statutoryTemplateDescription` | — | MISSING | On the table above. |
| 3 | `panAdhaarLinked` | `TaxComputer` handles a missing PAN (s.206AA higher rate). An inoperative PAN (not linked to Aadhaar) is not tracked. | MISSING | `employee_statutory_details.pan_aadhaar_linked` (nullable bool). False is treated like a missing PAN (rate from the TDS rule). Verification is EXT. |
| 4 | `taxRegime` | `employee_statutory_details.tax_regime` and `employee_tax_declarations.regime` | HAVE | — |
| 5 | `isSalaryProcessStopped` | Salary holds (`salary_holds`, PY-06) keep pay out of the bank file but still calculate it | PARTIAL | `salary_holds.mode` (`hold_payment` (default = today) / `skip_calculation`). `PayrollRuns::populationQuery` leaves out `skip_calculation`. |
| 6 | `providentFundApplicable` | `employee_statutory_details.pf_applicable` | HAVE | — |
| 7 | `providentFundGrossLimit`, `employerProvidentFundGrossLimit` | `employee_pf_options.wage_basis` (ceiling / actual) for both shares | PARTIAL | `employer_wage_basis` (10.8 #3). |
| 8 | `providentFundProrate`, `providentFundLimitProrate` (prorate PF and the ceiling by paid days) | PF is on paid (prorated) PF wages; the ceiling is never prorated | PARTIAL | EPF profile setting `prorate_ceiling` (yes / no, default no) in `compliance.profile_settings.EPF`, plus an employee override `employee_pf_options.prorate_ceiling` (nullable). |
| 9 | `providentFundPercent` (employee rate) | Rate from the EPF rule; extra contribution as VPF | PARTIAL | No lower rate: it is statutory. A higher rate is VPF (#22). |
| 10 | `accountTenProvidentFundPercent`, `accountOneProvidentFundPercent` (EPS / EPF split) | EPF rule `eps_rate`, `eps_wage_ceiling`; `PF_ER` basis {eps, epf} | N-A | Statutory, in the rule. |
| 11 | `providentFundNumber`, `universalAccountNumber` | `pf_number`, `uan` (encrypted, masked) | HAVE | — |
| 12 | `pensionApplicable` (EPS) | EPS is computed for every PF member | MISSING | `employee_pf_options.eps_applicable` (nullable = automatic). Automatic cases (age 58, an excluded employee who joined after 1 Sep 2014 above the ceiling) come from EPF rule params, read in `StatutoryEngine`. |
| 13 | `pensionLimit` | EPF rule `eps_wage_ceiling` | N-A | Statutory. |
| 14 | `pensionNumber` | — | MISSING | `employee_statutory_details.metadata.eps_number` (rarely used). |
| 15 | `joiningDateForForm5`, `leavingDateForForm10` (PF joining and leaving dates) | ECR uses employment dates | PARTIAL | `employee_pf_options.pf_joined_on` / `pf_left_on` (nullable, default the employment dates), read by `EpfReturns`. |
| 16 | `atalPension` | — | PARTIAL | A deduction component with classification `apy` (new) and a fixed per-employee amount. The subscriber chooses the amount, so there is no statutory calculation. |
| 17 | `employeeStateInsuranceApplicable`, `employeeStateInsuranceNumber` | `esic_applicable`, `esic_number` | HAVE | — |
| 18 | `employeeStateInsuranceDispensaryName`, `employeeStateInsuranceSubunitcode` | — | MISSING | `employee_statutory_details.metadata.esi_dispensary` and the registration `esic_sub_unit` (10.1 #4), in the ESI layout variant. |
| 19 | `laborWelfareFundApplicable` | Establishment applicability only. The LWF block has no employee flag. | MISSING | `employee_statutory_details.lwf_applicable` (bool, default true). Add it to `ChangeStatutoryApplicabilityAction::FIELDS` and the `StatutoryEngine` LWF condition. |
| 20 | `labourWelfareFundProcess` | The LWF state is the establishment's (`StatutoryContext::lwfState`) | PARTIAL | Optional `lwf_state_code` override, like `pt_state_code`. |
| 21 | `professionalTaxApplicable` | `pt_applicable` + `pt_state_code` | HAVE | — |
| 22 | `voluntaryProvidentFundApplicable`, `amountOrPercentange`, `voluntaryProvidentFundAmount`, `voluntaryProvidentFundPercentage`, VPF effective date | `employee_pf_options` (`vpf_mode` percent / amount, `vpf_value`, `effective_from`); `PfOptionResource`, `PfOptionImports` | HAVE | — |
| 23 | `prorateVoluntaryProvidentFund` | A fixed VPF amount is not prorated | MISSING | `employee_pf_options.vpf_prorate` (bool, default false). |
| 24 | `minimumVoluntaryProvidentFund` | — | MISSING | `employee_pf_options.vpf_minimum` (number, nullable). |
| 25 | `bonusApplicable` | `BonusRegister` decides eligibility from the BONUS rule | PARTIAL | `employee_statutory_details.bonus_applicable` (nullable = the rule decides). |
| 26 | `gratuityApplicable`, `gratuityPartOfCastToCompany`, `dateOfJoiningGratuity` | `GratuityEntitlements` by rule (service, employment type). In-CTC provision through a gratuity-classified component. No continuity date. | PARTIAL | `employee_statutory_details.gratuity_service_from` (date, nullable: continuity of service), read by `GratuityEntitlements`. `gratuity_applicable` stays rule-driven. |
| 27 | `nationalPensionSchemeApplicable`, `nationalPensionPercentage`, `nationalPensionConfigurationAmount`, `prorateNationalPensionConfigurationAmount`, `nationalPensionSchemedPercent`, `minimumNationalPensionConfigurationAmount` | NPS through `nps_employee` / `nps_employer` components (a formula per structure) and employer NPS on the declaration. No per-employee election. | PARTIAL | Use `input` components (10.4) for the employee's NPS % or amount, read by the NPS component formula (e.g. `basic * nps_pct / 100`). The 80CCD(2) limit stays in the TDS rule. |
| 28 | `pranNumber` | — | MISSING | `employee_statutory_details.pran` (encrypted, masked like UAN; DataInventory restricted). |
| 29 | `payModeCode`, `salaryBankBranchCode` ("Salary payment bank") | — | MISSING | `employees.pay_mode` (10.7 #4) and `company_bank_accounts` (10.7 #5). |
| 30 | `payModeCodeReimbursement`, `reimbursementBankBranchCode` ("Reimbursement payment bank") | — | MISSING | `employees.reimbursement_pay_mode` (nullable = same as salary). `BankFile` can split salary and reimbursement rows. |
| 31 | `panNo` | `employee_statutory_details.pan` (encrypted) | HAVE | — |
| 32 | Placeholders "Registration date", "Voluntary Provident Fund Effective Date", "Salary payment bank", "Reimbursement payment bank" | Effective dates on `employee_pf_options` | HAVE | — |
| 33 | Document viewer (`app-hrone-document-viewer`) | Employee documents | N-A | UI. |

## 10.12 Statutory page (`app-statutory`, *(supplementary)*: the crawl saved the "Statutory" menu under the template URL)

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | "Move to New Financial Year" with checks (all salaries processed, released or held; no pending investment or reimbursement requests; challans generated; joiners' DOJ; settlements for last-FY leavers) | There is no FY activation gate. Payroll is monthly, and TDS follows the payment date and FY (`FinancialYear`). | N-A | By design, no "move FY" step. Optional: a read-only "Year-end checklist" panel on `PayrollControlRoom` showing the same checks. |
| 2 | "Pending reviews: salaries not processed as per confirmed investments… Form 24Q last quarter" | TDS is recomputed every month on the declaration or verified proofs (`payroll.tax.verified_proofs_from`). `TdsQuarterlyReturns` validation. | PARTIAL | A year-end TDS true-up list of employees whose last-quarter TDS differs from the annual projection. |
| 3 | Tax setting stepper "Exemption / TDS / Review" (`app-exceptions`, `app-other`; controls not captured) | TDS rule params + `payroll.tax.*` settings (`TaxSettings`) | PARTIAL | Controls unknown. |
| 4 | Provident Fund DETAILS | `EstablishmentStatutoryProfile` EPF (applicable, registration, `restrict_to_ceiling`) | HAVE | — |
| 5 | ESI "Contribution / Configuration / Review" | ESI profile + rule | HAVE | — |
| 6 | Labour welfare fund DETAILS | LWF profile + rule | HAVE | — |
| 7 | Bonus: "Is bonus applicable?" (`applicable`) | BONUS rule (awaiting owner verification); establishment applicability through the BONUS register | PARTIAL | With PY-19. |
| 8 | Bonus "Minimum attendance (in days)" (`minimumAttendance`) | BONUS rule (30 working days) | N-A | Statutory. |
| 9 | Bonus "Bonus percentage" (`bonusPercent`) | `BonusRegister` shows the statutory minimum and maximum. The employer's chosen % is not held. | MISSING | Setting `payroll.bonus.percent` (number, default = the rule's minimum rate), validated against the rule's minimum and maximum. It is an employer choice inside the statutory range, not a statutory value. |
| 10 | Bonus "Bonus compliance Yes / No" (`compliance`) | — | MISSING | Part of #9: `payroll.bonus.statutory` (bool, default true). |
| 11 | Bonus "Ceiling from / Ceiling to / Amount" list (`ceilingList`) | Rule `eligibility_ceiling` / `calculation_ceiling` | N-A | Statutory. |
| 12 | Bonus "On what do you want to process bonus? Lesser of bonus wages and minimum wages / More of / On minimum wages" (`bonusCalculateOnWithCompliance`) | Rule `calculation_ceiling_rule = max_with_minimum_wage` (minimum wage not held) | PARTIAL | Statutory, in the rule. Needs `MIN_WAGE` (10.10 #4). A bonus payout run through a `BonusInput` (`PayrollInputSource`) belongs to PY-19. |
| 13 | Gratuity: "Is gratuity applicable?" | Rule + `GratuityEntitlements`, `GratuityRegister` | HAVE | — |
| 14 | "Gratuity percentage per year for provisioning as part of CTC" (Percentage / INR) | A gratuity-classified component formula in the structure (e.g. `basic * 0.0481`) | HAVE | — |
| 15 | "What is tax free gratuity limit?" | TDS / GRATUITY rule ceiling | N-A | Statutory. |
| 16 | "Do you want to cap gratuity payment on tax free gratuity limit?" | `FinalSettlements` pays the computed gratuity up to the rule's statutory ceiling | PARTIAL | Setting `exit.gratuity_cap_at_exempt_limit` (bool, default false) for employers who pay more than the statutory ceiling ex gratia (paid as a separate line). |
| 17 | "Employee types for which gratuity is applicable", "Tenure for gratuity applicability" (year / month), "Gratuity month based rounding" | Rule (5 years, fixed-term 1 year under the Code on Social Security, rounding of more than 6 months) and employment type in `GratuityEntitlements::accrued` | PARTIAL | Statutory parts stay in the rule. A per-employee exclusion uses 10.11 #26. |
| 18 | Professional tax DETAILS | PT profile + rule | HAVE | — |
| 19 | TDS deductor information "Add group" | `TdsProfileResource` (legal entity, deductor category, TAN, PAN, responsible person) | HAVE | — |
| 20 | Move-FY warnings and dialogs (Disclaimer / Warning / Alert) | — | N-A | UI. |

---

## Build packages

They are listed in suggested order. Each package is additive, and every new setting defaults to today's behaviour. Tests are Pest feature tests in `tests/Feature/HroneParity/`. Each package ends with an entry in `docs/architecture/hrone-parity.md` and the gap register.

| # | Package | Scope | Files likely touched | Depends on | Size |
|---|---|---|---|---|---|
| BP-1 | **Pay component flags** (CF-11) | The statutory-wage, display and process columns on `salary_components`: `pt_wages`, `lwf_wages`, `esi_eligibility_wages`, `bonus_wages`, `bonus_eligibility_wages`, `gratuity_wages`, `part_of_wages`, `encashment_wages`, `part_of_rate`, `show_on_payslip`, `show_in_register`, `pay_in_joining_month`, `pay_in_exit_month`, `prorate_on_join_exit`, `rounding`, `pay_nature`, `attachment_required`, `requestable_by`, `claim_window_days`, `claim_limit_per_claim`, `exempt_in_regimes`, `translations` (10.3). Defaults are back-filled from today's flags, so results do not change. | migration; `Payroll/Models/SalaryComponent`; `SalaryComponentResource`; `PayrollComputation` (`ptWages`, `lwfWages`); `StatutoryEngine` (PT, LWF, ESI eligibility); `PayrollCalculator`; `StructureFormulas`; `Payslips`; `WageRegister`; `BonusRegister`; `GratuityEntitlements`; `LeaveEncashmentInput`; `FinalSettlements`; `ReimbursementClaims`; `VariablePay`; `TaxComputer` | — | L |
| BP-2 (built) | **`input` components: CTC formula tags** (10.4) | New calculation method `input`: captured on the compensation, readable by formulas, never paid. Structure version `balancing_component_id` and `ctc_mode` (10.2 #12, 10.8 #16). | `config/peopleos.php` (`payroll.calculation_methods`); `PayrollCalculator`; `StructureFormulas`; `FormulaVariables` guide; `Compensation/Services/CompensationStructures`, `CompensationChanges`; `VersionsRelationManager`; `Payslips` | BP-1 (shared migration window) | M |
| BP-3 | **Workforce policy types** | Types `confirmation` and `transfer`. Extend the `exit` type and wire it (notice by status, backdating windows, retirement age, letters, interviews, checklist id). Induction as `onboarding_templates.kind` + `starts_days_before_joining`. Generic `applies_to` in `PolicyResolver`. New `options_from` sources (`letter_templates`, `forms`, `clearance_checklists`). | `config/peopleos.php` `policies.types`; `config/peopleos_setup.php` `workforce`; `PolicySettingsSchema`; `SetupCatalogue`; `PolicyResolver`; `Exits`; `HireEmployeeAction`; `CreatePreEmployeeAction`; `Onboarding`; `SetupCentreTest` | — | M |
| BP-4 | **Confirmation reviews** (WF-06) | `confirmation_reviews` table, `ConfirmationReviews` service (open at lead days, confirm / extend / terminate within the policy limits), `ConfirmationApprovalSource`, feedback form tasks, letters on decision, extension and termination letter types. | new `Domain/Lifecycle` (or `Employment`) model + service; `ParityServiceProvider`; `LifecycleEngine`; `Exits`; `Letters`; `config/peopleos.php` `letters.types`, `workflows.trigger_events`; `DataInventory`; a resource under Workforce | BP-3 | M |
| BP-5 | **Separation completion** (WF-02/03/08) | `workforce_reasons` (shared with BP-6), `clearance_checklists` + items with a resource, manager and HR exit interviews (forms, sources), retirement job and trigger event, `exit.settlement_trigger_days`, blacklist reasons. | `Exits`, `ExitInterviews`, `FinalSettlements`; migrations; new resources; `config/peopleos_setup.php`; `ExitCaseResource`; `DataInventory` | BP-3 | L |
| BP-6 | **Transfers** (WF-04) | Transfer reasons (`workforce_reasons` kind transfer), `transfer_requests` + `TransferRequests` service + `TransferApprovalSource`, policy-checked dimensions and windows, link to a `transfer` compensation change, transfer allowances as a component flag paid by `TransferAllowanceInput`. | new models / services / resources; `TransferEmployeeAction`; `ViewEmployee`; `Payroll/Inputs/TransferAllowanceInput` (tag `peopleos.payroll_inputs`); `ParityServiceProvider`; `DataInventory`; permissions `workforce.transfer.request` / `.approve` (+ sync) | BP-3, BP-1 (component flag) | M–L |
| BP-7 | **Employee CTC page and compensation switches** | `compensation.self_service.*` (net take home, wage split, history, perquisites, notes, insight, variable plan, statutory), compensation change attachments + `compensation.change_attachment_required`, wage-share enforcement and basis, `compensation.change_type_labels` (new Setup type `labels`) + `change_types_enabled`, joiner declaration text. | `config/peopleos.php` settings; `config/peopleos_setup.php`; `MyCompensation` + blade; `CompensationChanges`; `StructureFormulas`; `SetupCatalogue` + `setup-centre.blade.php`; CompensationChange resources | BP-2 (ctc_mode) for full effect | M |
| BP-8 | **Tax and investment switches** (HP-3 completion) | `payroll.tax.show_estimator`, `proof_required_on_submit`, `payroll_may_edit`, `declaration_day_from` / `_to`, `form12bb_visibility`, `regime_prompt`, `form16_self_service` (My tax certificates), `payroll.claims.new_regime_bills`, `pan_aadhaar_linked`, the `PanVerifier` seam (EXT), the `tax_declaration` policy type with declaration and proof tasks (exclusions by assignment). | `TaxSettings`; `InvestmentDeclarations`; `InvestmentProofs`; `DeclareInvestments`; `TaxDeclarationResource`; `Form12bbController`; `TaxComputer`; new Me page; console command; `config/peopleos.php` / `peopleos_setup.php` | BP-1 (`exempt_in_regimes`) | M |
| BP-9 (built) | **Loans, claims and variable pay completion** | Loan policy fields (approver edit, interest edit, guarantors count / limit + `loan_guarantors`, max EMI, request window, requests a year, exception), claim reminder tasks, one open claim, not above bills, `variable_pay_plans` + `payroll.variable.task_day` + CTC visibility. | `config/peopleos.php` `policies.types.loan`; `Loans`, `LoanResource`; `ReimbursementClaims`; `VariablePay` + new plan model and resource; commands; `PayrollRequestsApprovalSource` | BP-1 | M |
| BP-10 | **Arrears and settlement** (PY-09, PY-33, 10(10AA)) | `RevisionArrearInput` (reads `is_arrear_eligible`), `payroll.arrears.pay_with_salary`, `payroll.pf.on_arrears`, `payroll.pf.ctc_employer_pf_basis`; a new TDS rule version with leave-encashment exemption params (evidence + verification); settlement slip + `exit.settlement_slip_share`; settlement upload + `exit.settlement_import_auto_approve`. | `Payroll/Inputs/*`; `PayrollInputs`; `PayrollCalculator`; `database/data/compliance/in.php`; `TaxComputer`; `FinalSettlements`; `RowImportTypes` + new `RowImports` subclass; `PayrollUploads` | BP-1; owner verification of the new rule version | L |
| BP-11 | **Employee statutory options and templates** | `employee_pf_options` (+`employer_wage_basis`, `eps_applicable`, `vpf_prorate`, `vpf_minimum`, `pf_joined_on` / `pf_left_on`, `prorate_ceiling`), EPF profile `prorate_ceiling`, `employee_statutory_details` (+`lwf_applicable`, `lwf_state_code`, `bonus_applicable`, `gratuity_service_from`, `pran`, `pan_aadhaar_linked`, metadata ESI dispensary / EPS no.), registration `esic_sub_unit` + ESI layout variant, classification `apy`, EPS conditions as EPF rule params, `statutory_templates` + apply action and upload, `salary_holds.mode`. | migrations; `EmployeeStatutoryDetail`; `ChangeStatutoryApplicabilityAction`; `PfOptions`, `PfOptionResource`; `StatutoryEngine`; `EpfReturns`, `EsiReturns`; `statutory_export_layouts` data; `SalaryHolds`, `PayrollRuns`; new resource; `DataInventory` | BP-8 (PAN link) | L |
| BP-12 | **Pay groups as payroll units and templates** (PY-22, CF-10) | Structure version `templates` (payslip layout, appointment / revision / contract letters), `annexure_letter_template_id`, run population by structure, "Employees using this" export, tax-treatment column and component export. | `SalaryStructureVersion`, `VersionsRelationManager`; `PayrollRuns` (population, uniqueness); `Payslips`; `LetterContext`; SalaryComponent and SalaryStructure resources | BP-2 | M |
| BP-13 (built) | **Bank and pay modes** (PY-24) | `payroll.pay_modes`, `employees.pay_mode` / `reimbursement_pay_mode`, `company_bank_accounts` with a resource, versioned bank file layouts, cash and cheque register, `payroll.bank.show_ifsc_branch`. | migrations; `BankFile`; new model and resource; analytics dataset + template; `config` | — | M |
| BP-14 | **Payslip language and protection** (PY-23) | `payroll_glossary_entries` + resource, `salary_components.translations`, payslip locale preference + `payroll.payslip.languages`, translated snapshot labels, `payroll.payslip.show_zero_lines`. Password-protected PDF payslips (`payroll.payslip.password`) wait for an owner decision on a PDF library. | `Payslips`; payslip views; `LetterContext`; new model and resource; `composer.json` (after the decision) | BP-1 | M (+EXT) |
| BP-15 | **Statutory data and challans** | Rule pack (all with evidence and owner verification): `MIN_WAGE` + `wage_skill_categories` + `compensation.minimum_wage_check`; PT and LWF rules for the remaining states; LWF `bands` and PT `month_amounts` / `deduct_months`. Also: `statutory_challans` register (PY-21) feeding TDS returns, EPF return grouping by registration, statutory coverage panel, read-only statutory slabs page, `payroll.bonus.percent` / `statutory` / `mode` + `BonusInput` (PY-19). | `database/data/compliance/in.php`; `StatutoryEngine`; `ComplianceRules`; `EpfReturns`; `TdsQuarterlyReturns`; `BonusRegister`; `ComplianceControlRoom`; new pages and resources | owner verification (statutory 0/24 verified); BP-1 | L |
| BP-16 | **Contracts and automatic letters** (WF-01, WF-05, PY-28) | `employee_contracts` + `workforce.contract.renewal_lead_days` + `employee.contract_expiring`, the workflow `document` node calling `Letters::generate`, `letters.auto.contract` / `appointment_employment_types` / `revision`. | `WorkflowEngine` (document node); `Letters`; new model, resource and job; `config/peopleos.php` `workflows.trigger_events`; listeners in `ParityServiceProvider` | BP-3/BP-4 (letters on decisions share the node) | M |
| BP-17 | **Mid-year go-live** (PY-25) | `payroll.go_live_month`, `payroll_opening_balances` + `opening_ytd` upload, read by `StatutoryEngine::yearToDate`, `TaxComputer` and the registers. | migration; `StatutoryEngine`; `TaxComputer`; `RowImportTypes`; compliance builders | — | M |

**Items kept out on purpose:**

- Tenant-entered PT, LWF, bonus and tax slabs: statutory values come only from rules.
- The captcha on component edits.
- Unreleasing salary after challans: finalized payroll is never reopened.
- The FY "activation" gate.
- Replacement requisitions: recruitment.
- HROne licence and infrastructure flags.
