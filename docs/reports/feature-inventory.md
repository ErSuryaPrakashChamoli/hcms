# Feature inventory

**Product:** Markedge PeopleOS ("PeopleOS") · **Build:** `1844ce6` · **Inventoried:** 19 October 2026

**For:** the product owner and documentation maintainers. Every customer-facing module (Filament resource or page) that
a tenant user can open, where it sits in the navigation, which system roles can open it, and the handbook chapter that
documents it. Generated from the running application (see implementation-status.md for the method).

## Product at a glance

- **What it is:** a multi-tenant SaaS HR platform (Laravel 13, Filament 5, PHP 8.5, MySQL) covering the employee lifecycle from
  hire to alumni, with payroll and India statutory compliance, performance, learning, talent, service desk, communication,
  analytics, audit and AI assistance.
- **Tenant-facing modules:** 195 (plus platform-operator pages that customers never see: tenants, plans, subscriptions,
  billing, tax set-up, signups, operations, readiness, retention and offboarding).
- **Navigation:** nine role-adaptive sections — Home, My work, People, Organisation, Insights, Workflows, Services,
  Communication, Admin — with area sub-menus, an area bar and "All in <area>" menus; search/command center (Ctrl K).
- **System roles:** 14 tenant roles (below) plus the platform operator flag (Markedge staff).
- **Channels:** responsive web application (desktop, tablet, phone); REST API v1 (OpenAPI 3.1, `docs/api/openapi.json`, 131
  operations), SCIM 2.0 user provisioning, signed webhooks, inbound events; OIDC single sign-on. No native mobile app.
- **Switched off / not customer-facing:** public self-service signup, billing and payments, tenant deletion.

## Modules per area

| Area (handbook chapter) | Modules |
|---|---|
| 5.1 Your workspace | 8 |
| 5.2 People and lifecycle | 11 |
| 5.3 Time and leave | 15 |
| 5.4 Pay | 31 |
| 5.5 Growth | 48 |
| 5.6 Organisation | 33 |
| 5.7 Services and communication | 18 |
| 5.8 Insights | 9 |
| 5.9 Administration | 22 |
| **Total** | **195** |

## Modules each system role can open

Computed for the role alone (no employee record); personal "Me" pages need the user to be linked to an employee record.

| Role | Modules | Menu sections |
|---|---|---|
| Tenant Super Admin | 186 | Home, Admin, Organisation, People, Workflows, Insights, My work, Services, Communication |
| Account Owner | 43 | Home, Admin, Organisation, People, Workflows, Insights, My work, Communication |
| Tenant HR Admin | 182 | Home, Admin, Organisation, People, Workflows, Insights, My work, Services, Communication |
| HR Manager | 86 | Home, My work, People, Organisation, Workflows, Services, Communication, Insights, Admin |
| HR Executive | 39 | Home, My work, People, Organisation, Insights, Admin |
| Payroll Admin | 63 | Home, My work, People, Insights, Organisation, Admin |
| Attendance Admin | 41 | Home, My work, People, Organisation, Insights, Workflows, Admin |
| Performance Admin | 22 | Home, My work, People, Organisation, Admin |
| Asset Admin | 21 | Home, My work, People, Organisation, Admin |
| Manager | 62 | Home, My work, People, Organisation, Insights, Admin |
| Employee | 38 | Home, My work, People, Organisation |
| Executive | 23 | Home, Insights, People, Organisation, My work, Admin |
| Alumni | 5 | Home, My work, People |
| Auditor | 135 | Home, Insights, People, Organisation, My work, Communication, Services, Workflows, Admin |

## Complete module list

`Who can open` lists system roles (Tenant Super Admin can open every module and is omitted). Employee-facing variants:
HR queue = "My requests", Cases = "My grievances", Articles = "Knowledge base", Letters = "My letters", Exit cases = "My exit",
Asset register = "My assets", Enrolments = "My learning".

### 5.1 Your workspace

