# MARKEDGE PEOPLEOS — THE EMPLOYEE OPERATING SYSTEM

One person. One record. One journey. One platform.

> Master development blueprint (130 sections). Source of truth for product scope, principles and phasing.
> Engineering notes for what has actually been built live in `docs/architecture/`.

---

## 1. PRODUCT VISION

Build a world-class SaaS Human Resource Management / People Operating System that manages the complete employee lifecycle:

RMS / Recruitment -> Preboarding -> Onboarding -> Employee Master -> Attendance -> Leave -> Payroll -> Compliance -> Performance -> Goals / OKRs -> Skills / Career -> Learning -> Compensation -> Assets -> Documents -> Letters -> HR Service Desk -> Grievances -> Communication -> Exit -> Full & Final Settlement -> Exit Interview -> Alumni -> Post-exit document services

Core positioning: MARKEDGE PEOPLEOS "The Employee Operating System"

Core promise: "From first hello to lifelong connection."

The product should not be positioned as another conventional HRMS. The key differentiation should be:

1. One People Record
2. One Employee Lifecycle
3. One Configurable Policy Engine
4. One Configurable Workflow Engine
5. One Employee Experience
6. One Compliance Architecture
7. One Audit / Change Intelligence Layer
8. One AI / Analytics Layer
9. One Integration Hub

The fundamental concept:

- Traditional HRMS: Modules -> Transactions -> Reports
- PeopleOS: Person -> Life Event -> Policy -> Workflow -> Automation -> Experience -> Intelligence

## 2. PRODUCT PRINCIPLES

1. No unnecessary hardcoding. If a business rule can reasonably be configured by an authorised tenant administrator, make it configurable.
2. No client-specific code forks. Client-specific requirements should be handled through: configuration, custom fields, custom forms, policies, workflows, rules, extensions, integrations.
3. Every material change must be auditable. Record: who, what, when, before, after, reason, approval, effective date, source.
4. Historical data must never be silently rewritten. Use effective-dated and versioned records.
5. Statutory rules must be protected. Customers can configure business rules, but cannot accidentally override protected statutory calculations.
6. Employee experience comes first. A fresher should be able to use the employee portal with minimal training.
7. Configuration must be tenant-specific. Tenant A's policies, workflows, data and configuration must never affect Tenant B.
8. Configuration should be reversible and traceable.
9. Core business data should remain relational. Use metadata/JSON for flexible configuration, not as a replacement for core relational models.
10. Web, mobile, integrations and AI should use the same domain services and business rules.

## 3. PRODUCT ARCHITECTURE

Three major layers: A. PEOPLE CORE, B. CONFIGURATION PLATFORM, C. EXPERIENCE. Above them: D. INTELLIGENCE / ANALYTICS / AI. Beside them: E. INTEGRATION HUB.

```
MARKEDGE PEOPLEOS
+-- PEOPLE CORE: Identity, Organisation, People, Employment, Lifecycle, Attendance, Leave, Payroll,
|                Compliance, Performance, Goals, Skills, Learning, Compensation, Assets, Documents,
|                Letters, Service Desk, Grievance, Communication, Knowledge, Exit, Alumni
+-- CONFIGURATION PLATFORM: Policy Engine, Rule Engine, Workflow Engine, Approval Engine, Escalation Engine,
|                Form Builder, Custom Field Engine, Template Engine, Configuration Versioning,
|                Effective Dating, Configuration Packs
+-- EXPERIENCE: Employee, Manager, HR, Payroll, Admin, Mobile
+-- INTELLIGENCE: Reporting, Analytics, AI, Anomaly Detection
+-- INTEGRATION HUB: RMS, Biometric, ERP, Banking, Email, SMS, WhatsApp, SSO, APIs, Webhooks
```

## 4. ONE EMPLOYEE = ONE LIFETIME RECORD

The central architectural principle: ONE PERSON = ONE LIFETIME RECORD.

The system should not create unrelated records for payroll employee, attendance employee, performance employee, exit employee, alumni employee. Instead:

PERSON -> Person Master, Candidate history / RMS reference, Employee, Employment history, Organisation history, Reporting history, Salary history, Attendance history, Leave history, Payroll history, Performance history, Learning history, Skills, Assets, Documents, Grievances, Recognition, Promotions, Transfers, Exit, Alumni.

## 5. COMPLETE PRODUCT MODULE CATALOGUE

1. People Core 2. Organisation 3. Employee Lifecycle 4. Onboarding 5. Attendance 6. Leave 7. Payroll 8. Compliance 9. Performance 10. Goals & OKRs 11. Skills & Career 12. Learning 13. Compensation 14. Assets 15. Documents 16. Letters 17. HR Service Desk 18. Grievances 19. Engagement 20. Communication 21. Surveys 22. Workforce Planning 23. Analytics 24. Exit & Full and Final Settlement 25. Alumni 26. Integrations 27. AI 28. Administration & Configuration 29. Audit & Change Intelligence

## 6. ADMIN CONTROL CENTRE

A foundational product module, not an ordinary settings page. The tenant's authorised administrator should be able to configure the HRMS without developer intervention wherever configuration is reasonably possible.

Main sections: Organisation, People Setup, Attendance, Leave, Payroll, Compliance, Performance, Learning, Assets, Documents, Letters, Workflows, Approvals, Escalations, Notifications, Knowledge Base, Communication, Branding, Roles, Permissions, Integrations, Customisation, Audit & Change History.

## 7. ORGANISATION CONFIGURATION

Tenant admins can configure: Companies, Legal Entities, Locations, Branches, Business Units, Divisions, Departments, Teams, Functions, Cost Centres, Profit Centres, Levels, Grades, Job Families, Designations, Employment Types, Employee Categories, Work Modes.

Example: ABC Group -> ABC Technologies Pvt Ltd (Delhi, Mumbai, Bangalore), ABC Services Pvt Ltd (Delhi, Pune), ABC Consulting LLP (Noida).

Each legal entity may have its own: payroll, statutory registrations, holiday calendars, salary structures, policies, approval hierarchy, branding, bank/payment settings.

## 8. MULTI-COMPANY / MULTI-ENTITY

A single tenant may operate multiple legal entities. Support: group-level administration, company-level administration, company-specific payroll, company-specific statutory settings, company-specific policies, company-specific branding, consolidated reporting, entity-specific permissions. Do not duplicate the entire product for every company.

## 9. ORGANISATION HIERARCHY

Support configurable hierarchy such as: CEO -> President -> Business Head -> Regional Head -> Cluster Head -> Department Head -> Manager -> Team Leader -> Executive. Another customer may use: CEO -> CHRO -> VP HR -> Director HR -> HR Manager -> HR Executive.

The hierarchy must be data/configuration driven. Do not hardcode CEO -> VP -> Manager -> Employee.

Support: organisation hierarchy, reporting hierarchy, functional reporting, dotted-line reporting, matrix reporting, HRBP, mentor, buddy, project manager, secondary manager.

