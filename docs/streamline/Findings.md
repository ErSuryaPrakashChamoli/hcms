# Streamline programme — review findings (2026-11-05/06)

Four read-only reviews of the whole tree. Each item names the files; S-numbers refer to `Streamline-Plan.md`.

## Payroll (S2)
- Pipeline draft → calculated → validated → approved → finalized → paid (`PayrollRuns`); one live run per company month.
- Pre-run readiness (`PayrollReadiness`, 14 checks) informs only, always for the pay month containing today.
- Attendance month close (`attendance.month_close.enabled`, off) is never required by payroll (warning only).
- Finalize = lock attendance + close period + issue payslips + settle inputs (14 `PayrollInputSource`s) in one step;
  employees see payslips at once. No separate publish, no reopen of a closed month (PAY-1 / C12-D1).
- Defects: F&F can pay the final month twice (run population includes leavers whose settlement already paid it;
  `PayrollRuns::populationIn`, `FinalSettlements::calculate`); payslips guarded on delete only (`Payslip::booted`);
  readiness month fixed to today (`PayrollReadiness::month`); `setPaymentDate` checks editability before the lock.

## Forms (S6)
- Record pages are always wizards (FollowsFormWizard); drawers only with 2+ steps (`FormWizard::DRAWER_MINIMUM`).
- Plain today: 26 resource drawers (AlumniProfiles, CareerTracks, ClearanceChecklists, ExchangeRates, ExpenseGroups,
  ExpenseLocationGroups, ExpenseTaxTypes, FeedbackTemplates, ImprovementPlans, Initiatives, JvCodes,
  KpiRatingFormulas, LearningPrograms, MoodReasons, NotificationTemplates, OptionLists, OrganisationContacts,
  PerformanceLookups, PerformanceTemplates, Qualifications, QuestionBanks, RatingScales, SkillScales, TalentPools,
  TenantFeatures, TenantSettings); 19 relation-manager drawers (OnboardingTemplates items 15 fields, employee
  qualifications 12, addresses 9, experiences 8, bank accounts, emergency contacts …); 180 action modals with 4+
  fields (63 with 8+), e.g. Positions create (20), PlatformBillingAccounts recordProfile (20), ExpenseReceipts
  addExpense (29, already sectioned), leave apply forms (9–10, sectioned), RunAttendance processMonth (9).
- Six wizard styles: FormWizard (left rail + Review), hire wizard (rail, no Review), PolicyWizard (hand-built rail),
  39 native modal `->steps()` (top header, own or no review), DeclareInvestments (plain Wizard, top header),
  DataImport stepper. Guard test covers resource drawers only.

## Headers and navigation (S4, S5)
- 77 of 128 custom pages have no sub-heading; edit pages read "Edit {record}"; create buttons use technical model
  labels ("New master entry", "New rostered day", "New option").
- Duplicate labels: "My requests" ×2 (MyRequests, TicketResource), "My learning" ×2 (MyLearning,
  LearningEnrolmentResource), "Analytics" ×4, "Dashboard" ×3, "Cycles", "Templates", "Budgets", "Feedback" ×2, "Inbox".
- Competitor name in UI text (Engage desk sub-heading "in HROne's Engage order", badge/mood-reason seed dialogs,
  fundraiser description, data-import examples).
- Same concept, several names: payroll control room (4 names), audit (7), Admin Centre (4), calibration (4).
- Undeclared group "Admin" (QuestionBank, ClearanceChecklist, WorkforceReason, FeedbackTemplate) — lands in "Other".
- Overlapping destinations: MyWork / Inbox / Approvals / TaskInbox (+ MyHr tabs); NewRequest / MyRequests /
  TicketResource; PayslipResource vs MyPayslips; LeaveCalendar vs TeamCalendar; AdminCentre search vs
  ConfigurationFinder; legacy StatutoryProfileResource next to EstablishmentStatutoryProfileResource; launcher
  entries opening the same URL (ActionCatalogue 283/285/297; QuickActions 87/95, 104/107, 140/145).

## Duplicated code (S3)
- CSV formula-injection guard copied 14× with two rules; exports with none (Birthdays, ArticleResource,
  Feedback360Actions note column, CompensationAnalyticsPage, ReportExports).
- Base currency resolved three ways (CurrencyRates, `tenant.base_currency` setting, raw config).
- Financial-year parsing hand-rolled in ~15 places beside `Compliance\Services\FinancialYear`.
- Employee pickers: two label formats, 13 unbounded pickers, LIKE searches without escaping (AccessScopeGroups,
  ReportLibraryPage); ExpenseOnBehalf vs OnBehalfPicker.
- Generic imports read the attendance row limit (`RowImports.php:122`).
- Organisation-unit and master-data resources are byte-identical clones (Teams, Divisions, ProfitCentres,
  CostCentres, BusinessUnits; EmploymentTypes, EmployeeCategories, WorkModes, JobFamilies).
- Attachment path validation copied 9×; pay-month parsing ~31×; 18 private copies of `rescue()`.
- Dead: ChangeFeedPanel (+2 views), NullSurveyTaskProvider, LearningCosts, pos bars/donut/ring, employees/journey,
  welcome view; `x-pos.empty` is a subset of `x-pos.state`.