| Module | Class | URL path | Section / space / group | Who can open |
|---|---|---|---|---|
| Assistant | `AssistantPage` | `/admin/assistant-page` | Home / You / Me | Employee, Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| My Day | `MyDay` | `/admin/my-day` | Home / You / Me | personal page |
| Home | `Home` | `/admin` | Home / Other / Other | Account Owner, Alumni, Asset Admin, Attendance Admin, Auditor, Employee, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| My work | `MyWork` | `/admin/my-work` | My work / You / Me | Account Owner, Alumni, Asset Admin, Attendance Admin, Auditor, Employee, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Notifications | `NotificationCenter` | `/admin/notifications` | My work / You / Me | Account Owner, Alumni, Asset Admin, Attendance Admin, Auditor, Employee, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Team HR requests | `TeamRequests` | `/admin/team-requests` | My work / You / Me | HR Manager, Manager, Tenant HR Admin |
| Approvals | `Approvals` | `/admin/approvals` | My work / Workflows / Workflows | Account Owner, Asset Admin, Attendance Admin, Employee, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Task inbox | `TaskInbox` | `/admin/task-inbox` | My work / Workflows / Workflows | Account Owner, Asset Admin, Auditor, Employee, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |

### 5.2 People and lifecycle

| Module | Class | URL path | Section / space / group | Who can open |
|---|---|---|---|---|
| Alumni | `AlumniProfileResource` | `/admin/alumni-profiles` | People / Lifecycle / Alumni | Auditor, HR Manager, Tenant HR Admin |
| Exit cases | `ExitCaseResource` | `/admin/exit-cases` | People / Lifecycle / Exit | Auditor, Employee, HR Manager, Manager, Tenant HR Admin |
| Exit insights | `ExitInsights` | `/admin/exit-insights` | People / Lifecycle / Exit | Auditor, HR Manager, Tenant HR Admin |
| Letters | `LetterResource` | `/admin/letters` | People / Lifecycle / Letters | Auditor, HR Manager, Tenant HR Admin |
| Templates | `LetterTemplateResource` | `/admin/letter-templates` | People / Lifecycle / Letters | HR Manager, Tenant HR Admin |
| Background checks | `BgvCaseResource` | `/admin/bgv-cases` | People / Lifecycle / People | Auditor, HR Executive, HR Manager, Tenant HR Admin |
| Directory | `People` | `/admin/people` | People / Lifecycle / People | Account Owner, Asset Admin, Attendance Admin, Auditor, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Employee imports | `EmployeeImportResource` | `/admin/employee-imports` | People / Lifecycle / People | Account Owner, Tenant HR Admin |
| Employees | `EmployeeResource` | `/admin/employees` | People / Lifecycle / People | Account Owner, Asset Admin, Attendance Admin, Auditor, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Onboarding | `OnboardingPlanResource` | `/admin/onboarding-plans` | People / Lifecycle / People | Auditor, HR Executive, HR Manager, Manager, Tenant HR Admin |
| Onboarding templates | `OnboardingTemplateResource` | `/admin/onboarding-templates` | People / Lifecycle / People | Auditor, HR Executive, HR Manager, Manager, Tenant HR Admin |

### 5.3 Time and leave

| Module | Class | URL path | Section / space / group | Who can open |
|---|---|---|---|---|
| Attendance records | `AttendanceRecordResource` | `/admin/attendance-records` | People / Time & leave / Attendance | Attendance Admin, Auditor, Employee, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Devices | `AttendanceDeviceResource` | `/admin/attendance-devices` | People / Time & leave / Attendance | Attendance Admin, Auditor, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Exception centre | `AttendanceExceptionCentre` | `/admin/attendance-exception-centre` | People / Time & leave / Attendance | Attendance Admin, Auditor, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Holiday Calendars | `HolidayCalendarResource` | `/admin/holiday-calendars` | People / Time & leave / Attendance | Attendance Admin, Auditor, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Punch imports | `PunchImportResource` | `/admin/punch-imports` | People / Time & leave / Attendance | Tenant HR Admin |
| Punches | `AttendancePunchResource` | `/admin/attendance-punches` | People / Time & leave / Attendance | Attendance Admin, Auditor, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Regularisations | `AttendanceRegularisationResource` | `/admin/attendance-regularisations` | People / Time & leave / Attendance | Attendance Admin, Auditor, Employee, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Shifts | `ShiftResource` | `/admin/shifts` | People / Time & leave / Attendance | Attendance Admin, Auditor, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Work Schedules | `WorkScheduleResource` | `/admin/work-schedules` | People / Time & leave / Attendance | Attendance Admin, Auditor, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Balances | `LeaveBalanceResource` | `/admin/leave-balances` | People / Time & leave / Leave | Attendance Admin, Auditor, Employee, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Encashments | `LeaveEncashmentResource` | `/admin/leave-encashments` | People / Time & leave / Leave | Attendance Admin, Auditor, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Leave calendar | `LeaveCalendar` | `/admin/leave-calendar` | People / Time & leave / Leave | Attendance Admin, Auditor, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Leave requests | `LeaveRequestResource` | `/admin/leave-requests` | People / Time & leave / Leave | Attendance Admin, Auditor, Employee, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Leave types | `LeaveTypeResource` | `/admin/leave-types` | People / Time & leave / Leave | Attendance Admin, Auditor, Employee, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Transactions | `LeaveTransactionResource` | `/admin/leave-transactions` | People / Time & leave / Leave | Attendance Admin, Auditor, Employee, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |

### 5.4 Pay

| Module | Class | URL path | Section / space / group | Who can open |
|---|---|---|---|---|
| My compensation | `MyCompensation` | `/admin/my-compensation` | Home / You / Me | personal page |
| Analytics | `CompensationAnalyticsPage` | `/admin/compensation-analytics` | People / Pay / Compensation | Auditor, Executive, HR Manager, Payroll Admin, Tenant HR Admin |
| Budgets | `CompensationBudgetResource` | `/admin/compensation-budgets` | People / Pay / Compensation | Auditor, HR Manager, Payroll Admin, Tenant HR Admin |
| Compensation changes | `CompensationChangeResource` | `/admin/compensation-changes` | People / Pay / Compensation | Auditor, Employee, HR Manager, Payroll Admin, Tenant HR Admin |
| Cycles | `CompensationCycleResource` | `/admin/compensation-cycles` | People / Pay / Compensation | Auditor, HR Manager, Payroll Admin, Tenant HR Admin |
| Pay ranges | `CompensationRangeResource` | `/admin/compensation-ranges` | People / Pay / Compensation | Auditor, HR Manager, Payroll Admin, Tenant HR Admin |
| Salary structures | `SalaryStructureResource` | `/admin/salary-structures` | People / Pay / Compensation | Auditor, HR Manager, Payroll Admin, Tenant HR Admin |
| Compliance rules | `ComplianceRuleResource` | `/admin/compliance-rules` | People / Pay / Compliance | Auditor, Payroll Admin, Tenant HR Admin |
| Control room | `ComplianceControlRoom` | `/admin/compliance-control-room` | People / Pay / Compliance | Auditor, Payroll Admin, Tenant HR Admin |
| EPF returns (ECR) | `EpfReturnResource` | `/admin/compliance/epf-returns` | People / Pay / Compliance | Tenant HR Admin |
| ESI returns | `EsiReturnResource` | `/admin/compliance/esi-returns` | People / Pay / Compliance | Tenant HR Admin |
| Export layouts | `ExportLayoutResource` | `/admin/export-layouts` | People / Pay / Compliance | Auditor, Payroll Admin, Tenant HR Admin |
| LWF returns | `LwfReturnResource` | `/admin/compliance/lwf-returns` | People / Pay / Compliance | Tenant HR Admin |
| Parallel payroll | `ParallelRunResource` | `/admin/compliance/parallel-runs` | People / Pay / Compliance | Tenant HR Admin |
| Professional tax returns | `ProfessionalTaxReturnResource` | `/admin/compliance/professional-tax-returns` | People / Pay / Compliance | Tenant HR Admin |
| Regulatory notices | `RuleNoticeResource` | `/admin/rule-notices` | People / Pay / Compliance | Auditor, Payroll Admin, Tenant HR Admin |
| Rule verification | `RuleVerificationResource` | `/admin/rule-verifications` | People / Pay / Compliance | Auditor, Payroll Admin, Tenant HR Admin |
| Statutory profiles | `EstablishmentStatutoryProfileResource` | `/admin/establishment-statutory-profiles` | People / Pay / Compliance | Tenant HR Admin |
| Statutory registrations | `StatutoryRegistrationResource` | `/admin/statutory-registrations` | People / Pay / Compliance | Tenant HR Admin |
| TDS certificates (Form 130) | `TdsCertificateResource` | `/admin/compliance/tds-certificates` | People / Pay / Compliance | Tenant HR Admin |
| TDS deductor profiles | `TdsProfileResource` | `/admin/tds-profiles` | People / Pay / Compliance | Tenant HR Admin |
| TDS investment proofs | `TdsInvestmentResource` | `/admin/tds-investments` | People / Pay / Compliance | Tenant HR Admin |
| TDS statements (Form 138) | `TdsReturnResource` | `/admin/compliance/tds-returns` | People / Pay / Compliance | Tenant HR Admin |
| Adjustments | `PayrollAdjustmentResource` | `/admin/payroll-adjustments` | People / Pay / Payroll | Auditor, Payroll Admin, Tenant HR Admin |
| Company statutory profiles (legacy) | `StatutoryProfileResource` | `/admin/statutory-profiles` | People / Pay / Payroll | Auditor, Payroll Admin, Tenant HR Admin |
| Control room | `PayrollControlRoom` | `/admin/payroll-control-room` | People / Pay / Payroll | Auditor, Payroll Admin, Tenant HR Admin |
| Payroll Auditor | `PayrollAuditor` | `/admin/payroll-auditor` | People / Pay / Payroll | Payroll Admin, Tenant HR Admin |
| Payroll runs | `PayrollRunResource` | `/admin/payroll-runs` | People / Pay / Payroll | Auditor, Payroll Admin, Tenant HR Admin |
| Payslips | `PayslipResource` | `/admin/payslips` | People / Pay / Payroll | Alumni, Auditor, Employee, Payroll Admin, Tenant HR Admin |
| Salary components | `SalaryComponentResource` | `/admin/salary-components` | People / Pay / Payroll | Auditor, Payroll Admin, Tenant HR Admin |
| Tax declarations | `TaxDeclarationResource` | `/admin/tax-declarations` | People / Pay / Payroll | Alumni, Auditor, Employee, Payroll Admin, Tenant HR Admin |