## 10. ORGANISATION DESIGNER

Provide a visual hierarchy builder. Admin can: add node, move node, connect node, rename node, deactivate node, assign manager, create branch, reorder hierarchy. Every structural change must create an audit record. The organisation chart should be interactive and searchable.

## 11. LEVELS, GRADES, DESIGNATIONS

Support independently: Levels (L1..L6), Grades (G1..G4), Designations (Software Engineer, Senior Engineer, Tech Lead, Product Manager, ...).

Designation configuration can include: name, code, level, grade, job family, department, default reporting level, employment types, status, effective date. Admin must be able to add, edit, deactivate and create new designations without development.

## 12. EMPLOYEE CATEGORIES AND EMPLOYMENT TYPES

Employee categories: Permanent, Probationer, Contract, Consultant, Intern, Apprentice, Temporary, Part-time. Employment types: Full-time, Part-time, Fixed-term, Contract, Consultant, Apprentice, Intern. These should remain configurable.

## 13. WORK SCHEDULE AND SHIFT ENGINE

Admin should be able to create: General, Morning, Evening, Night, Cross-midnight, Flexible, Rotational, Split shifts and Custom schedules.

Shift configuration: start time, end time, cross midnight, break, working hours, grace in, grace out, late rule, early leaving rule, overtime eligibility, applicable days, weekly offs.

Example: Night Shift 22:00 - 07:00, Cross Midnight = Yes, Break = 60 minutes, Grace = 15 minutes.

## 14. WORKING HOURS CONFIGURATION

Admin can configure: working days, daily hours, weekly hours, break, core hours, flexible timing, work schedule, overtime eligibility. Do not hardcode "8 hours = full day". Working hours must be policy-driven (Company A = 8h, B = 7.5h, C = 9h, all without code changes).

## 15. HOLIDAY ENGINE

Support multiple holiday calendars (Delhi, Mumbai, Bangalore, Factory, Regional, International). Assignment based on: company, legal entity, location, department, employee group, employee. Admin can add/edit/deactivate holidays.

## 16. EMPLOYEE MASTER / EMPLOYEE 360

Identity: employee ID, legal name, preferred name, photograph, DOB, gender, nationality, contact.
Address: current, permanent, correspondence.
Family: spouse, parents, children, dependents, nominees, emergency contacts.
Employment: joining date, confirmation date, company, location, department, division, designation, level, grade, employment type, employee category, work mode, reporting manager, functional manager, HRBP.
Statutory: PAN, UAN, ESIC/IP, tax data, professional tax data, other applicable statutory information.
Banking: account, IFSC, bank.
Qualifications: education, certifications, experience.
Skills: skills, proficiency, certifications.

Sensitive fields require enhanced permissions.

## 17. EMPLOYEE 360 UI

Tabs: Overview, Timeline, Employment, Organisation, Attendance, Leave, Payroll, Performance, Goals, Learning, Skills, Documents, Assets, Requests, Grievances, Exit, Alumni. The profile should show a visual employee journey.

## 18. EMPLOYEE TIMELINE

Create a chronological People Timeline. Example: 26 Sep 2026 Salary revised ₹80,000 -> ₹95,000; 15 Sep 2026 Designation changed Manager -> Senior Manager; 01 Aug 2026 Department changed Finance -> Sales; 10 Jul 2026 Leadership programme completed; 10 May 2026 Transferred to Delhi; 01 Apr 2026 Salary revision; 28 May 2025 Joined company. Every material lifecycle event should appear here.

## 19. EMPLOYEE LIFECYCLE ENGINE

Lifecycle states: Pre-Employee, Preboarding, Onboarding, Joined, Probation, Confirmed, Active, Transferred, Promoted, On Leave, Suspended, Notice Period, Exited, Alumni. Lifecycle transitions should be configurable where appropriate.

## 20. LIFE EVENT ENGINE

Core events: Joined, Confirmed, Promoted, Transferred, Salary Changed, Manager Changed, Bank Changed, Dependent Added, Department Changed, Designation Changed, Exit Initiated, Exit Completed, Alumni Created.

One life event may trigger several actions. Example: Promotion approved -> designation updated -> grade updated -> salary updated -> payroll updated -> promotion letter generated -> employee notified -> org chart updated -> audit recorded -> timeline updated.

## 21. ONBOARDING

RMS integration: RMS -> Candidate selected -> Offer accepted -> Pre-employee created -> HRMS onboarding.

Preboarding: personal information, documents, bank, tax, statutory data, emergency contact, education, previous employment, BGV consent, policy acknowledgement, e-signatures.

Day 1: HR introduction, ID card, laptop, email, manager meeting, team introduction, policy acknowledgement. 30/60/90-day onboarding should be configurable.

## 22. BACKGROUND VERIFICATION

Support: identity, address, education, previous employment, reference, document verification, legally appropriate checks, BGV vendor integrations, consent, audit trail. RMS can initiate the BGV process and HRMS can track it.

## 23. ATTENDANCE ENGINE

Sources: biometric, mobile, web, API, manual, import, kiosk, GPS/geofence where applicable.

Support: shifts, flexible shifts, rotational shifts, night shifts, split shifts, grace, late, early leaving, overtime, weekly offs, holidays, WFH, field duty, on duty, permissions, regularisation, missed punches, half day, LOP, attendance exceptions. Create an Attendance Exception Centre.

## 24. BIOMETRIC INTEGRATION HUB

Do not tightly couple attendance to one biometric vendor. Architecture: Biometric Device -> Integration Adapter -> Raw Punch -> Normalisation -> Attendance Engine -> Attendance Record -> Payroll. Adapter-based integrations so eSSL, Matrix, ZKTeco and future providers connect without rewriting the core attendance system.

## 25. LEAVE ENGINE

Support: annual, casual, sick, earned, privilege, maternity, paternity, bereavement, compensatory, restricted holiday, optional holiday, unpaid, custom leave types.

Rules: accrual, carry-forward, encashment, expiry, probation restrictions, negative balance, half-day, hourly leave, location-specific, grade-specific, employment-type-specific. Tenant admins should be able to create custom leave types.

## 26. LEAVE POLICY BUILDER

Admin can configure: Policy name, Applicable employees, Annual entitlement, Accrual frequency, Carry forward, Encashment, Probation eligibility, Half-day, Negative balance, Approval, Expiry. Policy assignment should support rules. Example: IF Company = ABC AND Location = Delhi AND Employment Type = Full Time THEN Leave Policy = Standard Leave.

## 27. ATTENDANCE RULE BUILDER

Example Late Coming Policy: Grace = 15 minutes; After 3 late marks = configured action; After 5 late marks = configured action; Regularisation = allowed; Manager approval = required; HR override = allowed. All rules should be configurable and versioned.

## 28. OVERTIME ENGINE