### 5.5 Growth

| Module | Class | URL path | Section / space / group | Who can open |
|---|---|---|---|---|
| My career | `MyCareer` | `/admin/my-career` | Home / You / Me | personal page |
| My learning | `MyLearning` | `/admin/my-learning` | Home / You / Me | personal page |
| Assignments | `LearningAssignmentResource` | `/admin/learning-assignments` | People / Growth / Learning | Auditor, Manager, Tenant HR Admin |
| Certificates | `LearningCertificateResource` | `/admin/learning-certificates` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Courses | `CourseResource` | `/admin/courses` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Dashboard | `LearningDashboard` | `/admin/learning-dashboard` | People / Growth / Learning | Auditor, Tenant HR Admin |
| Development plans | `DevelopmentPlanResource` | `/admin/development-plans` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Enrolments | `LearningEnrolmentResource` | `/admin/learning-enrolments` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Instructors | `LearningInstructorResource` | `/admin/learning-instructors` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Learning paths | `LearningPathResource` | `/admin/learning-paths` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Programs | `LearningProgramResource` | `/admin/learning-programs` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Providers | `LearningProviderResource` | `/admin/learning-providers` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Skill assessments | `SkillAssessmentResource` | `/admin/skill-assessments` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Skill levels | `SkillScaleResource` | `/admin/skill-scales` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| Skills | `SkillResource` | `/admin/skills` | People / Growth / Learning | Account Owner, Attendance Admin, Auditor, HR Executive, HR Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Training sessions | `TrainingSessionResource` | `/admin/training-sessions` | People / Growth / Learning | Auditor, Employee, Manager, Tenant HR Admin |
| My Team | `MyTeam` | `/admin/my-team` | People / You / Me | personal page |
| Team career | `TeamCareer` | `/admin/team-career` | People / You / Me | personal page |
| Team learning | `TeamLearning` | `/admin/team-learning` | People / You / Me | personal page |
| Analytics | `PerformanceAnalyticsPage` | `/admin/performance-analytics` | People / Growth / Performance | Auditor, Tenant HR Admin |
| Appraisals | `AppraisalResource` | `/admin/appraisals` | People / Growth / Performance | Auditor, Employee, Manager, Tenant HR Admin |
| Calibration | `CalibrationBoard` | `/admin/calibration-board` | People / Growth / Performance | Tenant HR Admin |
| Calibration sessions | `CalibrationSessionResource` | `/admin/calibration-sessions` | People / Growth / Performance | Tenant HR Admin |
| Career passport | `CareerPassportPage` | `/admin/career-passport-page` | People / Growth / Performance | Auditor, Tenant HR Admin |
| Check-ins | `PerformanceCheckInResource` | `/admin/performance-check-ins` | People / Growth / Performance | Auditor, Employee, Manager, Tenant HR Admin |
| Competencies | `CompetencyResource` | `/admin/competencies` | People / Growth / Performance | Auditor, Tenant HR Admin |
| Cycles | `PerformanceCycleResource` | `/admin/performance-cycles` | People / Growth / Performance | Auditor, Tenant HR Admin |
| Development needs | `DevelopmentNeedResource` | `/admin/development-needs` | People / Growth / Performance | Auditor, Employee, Manager, Tenant HR Admin |
| Feedback | `FeedbackEntryResource` | `/admin/feedback-entries` | People / Growth / Performance | Auditor, Employee, Manager, Tenant HR Admin |
| Goals | `GoalResource` | `/admin/goals` | People / Growth / Performance | Auditor, Employee, Manager, Tenant HR Admin |
| Improvement plans | `ImprovementPlanResource` | `/admin/improvement-plans` | People / Growth / Performance | Auditor, Employee, Manager, Tenant HR Admin |
| KRA library | `KraResource` | `/admin/kras` | People / Growth / Performance | Auditor, Tenant HR Admin |
| One-on-ones | `OneOnOneResource` | `/admin/one-on-ones` | People / Growth / Performance | Auditor, Employee, Manager, Tenant HR Admin |
| Rating scales | `RatingScaleResource` | `/admin/rating-scales` | People / Growth / Performance | Auditor, Tenant HR Admin |
| Templates | `PerformanceTemplateResource` | `/admin/performance-templates` | People / Growth / Performance | Auditor, Tenant HR Admin |
| Analytics | `TalentAnalyticsPage` | `/admin/talent-analytics` | People / Growth / Talent | Tenant HR Admin |
| Career architecture | `CareerTrackResource` | `/admin/career-tracks` | People / Growth / Talent | Auditor, Tenant HR Admin |
| Career paths | `CareerPathResource` | `/admin/career-paths` | People / Growth / Talent | Auditor, Tenant HR Admin |
| Career profiles | `CareerProfileResource` | `/admin/career-profiles` | People / Growth / Talent | Auditor, Tenant HR Admin |
| Critical positions | `CriticalPositionResource` | `/admin/critical-positions` | People / Growth / Talent | Tenant HR Admin |
| Dashboard | `TalentDashboard` | `/admin/talent-dashboard` | People / Growth / Talent | Tenant HR Admin |
| Development actions | `TalentDevelopmentActionResource` | `/admin/talent-development-actions` | People / Growth / Talent | Tenant HR Admin |
| Readiness | `ReadinessAssessmentResource` | `/admin/readiness-assessments` | People / Growth / Talent | Tenant HR Admin |
| Role requirements | `RoleRequirementResource` | `/admin/role-requirements` | People / Growth / Talent | Auditor, Tenant HR Admin |
| Succession plans | `SuccessionPlanResource` | `/admin/succession-plans` | People / Growth / Talent | Tenant HR Admin |
| Successors | `SuccessorResource` | `/admin/successors` | People / Growth / Talent | Tenant HR Admin |
| Talent pools | `TalentPoolResource` | `/admin/talent-pools` | People / Growth / Talent | Tenant HR Admin |
| Talent reviews | `TalentReviewResource` | `/admin/talent-reviews` | People / Growth / Talent | Tenant HR Admin |