Support: minimum OT, weekday rate, weekend rate, holiday rate, maximum OT, approval, eligibility, payroll treatment. Statutory limits and applicable law must be protected by the compliance layer.

## 29. PAYROLL ENGINE

Payroll must support arbitrary salary structures. Example CTC: Basic, HRA, Special Allowance, Conveyance, LTA, Bonus, Incentive, Commission, Employer PF, Employer ESIC, Gratuity, Other Benefits. Another client may have completely different components.

Salary component properties: taxable, PF applicable, ESI applicable, PT applicable, included in CTC, included in gross, recurring, proratable, arrear eligible, statutory classification.

## 30. PAYROLL FORMULA ENGINE

Architecture: Component -> Eligibility Rule -> Formula -> Proration -> Statutory Treatment -> Tax Treatment -> Payroll Result. Non-statutory components: highly configurable. Statutory components: controlled templates and protected rules. Never scatter payroll formulas through controllers and models.

## 31. PAYROLL CONTROL ROOM

Example: September Payroll. Employees: 4,827; Gross Payroll: ₹8.42 Cr; Net Payroll: ₹6.91 Cr; Exceptions: 12 (Missing Bank: 4, Tax Issues: 3, Attendance Issues: 5).

Workflow: Calculate -> Validate -> Review -> Approve -> Finalize -> Generate Payslips -> Payment/Bank Integration -> Audit.

## 32. INDIA COMPLIANCE ARCHITECTURE

Create a versioned compliance framework. India Compliance Pack: EPF, ESI, Professional Tax, Labour Welfare Fund, TDS, Form 16, applicable labour records/registers, state-specific requirements, applicable returns/reports.

The system must account for: applicable jurisdiction, establishment type, state, legal entity, effective date, rule version. Do not create one static "India payroll formula". Compliance rules should be versioned and updateable. Statutory rules should be reviewed against current government notifications before production use. The compliance layer must prevent ordinary tenant configuration from overriding protected statutory calculations.

## 33. COMPLIANCE CONTROL CENTRE

Show: Applicable, Not Applicable, Pending, Completed, Due, Overdue. Reports/registers should be jurisdiction-aware. Categories: employee registers, wage records, attendance, working hours, overtime, leave, deductions, statutory records, EPFO records, ESI records, TDS records, Form 16, PT, LWF, other applicable state/establishment records. Do not promise one identical register list for every Indian employer.

## 34. PERFORMANCE MANAGEMENT

Support: KRA, KPI, OKR, goals, competencies, behaviour, self-review, manager review, peer review, 360 feedback, continuous feedback, check-ins, one-on-ones, appraisal cycles, calibration, PIP, promotion recommendation, succession.

Goal cascade: Company Goal -> Business Goal -> Department Goal -> Team Goal -> Employee Goal.

## 35. PERFORMANCE CONFIGURATION

Tenant admins can configure: Performance cycles, Rating scales, KRA libraries, Competencies, Goal templates, Weightages, Review stages, Self review, Manager review, Peer review, 360, Calibration, Final rating. Example rating scale: 1 Needs Improvement, 2 Developing, 3 Meets Expectations, 4 Exceeds Expectations, 5 Exceptional. Customers can create different scales.

## 36. SKILLS AND CAREER

Create a Career Passport: skills, proficiency, certifications, projects, training, experience, performance, aspirations, career paths. Career path example: Senior Engineer -> Tech Lead -> Engineering Manager -> Director. Show skill gaps and recommended development plans.

## 37. LEARNING / LMS

Support: courses, learning paths, videos, documents, assessments, quizzes, assignments, classroom training, virtual training, trainers, attendance, completion, certification, expiry, mandatory compliance training.

## 38. ASSET MANAGEMENT

Asset lifecycle: Procurement -> Inventory -> Assignment -> Employee -> Transfer -> Repair -> Replacement -> Return -> Disposal/Reassignment. Support: laptop, desktop, monitor, mobile, SIM, ID card, access card, software licence, vehicle, tools, equipment, other company property. Exit clearance should connect with asset recovery.

## 39. DOCUMENT MANAGEMENT

Categories: identity, education, previous employment, tax, statutory, company, training, medical where appropriate, exit, other. Support: upload, verification, approval, expiry, versioning, access history, download history, retention, archival. Store actual documents in secure object storage, not directly in the relational database.

## 40. LETTER FACTORY

Templates: offer, appointment, confirmation, promotion, increment, transfer, relieving, experience, salary certificate, employment certificate, NOC, warning, show cause, appreciation, custom letters. Variables such as `{{employee.name}}`, `{{employee.designation}}`, `{{employee.joining_date}}`, `{{employee.salary}}`, `{{employee.department}}`, `{{company.name}}`. Support: branding, approvals, versioning, PDF generation, e-sign integration where applicable, audit.

## 41. CUSTOM FIELD ENGINE

Tenant admins can add fields without database development. Example: Shirt Size (Dropdown: Small, Medium, Large, XL, XXL). Properties: Required, Visible to employee, Visible to manager, Visible to HR, Searchable, Reportable.

Custom fields should support: employee, department, asset, leave, performance, exit, grievance, service request, other configurable entities. Do not use generic custom fields for core payroll/attendance facts that require strong relational querying.

## 42. FORM BUILDER

Admin can create forms using: text, number, date, dropdown, radio, checkbox, file, employee selector, department selector, location selector, signature. Form lifecycle: Create -> Version -> Publish -> Collect -> Validate -> Approve -> Audit.

## 43. POLICY ENGINE

A core differentiator. Policies may cover: leave, attendance, overtime, payroll, performance, exit, benefits, approvals, work schedules. Example: IF Department = Sales AND Employee Category = Permanent THEN Leave Policy = Sales Leave, Attendance Policy = Sales Attendance.

## 44. WORKFLOW ENGINE

Visual no-code workflow builder. Nodes: Start, Form, Approval, Condition, Assignment, Notification, Task, Wait, Webhook, Document, End. Example: START -> Employee submits request -> Manager approval -> Condition (YES -> HR approval, NO -> End) -> Generate document -> Notify employee -> END. Workflow versions must be stored.

## 45. APPROVAL ENGINE

Support: single approver, sequential, parallel, conditional, hierarchy-based, amount-based, majority, dynamic approver. Example: Leave > 3 days -> Manager -> Department Head -> HR.

## 46. ESCALATION ENGINE

Example: After 24 hours reminder to manager; after 48 hours escalate to manager's manager; after 72 hours escalate to HR. Escalations must be configurable.

## 47. NOTIFICATION ENGINE

Architecture: Event -> Notification Rule -> Audience -> Channel -> Template -> Delivery -> Tracking. Channels: in-app, email, SMS, WhatsApp, push. Examples: Leave approved -> Employee; Resignation submitted -> Employee confirmation -> Manager -> HR; Document expiring -> Employee -> HR.

## 48. HR SERVICE DESK

Employee should be able to ask HR instead of relying on email/WhatsApp. Support: requests, tickets, queries, complaints, categories, assignment, SLA, escalation, attachments, internal notes, knowledge links, resolution, satisfaction. Example: "I need my experience letter." The system creates and tracks the request.

## 49. GRIEVANCE MANAGEMENT

Separate from ordinary HR support. Support: grievance categories, confidential cases, restricted cases, anonymous capability where appropriate, assignments, investigation, evidence, actions, escalation, resolution, audit. Sensitive matters such as POSH-related cases require strict permissions and configurable access.

## 50. KNOWLEDGE BASE

Categories: HR policies, attendance, leave, travel, POSH, WFH, IT, code of conduct, expense, benefits, other. Support: article, version, effective date, audience, search, acknowledgement, mandatory reading.

## 51. COMMUNICATION CENTRE

Support: announcements, circulars, newsletters, policy publications, instructions, employee messages, targeted audiences, acknowledgement, read tracking. Audience selection: company, entity, department, location, designation, level, employee category, custom segment.

## 52. EMPLOYEE EXPERIENCE

Employee portal: MY PEOPLEOS. Main areas: My Day, My Attendance, My Leave, My Pay, My Performance, My Goals, My Growth, My Learning, My Documents, My Assets, My Requests, My Benefits, My Company, My HR. Design principle: One screen. One decision. One next action.

## 53. EMPLOYEE DASHBOARD

"Good morning, Rahul" [Check In]. Attendance, Leave, Pay, Performance, Learning. Needs Attention: Tax declaration, Policy acknowledgement. Quick Actions: Apply Leave, Regularise, View Payslip, Ask HR.

## 54. MANAGER EXPERIENCE

MY TEAM: 18 Employees, 16 Present, 1 Leave, 1 Absent. Needs Attention: 3 Leave approvals, 2 Regularisations, 1 Performance review, 1 Resignation. Managers should not be overloaded with HR administration.

## 55. HR EXPERIENCE

People Control Centre: Headcount, New Joiners, Exits, Attendance Exceptions, Payroll Exceptions, Pending Approvals, Open Grievances, Documents Expiring, Onboarding Tasks.

## 56. EXECUTIVE EXPERIENCE

Workforce Command Centre: Headcount, People Cost, Attrition, Absenteeism, Open Positions, High Performers, Critical Skills, Workforce Trends.

## 57. "NEEDS ATTENTION" DESIGN PATTERN

Every user should see a personalised Needs Attention area. Employee: missing document, policy acknowledgement, pending request. Manager: leave approvals, attendance regularisations, performance reviews. HR: payroll exceptions, BGV pending, document expiry, grievances. Payroll: missing bank data, tax issues, payroll anomalies.

## 58. GLOBAL SEARCH

Search should find: employee, employee ID, department, designation, document, asset, ticket, request, policy. Example "Rahul" -> Rahul Sharma EMP10284 Senior Manager Finance Delhi. Future natural language: "Employees in Delhi who joined this year", "Employees whose probation ends this month", "Employees reporting to Amit".

## 59. EXIT MANAGEMENT

Exit types: resignation, termination, retirement, contract expiry, absconding, death, mutual separation, other. Lifecycle: Resignation -> Notice -> Knowledge Transfer -> Manager Clearance -> IT Clearance -> Finance Clearance -> Asset Clearance -> HR Clearance -> F&F -> Documents -> Exit Interview -> Alumni.

## 60. FULL & FINAL SETTLEMENT

Earnings: unpaid salary, leave encashment, incentives, bonus, other payable items. Deductions: LOP, notice recovery, loans, advances, asset recovery, tax, other configured deductions. Result: NET F&F. Every amount must be explainable and traceable.

## 61. EXIT INTERVIEW

Capture: reason for leaving, manager experience, compensation, culture, workload, location, career opportunities, work environment, suggestions. Aggregate analytics should distinguish actual employee responses from system-generated inference.

## 62. ALUMNI PLATFORM

Exit should not necessarily mean the end of the relationship. Alumni portal may support: experience letter, relieving letter, employment verification, salary certificate, payslips, Form 16, other permitted documents, reference requests. Former employee can request a document years later. System: Authenticate -> Request -> Verify -> Approval if required -> Generate -> Deliver -> Audit.

## 63. AUDIT & CHANGE INTELLIGENCE

Mandatory and built from Phase 1. Every material action must create an audit event. Record: who, user ID, role, tenant, action, module, entity type, entity ID, date/time, IP, user agent/device, source, request ID, before values, after values, reason, approval, effective date, metadata.

Example: SHIFT CONFIGURATION CHANGED. Changed by: Shivangi Rajawat, Tenant HR Admin. Date: 26 Sep 2026, 10:42 AM. Record: Night Shift / SHIFT-008. Previous: 22:00 - 07:00. New: 21:30 - 06:30. Effective: 01 Oct 2026. Reason: New operational timing. Source: Admin Control Centre.

## 64. FIELD-LEVEL CHANGE HISTORY

Do not record only "Shift updated". Record per field: Start Time 22:00 -> 21:30; End Time 07:00 -> 06:30; Grace 15 min -> 10 min; OT Eligible Yes -> Yes.

## 65. AUDIT EVENT TYPES

Data: CREATE, UPDATE, DELETE, RESTORE, IMPORT, EXPORT.
Workflow: SUBMITTED, APPROVED, REJECTED, CANCELLED, ESCALATED, DELEGATED.
Security: LOGIN, LOGOUT, LOGIN_FAILED, PASSWORD_CHANGED, MFA_CHANGED, ROLE_CHANGED, PERMISSION_CHANGED.
Configuration: POLICY_CREATED, POLICY_UPDATED, POLICY_PUBLISHED, POLICY_RETIRED, WORKFLOW_CHANGED, SHIFT_CHANGED, FORM_CHANGED.
Payroll: PAYROLL_STARTED, PAYROLL_CALCULATED, PAYROLL_APPROVED, PAYROLL_FINALIZED, PAYROLL_REOPENED, PAYSLIP_GENERATED.
Lifecycle: JOINED, CONFIRMED, PROMOTED, TRANSFERRED, SALARY_CHANGED, MANAGER_CHANGED, EXIT_INITIATED, EXIT_COMPLETED, ALUMNI_CREATED.

## 66. SENSITIVE ACCESS AUDITING

For sensitive information, viewing itself may be audited: bank details, salary, tax information, statutory details, confidential grievance, sensitive documents. Example: Payroll Admin viewed bank details of Employee EMP10284 at 10:42, Purpose: Payroll Processing.

## 67. BULK OPERATION AUDITING

Operation ID: BULK-2026-00982; Performed by: HR Admin; Employees: 250; Changed: Department From Finance To Operations; Started 10:42:10; Completed 10:43:21; Success 247; Failed 3. Each failed record must remain traceable.