### 5.6 Organisation

| Module | Class | URL path | Section / space / group | Who can open |
|---|---|---|---|---|
| Designations | `DesignationResource` | `/admin/designations` | Admin / Setup / People Setup | Account Owner, Attendance Admin, Auditor, HR Executive, HR Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Document types | `DocumentTypeResource` | `/admin/document-types` | Admin / Setup / People Setup | Auditor, HR Executive, HR Manager, Tenant HR Admin |
| Employee Categories | `EmployeeCategoryResource` | `/admin/employee-categories` | Admin / Setup / People Setup | Account Owner, Attendance Admin, Auditor, HR Executive, HR Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Employment Types | `EmploymentTypeResource` | `/admin/employment-types` | Admin / Setup / People Setup | Account Owner, Attendance Admin, Auditor, HR Executive, HR Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Grades | `GradeResource` | `/admin/grades` | Admin / Setup / People Setup | Account Owner, Attendance Admin, Auditor, HR Executive, HR Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Job Families | `JobFamilyResource` | `/admin/job-families` | Admin / Setup / People Setup | Account Owner, Attendance Admin, Auditor, HR Executive, HR Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Levels | `LevelResource` | `/admin/levels` | Admin / Setup / People Setup | Account Owner, Attendance Admin, Auditor, HR Executive, HR Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Work Modes | `WorkModeResource` | `/admin/work-modes` | Admin / Setup / People Setup | Account Owner, Attendance Admin, Auditor, HR Executive, HR Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Asset register | `AssetResource` | `/admin/assets` | Organisation / Organisation / Assets | Asset Admin, Auditor, Employee, Manager, Tenant HR Admin |
| Categories | `AssetCategoryResource` | `/admin/asset-categories` | Organisation / Organisation / Assets | Asset Admin, Auditor, Tenant HR Admin |
| Models | `AssetModelResource` | `/admin/asset-models` | Organisation / Organisation / Assets | Asset Admin, Auditor, Tenant HR Admin |
| Business Units | `BusinessUnitResource` | `/admin/business-units` | Organisation / Organisation / Organisation | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Companies | `CompanyResource` | `/admin/companies` | Organisation / Organisation / Organisation | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Cost Centres | `CostCentreResource` | `/admin/cost-centres` | Organisation / Organisation / Organisation | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Departments | `DepartmentResource` | `/admin/departments` | Organisation / Organisation / Organisation | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Divisions | `DivisionResource` | `/admin/divisions` | Organisation / Organisation / Organisation | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Establishment assignments | `EstablishmentAssignmentResource` | `/admin/establishment-assignments` | Organisation / Organisation / Organisation | Account Owner |
| Establishments | `EstablishmentResource` | `/admin/establishments` | Organisation / Organisation / Organisation | Account Owner |
| Legal Entities | `LegalEntityResource` | `/admin/legal-entities` | Organisation / Organisation / Organisation | Account Owner |
| Locations | `LocationResource` | `/admin/locations` | Organisation / Organisation / Organisation | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Org map | `OrganisationMap` | `/admin/organisation-map` | Organisation / Organisation / Organisation | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Organisation Designer | `OrganisationDesigner` | `/admin/organisation-designer` | Organisation / Organisation / Organisation | Account Owner, Tenant HR Admin |
| Profit Centres | `ProfitCentreResource` | `/admin/profit-centres` | Organisation / Organisation / Organisation | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Teams | `TeamResource` | `/admin/teams` | Organisation / Organisation / Organisation | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Analytics | `WorkforceAnalyticsPage` | `/admin/workforce-analytics` | Organisation / Organisation / Workforce | Executive, Tenant HR Admin |
| Budgets | `WorkforceBudgetResource` | `/admin/workforce-budgets` | Organisation / Organisation / Workforce | Tenant HR Admin |
| Dashboard | `WorkforceDashboard` | `/admin/workforce-dashboard` | Organisation / Organisation / Workforce | Auditor, Executive, Tenant HR Admin |
| Headcount plans | `WorkforcePlanResource` | `/admin/workforce-plans` | Organisation / Organisation / Workforce | Auditor, Tenant HR Admin |
| Position hierarchy | `PositionHierarchyPage` | `/admin/position-hierarchy` | Organisation / Organisation / Workforce | Auditor, Tenant HR Admin |
| Positions | `PositionResource` | `/admin/positions` | Organisation / Organisation / Workforce | Auditor, Manager, Tenant HR Admin |
| Scenarios | `WorkforceScenarioResource` | `/admin/workforce-scenarios` | Organisation / Organisation / Workforce | Auditor, Tenant HR Admin |
| Vacancies | `VacanciesPage` | `/admin/workforce-vacancies` | Organisation / Organisation / Workforce | Auditor, Tenant HR Admin |
| Workforce snapshots | `WorkforceSnapshotsPage` | `/admin/workforce-snapshots` | Organisation / Organisation / Workforce | Auditor, Tenant HR Admin |