## 68. AUDIT IMMUTABILITY

Tenant admins must not be able to edit/delete their own audit records. Audit records should be: append-only, immutable, timestamped, permission controlled, retention controlled. For enterprise use, consider cryptographic integrity verification.

## 69. CONFIGURATION VERSIONING

Every configurable object should support versions. Example: Version 1 (01 Apr 2025 - 31 Mar 2026) 09:30 - 18:30; Version 2 (01 Apr 2026 - Present) 09:00 - 18:00. Never overwrite historical configuration.

## 70. EFFECTIVE-DATED DATA

Use effective dating for: salary, designation, grade, department, manager, location, shifts, working hours, leave policies, payroll policies, statutory rules. Fields: `effective_from`, `effective_to`. This preserves historical correctness.

## 71. CONFIGURATION CHANGE CENTRE

Provide: Pending Changes, Scheduled Changes, Recently Published, Rejected, History. High-risk changes can require approval. Risk levels: LOW (designation, department, category), MEDIUM (leave policy, shift, approval workflow), HIGH (payroll formula, statutory configuration, tax configuration, permission changes).

## 72. CONFIGURATION APPROVAL

Example: HR Admin -> Creates payroll rule -> Pending Approval -> Payroll Head -> Approved -> Effective 01 Oct. Audit: Created by, Approved by, Approval date, Effective date, Version.

## 73. CONFIGURATION IMPACT PREVIEW

For major changes show "What will this change affect?". Example changing shift: Employees affected 384; Departments: Sales, Operations, Support; Attendance impact: Yes; Payroll impact: Potential; Effective: 01 Oct 2026.

## 74. CONFIGURATION SIMULATION MODE

For high-risk changes provide TEST / SIMULATE. Example payroll change: Current payroll ₹2.31 Cr; Projected ₹2.36 Cr; Difference +₹5.2 L; Employees affected 1,284. Only after review: PUBLISH.

## 75. CONFIGURATION ROLLBACK

Allow authorised users to restore an earlier configuration version. Rollback itself creates a new audit event. Never delete history.

## 76. CONFIGURATION PACKS

Starting templates: Startup, IT Company, BPO, Manufacturing, Retail, Consulting, Enterprise. Starting configurations, not mandatory industry rules. Client can modify after applying a pack.

## 77. CONFIGURATION BLUEPRINTS

Allow a tenant to export/import configuration only. Useful for group companies, staging to production, implementation, testing, migrations. Never include sensitive employee data in a configuration blueprint.

## 78. ROLES AND PERMISSIONS

Default roles: Platform Super Admin, Tenant Super Admin, Tenant HR Admin, HR Manager, HR Executive, Payroll Admin, Attendance Admin, Performance Admin, Asset Admin, Manager, Employee, Auditor. Roles should be configurable.

## 79. GRANULAR PERMISSIONS

Use resource.action style: `employee.view`, `employee.create`, `employee.update`; `payroll.view`, `payroll.calculate`, `payroll.approve`, `payroll.finalize`; `configuration.view`, `configuration.update`, `configuration.publish`; `workflow.create`, `workflow.update`, `workflow.publish`.

## 80. FIELD-LEVEL SECURITY

Sensitive fields: salary, bank account, PAN, Aadhaar reference, tax information, medical information, grievance details. Permission dimensions: view, edit, export. Example: Manager can see designation/attendance/leave/performance but not salary/bank/tax. Payroll Admin can see salary/bank/tax but may not access confidential grievances.

## 81. MULTI-TENANT SECURITY

Every tenant-scoped table should have `tenant_id`. Request flow: Authenticated User -> Tenant -> Company/Scope -> Authorization -> Data. Build automated tenant isolation tests. Critical acceptance test: Tenant A must never access Tenant B data.

## 82. SECURITY ARCHITECTURE

Implement: MFA, strong password policy, session management, device/session management, rate limiting, TLS, encryption at rest, sensitive field protection, API authentication, signed document URLs, file scanning, backup encryption, audit logging, IP restrictions for enterprise where required, role and field-level security.

## 83. DOCUMENT SECURITY

Never expose direct public file paths. Use: Authenticated request -> Authorization -> Temporary signed URL -> Secure download. Sensitive document access may create audit events.

## 84. REPORTING ENGINE

Do not hardcode hundreds of reports. Build a Report Builder: Dataset -> Fields -> Filters -> Grouping -> Calculated Fields -> Visualization -> Schedule. Export: Excel, CSV, PDF. Schedule reports (e.g. attendance report every Monday).

## 85. DASHBOARD BUILDER

Widgets: KPI, chart, table, trend, calendar, alerts, leaderboard, funnel, heatmap. Dashboards should be role-specific.

## 86. SEARCH

Initially PostgreSQL search where appropriate; at scale OpenSearch / Elasticsearch. Search: employee, employee ID, designation, department, document, request, asset, policy, ticket. Eventually support permission-aware natural-language search.

## 87. API ARCHITECTURE

Versioned APIs: `/api/v1`, `/api/v2`. Resources: employees, attendance, leave, payroll, documents, assets, performance, workflows, reports. Use: API keys, OAuth where appropriate, webhooks, rate limits, tenant-aware authentication.

## 88. DOMAIN EVENTS

`employee.created`, `employee.joined`, `employee.confirmed`, `employee.promoted`, `employee.transferred`, `employee.salary_changed`, `employee.exited`, `employee.alumni_created`, `payroll.processed`, `payroll.finalized`, `leave.approved`, `asset.assigned`, `document.expired`, `workflow.completed`. Example: SalaryChanged -> Payroll update -> Letter generation -> Notification -> Audit -> Employee timeline.

## 89. RMS INTEGRATION

Existing Recruitment Management System remains the recruitment source. Flow: RMS -> Candidate Selected -> Offer Accepted -> HRMS Pre-Employee -> Preboarding -> Onboarding -> Employee. Avoid duplicate recruitment functionality inside HRMS.

## 90. DATA MIGRATION ENGINE

Create an import centre. Imports: employees, attendance, leave, salary, historical payroll, assets, documents. Flow: Upload -> Column Mapping -> Validation -> Preview -> Error Correction -> Approval -> Import -> Audit.

## 91. STAGING IMPORT ARCHITECTURE

Never import raw Excel directly into production tables. Use: Upload -> Staging Tables -> Validation -> Error Report -> Approval -> Production -> Audit.

## 92. MOBILE ARCHITECTURE

Do not duplicate business rules in mobile. Mobile: UI -> API -> Same domain services -> Same policy/workflow rules -> Same audit. Recommended eventual mobile platform: Flutter or React Native. Web remains the primary HR/Admin experience.

## 93. AI ARCHITECTURE

AI should sit above deterministic HR services: AI Assistant -> Permission-aware AI Gateway -> Domain Services -> Actual HRMS Data. AI must not bypass permissions. AI must not directly modify payroll or statutory data without controlled, authorised actions.