### 5.7 Services and communication

| Module | Class | URL path | Section / space / group | Who can open |
|---|---|---|---|---|
| Announcements | `AnnouncementResource` | `/admin/announcements` | Communication / Communication / Communication | HR Manager, Tenant HR Admin |
| Delivery log | `NotificationDeliveryResource` | `/admin/notification-deliveries` | Communication / Communication / Communication | Auditor, HR Manager, Tenant HR Admin |
| Notification rules | `NotificationRuleResource` | `/admin/notification-rules` | Communication / Communication / Communication | Account Owner, Auditor, HR Manager, Tenant HR Admin |
| Notification templates | `NotificationTemplateResource` | `/admin/notification-templates` | Communication / Communication / Communication | Account Owner, Auditor, HR Manager, Tenant HR Admin |
| Audiences | `AudienceResource` | `/admin/audiences` | Communication / Communication / Engagement | Auditor, HR Manager, Tenant HR Admin |
| Campaigns | `EngagementCampaignResource` | `/admin/engagement-campaigns` | Communication / Communication / Engagement | Auditor, HR Manager, Tenant HR Admin |
| Feedback inbox | `EmployeeFeedbackResource` | `/admin/employee-feedback` | Communication / Communication / Engagement | HR Manager, Tenant HR Admin |
| Surveys | `SurveyResource` | `/admin/surveys` | Communication / Communication / Engagement | Auditor, HR Manager, Tenant HR Admin |
| Announcements | `AnnouncementsFeed` | `/admin/announcements-feed` | Communication / You / Me | personal page |
| Cases | `GrievanceResource` | `/admin/grievances` | Services / Services / Grievances | Employee, HR Manager, Manager, Tenant HR Admin |
| Categories & handlers | `GrievanceCategoryResource` | `/admin/grievance-categories` | Services / Services / Grievances | HR Manager, Tenant HR Admin |
| Articles | `ArticleResource` | `/admin/articles` | Services / Services / Knowledge | Auditor, Employee, HR Manager, Manager, Tenant HR Admin |
| My HR | `MyHr` | `/admin/my-hr` | Services / You / Me | personal page |
| Categories & SLAs | `TicketCategoryResource` | `/admin/ticket-categories` | Services / Services / Service Desk | HR Manager, Tenant HR Admin |
| HR queue | `TicketResource` | `/admin/tickets` | Services / Services / Service Desk | Auditor, Employee, HR Manager, Manager, Tenant HR Admin |
| SLA policies | `ServiceSlaPolicyResource` | `/admin/service-sla-policies` | Services / Services / Service Desk | Auditor, HR Manager, Tenant HR Admin |
| Service analytics | `ServiceDeskAnalyticsPage` | `/admin/service-analytics` | Services / Services / Service Desk | HR Manager, Tenant HR Admin |
| Service catalogue | `ServiceDefinitionResource` | `/admin/service-definitions` | Services / Services / Service Desk | Auditor, HR Manager, Tenant HR Admin |

### 5.8 Insights

| Module | Class | URL path | Section / space / group | Who can open |
|---|---|---|---|---|
| Dashboard builder | `DashboardResource` | `/admin/dashboards` | Insights / Insights / Analytics | Tenant HR Admin |
| Dashboards | `DashboardViewer` | `/admin/dashboard-viewer` | Insights / Insights / Analytics | Auditor, Executive, HR Manager, Tenant HR Admin |
| People analytics | `PeopleAnalyticsPage` | `/admin/people-analytics` | Insights / Insights / Analytics | Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Tenant HR Admin |
| Reports | `ReportResource` | `/admin/reports` | Insights / Insights / Analytics | Auditor, Executive, HR Manager, Tenant HR Admin |
| Workforce Command Centre | `WorkforceCommandCentre` | `/admin/workforce-command-centre` | Insights / Insights / Analytics | Auditor, Executive, Tenant HR Admin |
| Workforce Intelligence | `WorkforceIntelligence` | `/admin/workforce-intelligence` | Insights / Insights / Analytics | Executive, HR Manager, Tenant HR Admin |
| AI interactions | `AiInteractionResource` | `/admin/ai-interactions` | Insights / Insights / Audit | Auditor, Tenant HR Admin |
| Change history | `AuditEventResource` | `/admin/audit-events` | Insights / Insights / Audit | Account Owner, Auditor, HR Manager, Payroll Admin, Tenant HR Admin |
| Change intelligence | `ChangeIntelligencePage` | `/admin/change-intelligence` | Insights / Insights / Audit | Account Owner, Auditor, HR Manager, Payroll Admin, Tenant HR Admin |