## 94. AI FEATURES

Employee Assistant (leave balance, policies, payslip help, attendance help, HR requests). HR Copilot (pending onboarding, probation due, document expiry, workforce questions). Manager Assistant (team attendance, leave, performance, pending actions). Payroll Auditor (unusual salary changes, abnormal deductions, duplicate payment indicators, attendance/payroll anomalies). Policy Assistant (answers using tenant knowledge base and policies). Workforce Analyst (workforce trends, skills, cost, attrition, capacity). AI should augment the system, not replace deterministic calculations.

## 95. AI GOVERNANCE

AI should be permission aware, auditable, explainable where appropriate, controlled for write actions, prevented from bypassing compliance rules. For high-impact employment decisions, avoid opaque automated scoring without proper governance.

## 96. INTERNATIONAL ARCHITECTURE

Do not build global payroll first. Build architecture for country packs: Country -> Jurisdiction -> Currency -> Tax Framework -> Social Security -> Labour Rules -> Payroll Rules -> Documents -> Localisation. India is the first country pack. Later: UAE, USA, UK, Singapore, Australia, Canada, other countries.

## 97. TECHNOLOGY STACK

Backend: Laravel 13, PHP 8.5. Admin: Filament 5, Livewire, Alpine.js, Tailwind CSS. Database: PostgreSQL recommended; MySQL 8.4 also viable if existing standardisation is important. Caching: Redis. Queues: Laravel Queue, Laravel Horizon. Realtime: Laravel Reverb / WebSockets as needed. Storage: S3-compatible object storage. Search: PostgreSQL initially, OpenSearch / Elasticsearch at scale. Infrastructure: Nginx, Cloudflare, Queue Workers, Scheduler, Monitoring, Automated Backups.

## 98. DATABASE / DOMAIN STRUCTURE

```
app/
+-- Domain/
|   +-- Identity/  Organisation/  People/  Employment/  Lifecycle/  Attendance/  Leave/  Payroll/
|   +-- Compliance/  Performance/  Learning/  Assets/  Documents/  Workflow/  Notifications/
|   +-- ServiceDesk/  Exit/  Alumni/  Reporting/  Integration/  AI/  Audit/
+-- Application/
+-- Infrastructure/
+-- Support/
```

## 99. DATABASE TABLE GROUPS

Platform: tenants, tenant_settings, tenant_features, tenant_subscriptions, tenant_domains.
Organisation: companies, legal_entities, locations, branches, business_units, divisions, departments, teams, cost_centres, profit_centres.
Structure: levels, grades, job_families, designations, employment_types, employee_categories, work_modes, reporting_relationships, organisation_nodes.
People: people, employees, employee_profiles, employee_contacts, employee_addresses, employee_dependents, employee_emergency_contacts, employee_qualifications, employee_experiences, employee_skills, employee_certifications.
Employment: employee_employments, employee_designation_histories, employee_department_histories, employee_manager_histories, employee_salary_histories, employee_location_histories, employee_grade_histories, employee_status_histories.
Attendance: attendance_devices, attendance_sources, attendance_punches, attendance_records, attendance_rules, attendance_exceptions, attendance_regularisations, shifts, shift_breaks, shift_rules, shift_assignments, work_schedules, work_schedule_days, work_schedule_assignments.
Leave: leave_types, leave_policies, leave_policy_rules, leave_balances, leave_transactions, leave_requests, holiday_calendars, holidays, holiday_calendar_assignments.
Payroll: salary_structures, salary_components, salary_component_rules, employee_salary_assignments, payroll_periods, payroll_runs, payroll_entries, payroll_adjustments, payroll_deductions, payroll_earnings, payslips.
Compliance: compliance_jurisdictions, compliance_frameworks, compliance_rules, compliance_versions, compliance_outputs, compliance_deadlines.
Workflow: workflows, workflow_versions, workflow_nodes, workflow_edges, workflow_instances, workflow_tasks, workflow_actions, workflow_conditions, approval_rules, escalation_rules.
Audit: audit_events, audit_event_changes, audit_contexts, audit_approvals.
Configuration: configuration_categories, configuration_items, configuration_versions, configuration_assignments, custom_fields, custom_field_options, forms, form_versions, form_fields, form_field_options, form_submissions.
Performance: performance_cycles, performance_templates, performance_goals, performance_reviews, performance_ratings, competencies, feedback, one_on_ones, pip_records, calibrations.
Learning: courses, learning_paths, learning_assignments, learning_enrolments, assessments, certifications, training_sessions.
Assets: asset_categories, asset_models, assets, asset_assignments, asset_movements, asset_repairs, asset_returns, asset_disposals.
Documents: document_categories, document_types, employee_documents, document_versions, document_expiries, document_verifications, document_access_logs.
Letters: letter_templates, letter_variables, letter_requests, generated_letters, letter_versions, letter_approvals.
Service Desk: service_categories, service_types, service_requests, service_tasks, service_comments, service_slas, service_escalations.
Grievance: grievance_cases, grievance_categories, grievance_assignments, grievance_actions, grievance_evidence, grievance_resolutions.
Communication: announcements, newsletters, circulars, policy_publications, audiences, acknowledgements.
Exit: exit_cases, exit_types, notice_periods, clearance_items, exit_tasks, exit_interviews, ff_settlements, ff_components, exit_documents, alumni_profiles.

## 100. EMPLOYMENT HISTORY

Never overwrite important employee history. Use effective-dated tables. Example salary: 01 Apr 2025 - 31 Mar 2026 ₹50,000; 01 Apr 2026 - Present ₹60,000. Similarly for department, designation, manager, grade, location, salary, shift, policy assignment.

## 101. CONFIGURATION VS HARD-CODE BOUNDARY

Hardcode/protect: security, tenant isolation, permission enforcement, data integrity, core audit engine, core platform architecture, protected statutory safeguards, platform subscription/billing logic.

Configure: departments, designations, levels, grades, categories, employment types, shifts, work hours, holidays, leave policies, attendance rules, overtime policies, salary structures, non-statutory formulas, performance cycles, approval workflows, notifications, forms, custom fields, templates, branding, dashboards, reports.

## 102. ADMIN CONFIGURATION EXPERIENCE

ADMIN CONTROL CENTRE navigation:
- Organisation: Companies, Locations, Departments, Divisions, Cost Centres, Organisation Designer
- People Setup: Levels, Grades, Designations, Job Families, Categories, Employment Types, Work Modes
- Attendance: Shifts, Work Schedules, Holidays, Attendance Rules, Overtime
- Leave: Leave Types, Leave Policies, Accrual Rules
- Payroll: Salary Components, Salary Structures, Payroll Policies, Tax Configuration
- Performance: Cycles, Rating Scales, Goals, Competencies
- Workflows: Workflow Builder, Approvals, Escalations, Automations
- Customisation: Custom Fields, Forms, Templates, Branding
- Documents: Categories, Retention, Letter Templates
- Communication: Notifications, Announcements, Knowledge Base
- Access: Roles, Permissions, Security
- Integrations: Biometric, RMS, Finance, SSO, APIs
- Audit: Change History, Security Logs, Configuration History

## 103. ADMIN CONFIGURATION SEARCH

At the top of Admin Control Centre: "What do you want to configure?". Search "working hours" -> Working Hours, Shift Timing, Work Schedule, Overtime, Attendance Rules. Search "designation" -> Designations, Levels, Grades, Job Families. Important for non-technical HR users.

## 104. "WHAT CHANGED?" EXPERIENCE

Every major configuration page should have History. Example: SHIFT POLICY Version 7. What changed: working hours 9.5 -> 9; grace 15 -> 10 minutes; effective from 01 Oct 2026. Changed by: HR Admin. Approved by: HR Head.

## 105. COMPETITIVE POSITIONING

Do not compete only on feature count. Learn from Workday, SAP SuccessFactors, UKG Pro, BambooHR, Rippling, Zoho People, Keka, greytHR, Darwinbox, PeopleStrong, HROne, Pocket HRMS. Do not copy their UI or architecture.

Differentiate on: configurable PeopleOS, lifetime employee record, powerful tenant configuration, no-code workflow/policy engine, India-first compliance architecture, employee-first UX, exit-to-alumni continuity, audit/change intelligence, simulation/impact preview, AI on top of deterministic HR services.

## 106. UNIQUE PRODUCT FEATURES

1. Employee Timeline 2. Needs Attention 3. PeopleOS Control Centre 4. Organisation Designer 5. Policy Engine 6. Workflow Builder 7. Configuration Change Centre 8. Configuration Impact Preview 9. Configuration Simulation Mode 10. Configuration Packs 11. Career Passport 12. Payroll Control Room 13. Compliance Control Centre 14. Alumni Portal 15. HR Service Centre 16. One People Record 17. Life Event Engine 18. Employee Assistant 19. AI Payroll Auditor

## 107. UI/UX PHILOSOPHY

"One screen. One decision. One next action." Avoid complicated ERP-style navigation. Employee: My Day. Manager: My Team. HR: People Control Centre. Payroll: Payroll Control Room. CEO: Workforce Command Centre. Admin: Admin Control Centre. Every interface should be role-specific.

## 108. PRODUCT EXPERIENCE

Employee: "Everything I need from HR is here." Manager: "I know exactly what needs my attention." HR: "I can operate the entire workforce from one place." Admin: "I can configure the system without a developer." CEO: "I understand my workforce immediately."

## 109. INTEGRATION HUB

Create adapters/connectors rather than hardcoding integrations. Possible integrations: RMS, biometric devices, email, SMS, WhatsApp, ERP, accounting, banking, SSO, Microsoft Entra, Google Workspace, Okta, BGV providers, e-signature, other APIs. Architecture: External System -> Adapter -> Integration Service -> Domain Service -> Audit.

## 110. ENTERPRISE FEATURES

Eventually support: SSO, SCIM, advanced API, dedicated tenant infrastructure, data warehouse, enterprise audit, advanced security, IP restrictions, custom retention, advanced reporting, large-scale search, country packs, dedicated support, enterprise SLA.

## 111. PERFORMANCE / SCALABILITY

Target: 100, 500, 5,000, 10,000, 50,000+ and potentially 100,000+ employees. Use: queues, caching, indexing, background jobs, pagination, chunked imports, asynchronous reports, object storage, search infrastructure, read optimisation, analytics warehouse at large scale.

## 112. OBSERVABILITY

Production must have: application logs, queue logs, audit logs, error tracking, database monitoring, slow query monitoring, uptime monitoring, API monitoring, job failure alerts. Every request should have a request/correlation ID linking Request -> Controller -> Service -> Workflow -> Payroll -> Notification -> Audit.

## 113. BACKGROUND JOBS

Use queues for: payroll, biometric sync, attendance processing, notifications, document processing, PDF generation, large reports, imports, exports, analytics, AI jobs, bulk updates.

## 114. SCHEDULED TASKS

Examples: attendance processing, leave accrual, payroll reminders, document expiry, probation reminders, birthday notifications, compliance reminders, scheduled reports, alumni requests, recurring notifications.

## 115. TESTING STRATEGY

Required test layers: 1. Unit 2. Feature 3. Integration 4. Tenant Isolation 5. Permission 6. Payroll 7. Compliance Regression 8. Browser/E2E 9. Performance 10. Security. Payroll and compliance changes must run regression suites.

## 116. PAYROLL TESTING

Test: Basic, HRA, PF, ESI, PT, TDS, Bonus, LOP, OT, Leave Encashment, Arrears, Incentives, Deductions. Validate: gross, deductions, tax, net, employer cost, statutory outputs. Every payroll engine change should run the complete regression suite.

## 117. CONFIGURATION TESTING

Every configurable feature should test: default configuration, custom configuration, modified configuration, future-dated configuration, expired configuration, conflicting configuration, invalid configuration, tenant isolation, permissions, audit generation, rollback, approval, effective date.

## 118. AUDIT TESTING

For every important action verify: audit exists, correct tenant, correct actor, correct action, correct timestamp, correct old value, correct new value, correct reason, correct effective date.

## 119. DEPLOYMENT ARCHITECTURE

Environments: Development -> Testing -> Staging -> Production. Never introduce major payroll/compliance changes directly into production. Production: Nginx, Laravel, PHP, Queue workers, Scheduler, Redis, PostgreSQL/MySQL, Object storage, Search, Monitoring, Backups.

## 120. BACKUP / DISASTER RECOVERY

Implement: automated database backups, object storage backups/versioning, encrypted backups, offsite backup, restore testing, retention policy, disaster recovery runbook. Enterprise tier should eventually have configurable RPO/RTO.

## 121. DEVELOPMENT PHASES