### 5.9 Administration

| Module | Class | URL path | Section / space / group | Who can open |
|---|---|---|---|---|
| Roles | `RoleResource` | `/admin/roles` | Admin / Setup / Access | Account Owner, Auditor, Tenant HR Admin |
| Users | `UserResource` | `/admin/users` | Admin / Setup / Access | Account Owner, Auditor, HR Executive, HR Manager, Tenant HR Admin |
| All modules | `AdminCentre` | `/admin/admin-centre` | Admin / Setup / Configuration | Account Owner, Auditor, HR Executive, HR Manager, Tenant HR Admin |
| Change Centre | `ConfigurationChangeResource` | `/admin/configuration-changes` | Admin / Setup / Configuration | Auditor, HR Manager, Tenant HR Admin |
| Packs & Blueprints | `ConfigurationPacks` | `/admin/configuration-packs` | Admin / Setup / Configuration | Tenant HR Admin |
| What do you want to configure? | `ConfigurationFinder` | `/admin/configuration-finder` | Admin / Setup / Configuration | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Custom fields | `CustomFieldResource` | `/admin/custom-fields` | Admin / Setup / Customisation | Auditor, Tenant HR Admin |
| Feature flags | `TenantFeatureResource` | `/admin/tenant-features` | Admin / Setup / Customisation | Account Owner, Auditor, Tenant HR Admin |
| Forms | `FormResource` | `/admin/forms` | Admin / Setup / Customisation | Auditor, HR Manager, Tenant HR Admin |
| Settings | `TenantSettingResource` | `/admin/tenant-settings` | Admin / Setup / Customisation | Account Owner, Auditor, Tenant HR Admin |
| Country packs | `CountryPacksPage` | `/admin/country-packs-page` | Admin / Platform / Enterprise | Account Owner, Asset Admin, Attendance Admin, Auditor, Executive, HR Executive, HR Manager, Manager, Payroll Admin, Performance Admin, Tenant HR Admin |
| Exchange rates | `ExchangeRateResource` | `/admin/exchange-rates` | Admin / Platform / Enterprise | Tenant HR Admin |
| Security policy | `SecurityPolicyPage` | `/admin/security-policy-page` | Admin / Platform / Enterprise | Account Owner, Tenant HR Admin |
| Single sign-on | `SsoConnectionResource` | `/admin/sso-connections` | Admin / Platform / Enterprise | Tenant HR Admin |
| Webhooks | `WebhookEndpointResource` | `/admin/webhook-endpoints` | Admin / Platform / Enterprise | Auditor, Tenant HR Admin |
| API keys | `ApiKeyResource` | `/admin/api-keys` | Admin / Platform / Integrations | Tenant HR Admin |
| Inbound events | `InboundEventResource` | `/admin/inbound-events` | Admin / Platform / Integrations | Tenant HR Admin |
| Integration hub | `IntegrationSystemResource` | `/admin/integration-systems` | Admin / Platform / Integrations | Tenant HR Admin |
| Data export | `DataExportPage` | `/admin/data-export` | Admin / Platform / Platform | Account Owner |
| Policies | `PolicyResource` | `/admin/policies` | Workflows / Workflows / Policies | Account Owner, Attendance Admin, Auditor, HR Manager, Tenant HR Admin |
| Runs | `WorkflowInstanceResource` | `/admin/workflow-instances` | Workflows / Workflows / Workflows | Auditor, HR Manager, Tenant HR Admin |
| Workflows | `WorkflowResource` | `/admin/workflows` | Workflows / Workflows / Workflows | Auditor, HR Manager, Tenant HR Admin |