- PHASE 0 — PRODUCT AND ARCHITECTURE: freeze product terminology, architecture, tenancy, security, lifecycle, configuration, audit, UI/UX principles. Deliver Master PRD, Architecture document, ERD, UX system, Security model.
- PHASE 1 — SAAS FOUNDATION: authentication, tenants, users, companies, tenant isolation, roles, permissions, feature flags, settings, audit engine. Production-quality foundation.
- PHASE 2 — ORGANISATION CORE: companies, locations, departments, divisions, business units, cost centres, levels, grades, designations, job families, categories, employment types, hierarchy, Organisation Designer.
- PHASE 3 — EMPLOYEE CORE: employee master, Employee 360, personal data, contacts, family, dependents, qualifications, experience, skills, statutory, banking, employment history, employee timeline.
- PHASE 4 — CONFIGURATION PLATFORM: configuration engine, custom fields, forms, policy engine, rule engine, configuration versioning, effective dating, configuration centre, configuration history, approval, import/export, configuration packs.
- PHASE 5 — WORKFLOW PLATFORM: workflow builder, approval engine, escalation engine, task engine, notification engine, automation, conditions, webhooks.
- PHASE 6 — LIFECYCLE AND ONBOARDING: RMS integration, preboarding, onboarding, BGV, document collection, joining, probation, confirmation, lifecycle events.
- PHASE 7 — ATTENDANCE: shifts, work schedules, holiday, biometric abstraction, attendance, regularisation, overtime, exceptions.
- PHASE 8 — LEAVE: leave types, policies, accrual, balances, requests, approval, encashment, reports.
- PHASE 9 — PAYROLL AND COMPLIANCE: salary structures, components, formulas, payroll, TDS, PF, ESI, PT, LWF, statutory outputs, payroll control room, payslips. Statutory rules must be reviewed and maintained against current official requirements before production use.
- PHASE 10 — PERFORMANCE AND TALENT: goals, KRA, KPI, OKR, appraisal, 360, feedback, competencies, calibration, PIP, career, skills.
- PHASE 11 — LEARNING AND ASSETS: LMS, training, certification, asset management, inventory, asset lifecycle.
- PHASE 12 — EMPLOYEE EXPERIENCE: employee portal, mobile-ready experience, My HR, service desk, grievance, knowledge base, announcements, newsletters, notification centre.
- PHASE 13 — EXIT AND ALUMNI: resignation, termination, notice, clearance, asset recovery, F&F, exit interview, letters, alumni portal.
- PHASE 14 — ANALYTICS: dashboards, report builder, workforce/payroll/attendance analytics, attrition, performance, learning, cost analytics.
- PHASE 15 — AI AND INTELLIGENCE: Employee Assistant, HR Copilot, Manager Assistant, Policy Assistant, Payroll Auditor, Workforce Intelligence, anomaly detection.
- PHASE 16 — ENTERPRISE AND INTERNATIONAL: SSO, SCIM, advanced APIs, enterprise security, dedicated tenants, data warehouse, country framework, multiple currencies, global localisation, international payroll architecture.

## 122. WHAT MUST BE BUILT EARLY

Do NOT postpone: tenant isolation, RBAC/ABAC, audit, effective dating, configuration architecture, workflow engine, notification engine, event architecture, file/document security. These should be foundational.

## 123. WHAT SHOULD NOT BE DONE

Do not: build every module as an isolated CRUD application; hardcode client policies; hardcode payroll formulas throughout the application; create client-specific code forks; duplicate employee data in every module; create giant employee forms; create a complicated ERP-style employee UI; let AI freely modify payroll; allow tenant admins to alter protected compliance/security logic; overwrite historical payroll or employee configuration; make audit logs editable/deletable; store documents as publicly accessible files.

## 124. CORE ENGINEERING RULES

1. No business policy should be hardcoded if it can reasonably be configured by a tenant administrator.
2. No tenant-specific customization should require modifying core product code.
3. Every configurable rule must be effective-dated, auditable and reversible.
4. Statutory and security controls must remain protected from tenant configuration.
5. Every material business action must create an audit event.
6. Historical records must remain reproducible.

## 125. SAAS SUCCESS TEST

Can the same codebase serve Client A (100 employees), B (1,500), C (10,000), D (50,000+) with different hierarchy, policies, shifts, payroll, workflows, permissions, branding, company structures, without modifying core product code? If yes, the SaaS architecture is succeeding.

## 126. TENANT ADMIN SUCCESS TEST

Can an authorised tenant administrator create company, location, department, level, grade, designation, category, employment type; configure reporting hierarchy; create shift; change working hours; create holiday calendar, leave type, leave policy; configure attendance, overtime; create salary structure, non-statutory formulas, performance cycle, rating scale, custom fields, forms, workflows, approval rules, escalation rules, notifications, letters, policies; configure branding, roles, permissions; manage integrations; view audit history — without Markedge development intervention? That is a primary product acceptance criterion.

## 127. FINAL ARCHITECTURE

MARKEDGE PEOPLEOS = PEOPLE CORE (Employee 360, Organisation, Employment, Lifecycle, Attendance, Leave, Payroll, Compliance, Performance, Learning, Assets, Documents, Exit, Alumni) + CONFIGURATION PLATFORM (Policy Engine, Workflow Engine, Rule Engine, Approval Engine, Escalation Engine, Form Builder, Custom Fields, Templates, Versioning, Effective Dating, Configuration Packs) + EXPERIENCE (Employee, Manager, HR, Payroll, Admin, Mobile) + AUDIT (Immutable Audit, Change History, Security Logs, Configuration History) + INTELLIGENCE (Reporting, Analytics, AI) + INTEGRATION HUB (RMS, Biometric, ERP, Banking, SSO, APIs, Webhooks).

## 128. FINAL PRODUCT POSITIONING

MARKEDGE PEOPLEOS — THE EMPLOYEE OPERATING SYSTEM. "One person. One record. One journey. One platform." Alternative promise: "From first hello to lifelong connection."

Core differentiators: 1. One Lifetime Employee Record 2. Complete Hire-to-Alumni Lifecycle 3. No-Code Tenant Configuration 4. Policy Engine 5. Workflow Engine 6. Flexible Payroll Architecture 7. India-First Compliance 8. Multi-Company / Multi-Entity 9. Enterprise Hierarchy and Matrix Reporting 10. World-Class Employee UX 11. Employee Service Centre 12. Exit-to-Alumni Continuity 13. Configuration Simulation 14. Configuration Impact Preview 15. Immutable Audit / Change Intelligence 16. AI over deterministic HR services 17. API-first integration architecture 18. International-ready country framework.

## 129. FINAL PRODUCT PHILOSOPHY

Traditional HRMS: "Here is our software. Change your processes to fit it." MARKEDGE PEOPLEOS: "Configure the platform to the way your organisation works."

The customer defines: Your Organisation, Hierarchy, Policies, Workflows, Payroll, Forms, Letters, Dashboards, Approvals, Notifications, Branding. Markedge controls: Platform Security, Tenant Isolation, Core Data Integrity, Audit Engine, Protected Compliance Logic, Core Product Architecture.

## 130. THE NORTH STAR

PeopleOS should not be an HRMS with configuration added later. It should be A CONFIGURABLE PEOPLE PLATFORM on which the HRMS modules are built.

Foundation: Employee + Policy + Workflow + Rules + Configuration + Audit + Lifecycle.
Modules: Attendance + Leave + Payroll + Compliance + Performance + Learning + Assets + Documents + Service Desk + Grievance + Communication + Exit + F&F + Alumni + Analytics + AI.

This architecture is intended to allow Markedge to serve a 100-person startup and a complex multi-company enterprise from the same SaaS platform while avoiding client-specific code forks.

END OF MASTER PEOPLEOS DEVELOPMENT BLUEPRINT
