# SaaS.9 data inventory: every table

Generated from `DataInventory` and the live foreign-key graph (`SchemaGraph`) on 2026-10-08. 340 tables.
The code is authoritative: `ArchitectureTest` fails on any table not classified exactly once.

Columns:
- **Owner:** tenant = customer data; markedge = Markedge's own records; platform = PeopleOS.
- **Deletion:** the category's action in a deletion. `delete` happens only when retention has ended, no hold covers it and nothing kept depends on it. `anonymise` is the fallback for identity. `retain` means never deleted with the tenant.
- **Export:** whether the table is in the customer export.
- **FK in / out:** the number of foreign keys pointing at the table, and from it.
- **Files:** columns holding stored file paths.
- **Excluded columns:** secret or lookup columns, never exported.
- **Anonymised:** columns replaced when the table is anonymised.
- **Audit refs:** the table references people (actor, creator, user).

| Category | Owner | Deletion | Export | Sensitivity | Legal / financial significance |
|---|---|---|---|---|---|
| identity | tenant | delete (or anonymise) | yes | High (credentials excluded) | Operational |
| people | tenant | delete | yes | High (personal, family, contact) | Legal (personal data) |
| employment | tenant | delete | yes | High | Legal (employment records) |
| organisation | tenant | delete | yes | Low | Operational |
| attendance | tenant | delete | yes | Medium | Statutory (registers, where required) |
| leave | tenant | delete | yes | Medium (may include health reasons) | Statutory (registers, where required) |
| payroll | tenant | delete | yes | High (pay) | Statutory and financial (wage records) |
| statutory | tenant | delete | yes | High (government identifiers) | Statutory (returns, identifiers) |
| tax | tenant | delete | yes | High (PAN, tax) | Statutory (TDS) |
| banking | tenant | delete | yes | High (bank accounts) | Financial |
| documents | tenant | delete | yes | High (documents) | Legal (documents) |
| performance | tenant | delete | yes | High (evaluations) | Operational |
| goals | tenant | delete | yes | Medium | Operational |
| learning | tenant | delete | yes | Low | Operational |
| career | tenant | delete | yes | Medium | Operational |
| talent | tenant | delete | yes | High (succession, potential) | Operational |
| compensation | tenant | delete | yes | High (pay) | Financial |
| assets | tenant | delete | yes | Low | Operational |
| service_desk | tenant | delete | yes | Medium | Operational |
| grievances | tenant | delete | yes | High (complaints) | Legal (complaints) |
| engagement | tenant | delete | yes | Medium (anonymity preserved) | Operational |
| communication | tenant | delete | yes | Low | Operational |
| notifications | tenant | delete | yes | Medium (message log) | Operational |
| workflows | tenant | delete | yes | Medium | Operational |
| configuration | tenant | delete | yes | Low | Operational |
| analytics | tenant | delete | yes | Medium (AI questions) | Operational |
| integrations | tenant | delete | yes | Medium (secrets excluded) | Operational |
| api | tenant | delete | yes | Low (hashes excluded) | Operational |
| audit | tenant | delete | yes | High (evidence) | Evidence (hash chain) |
| billing | markedge | retain | yes | Medium (commercial) | Markedge financial |
| invoices | markedge | retain | yes | Medium (commercial) | Markedge financial (tax invoices) |
| payments | markedge | retain | yes | Medium (commercial) | Markedge financial |
| subscriptions | markedge | retain | yes | Low (commercial) | Markedge commercial |
| platform_operational | platform | delete | no | Low | Operational |
| offboarding_records | platform | retain | no | Medium (exit evidence) | Evidence (the exit) |
| warehouse_exports | tenant | delete | no | High (derived copies) | Operational |

## Tables by category

### identity: Identity: sign-in accounts, roles, access scopes, invitations, preferences

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `experience_preferences` | table | 0 | 2 |  |  |  | user refs |
| `roles` | table | 11 | 1 |  |  |  |  |
| `user_access_scopes` | table | 0 | 2 |  |  |  | user refs |
| `user_invitations` | table | 0 | 3 |  | token_hash |  | user refs |
| `users` | table | 282 | 1 |  | password, remember_token, app_authentication_secret, app_authentication_recovery_codes | name, email, password, remember_token, app_authentication_secret, app_authentication_recovery_codes, sso_subject, sso_connection_id, external_id, email_verified_at, last_login_at, status, timezone, locale |  |
| `role_user` | child of users | 0 | 3 |  |  |  | user refs |
| `permission_role` | child of roles | 0 | 2 |  |  |  |  |
| `sessions` | child of users | 0 | 0 |  |  |  | user refs |
| `password_reset_tokens` | child of users | 0 | 0 |  |  |  |  |

### people: People: personal details, addresses, family, emergency contacts, qualifications, skills

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `people` | table | 8 | 1 | photo_path |  |  |  |
| `person_addresses` | table | 0 | 2 |  |  |  |  |
| `person_certifications` | table | 0 | 2 |  |  |  |  |
| `person_emergency_contacts` | table | 0 | 2 |  |  |  |  |
| `person_experiences` | table | 0 | 2 |  |  |  |  |
| `person_family_members` | table | 0 | 2 |  |  |  |  |
| `person_qualifications` | table | 0 | 2 |  |  |  |  |
| `person_skills` | table | 0 | 3 |  |  |  |  |
| `skills` | table | 6 | 2 |  |  |  |  |

### employment: Employment: employees, positions, reporting lines, lifecycle, onboarding, exits, alumni, background checks, imports

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `alumni_profiles` | table | 1 | 3 |  |  |  |  |
| `alumni_requests` | table | 0 | 4 |  |  |  |  |
| `bgv_cases` | table | 1 | 4 |  |  |  |  |
| `bgv_checks` | table | 0 | 4 |  |  |  |  |
| `employee_import_rows` | table | 0 | 3 |  |  |  |  |
| `employee_imports` | table | 1 | 3 | path |  |  |  |
| `employee_lifecycle_transitions` | table | 0 | 3 |  |  |  | user refs |
| `employee_positions` | table | 0 | 16 |  |  |  |  |
| `employee_timeline_entries` | table | 0 | 3 |  |  |  | user refs |
| `employees` | table | 101 | 3 |  |  |  | user refs |
| `exit_cases` | table | 4 | 7 |  |  |  |  |
| `exit_clearances` | table | 0 | 5 |  |  |  |  |
| `exit_interviews` | table | 0 | 4 |  |  |  |  |
| `final_settlement_lines` | table | 0 | 2 |  |  |  |  |
| `final_settlements` | table | 1 | 4 |  |  |  |  |
| `onboarding_plans` | table | 1 | 4 |  |  |  |  |
| `onboarding_tasks` | table | 0 | 10 |  |  |  |  |
| `onboarding_template_items` | table | 1 | 6 |  |  |  |  |
| `onboarding_templates` | table | 2 | 1 |  |  |  |  |
| `reporting_relationships` | table | 0 | 3 |  |  |  |  |

Stored files: disk `local` (configurable), exact prefix `tenants/{tenant}/imports`

### organisation: Organisation: companies, legal entities, establishments, structure, positions, workforce plans

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `business_units` | table | 8 | 2 |  |  |  |  |
| `companies` | table | 30 | 1 |  |  |  |  |
| `cost_centres` | table | 5 | 2 |  |  |  |  |
| `departments` | table | 9 | 3 |  |  |  |  |
| `designations` | table | 15 | 6 |  |  |  |  |
| `divisions` | table | 7 | 2 |  |  |  |  |
| `employee_categories` | table | 1 | 1 |  |  |  |  |
| `employee_establishment_assignments` | table | 0 | 6 |  |  |  | user refs |
| `employment_types` | table | 4 | 1 |  |  |  |  |
| `establishments` | table | 15 | 5 | verification_evidence_path |  |  |  |
| `grades` | table | 7 | 2 |  |  |  |  |
| `job_families` | table | 7 | 1 |  |  |  |  |
| `legal_entities` | table | 12 | 4 | verification_evidence_path |  |  |  |
| `levels` | table | 4 | 1 |  |  |  |  |
| `locations` | table | 14 | 3 |  |  |  |  |
| `organisation_nodes` | table | 13 | 2 |  |  |  |  |
| `position_change_requests` | table | 0 | 5 |  |  |  |  |
| `position_versions` | table | 2 | 19 |  |  |  | user refs |
| `positions` | table | 8 | 13 |  |  |  | user refs |
| `profit_centres` | table | 0 | 2 |  |  |  |  |
| `teams` | table | 7 | 2 |  |  |  |  |
| `work_modes` | table | 1 | 1 |  |  |  |  |
| `workforce_budgets` | table | 1 | 12 |  |  |  | user refs |
| `workforce_plan_lines` | table | 1 | 11 |  |  |  |  |
| `workforce_plan_versions` | table | 4 | 8 |  |  |  | user refs |
| `workforce_plans` | table | 1 | 13 |  |  |  | user refs |
| `workforce_reminder_logs` | table | 0 | 1 |  |  |  |  |
| `workforce_scenarios` | table | 1 | 3 |  |  |  | user refs |
| `designation_employment_type` | child of designations | 0 | 2 |  |  |  |  |

Stored files: disk `local` (configurable), exact prefix `compliance-evidence/establishments/{tenant}`

Stored files: disk `local` (configurable), exact prefix `compliance-evidence/legal_entities/{tenant}`

### attendance: Attendance: punches, records, regularisations, shifts, schedules, holidays, devices

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `attendance_devices` | table | 1 | 2 |  |  |  |  |
| `attendance_punches` | table | 0 | 4 |  |  |  |  |
| `attendance_records` | table | 0 | 5 |  |  |  |  |
| `attendance_regularisations` | table | 0 | 4 |  |  |  |  |
| `holiday_calendar_rules` | table | 0 | 2 |  |  |  |  |
| `holiday_calendars` | table | 2 | 1 |  |  |  |  |
| `holidays` | table | 0 | 2 |  |  |  |  |
| `shift_breaks` | table | 0 | 2 |  |  |  |  |
| `shifts` | table | 2 | 1 |  |  |  |  |
| `work_schedule_assignments` | table | 0 | 3 |  |  |  |  |
| `work_schedule_rules` | table | 0 | 2 |  |  |  |  |
| `work_schedules` | table | 2 | 1 |  |  |  |  |

### leave: Leave: types, requests, balances, ledger, encashments

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `leave_balances` | table | 0 | 3 |  |  |  |  |
| `leave_encashments` | table | 0 | 5 |  |  |  |  |
| `leave_ledger_entries` | table | 0 | 4 |  |  |  | user refs |
| `leave_requests` | table | 1 | 7 |  |  |  |  |
| `leave_types` | table | 4 | 1 |  |  |  |  |

### payroll: Payroll: periods, runs, entries, lines, payslips, adjustments, components

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `payroll_adjustments` | table | 0 | 7 |  |  |  | user refs |
| `payroll_entries` | table | 8 | 6 |  |  |  |  |
| `payroll_entry_lines` | table | 0 | 3 |  |  |  |  |
| `payroll_periods` | table | 4 | 2 |  |  |  |  |
| `payroll_runs` | table | 8 | 6 |  |  |  | user refs |
| `payslips` | table | 0 | 3 |  |  |  |  |
| `salary_components` | table | 3 | 1 |  |  |  |  |

### statutory: Statutory: registrations, employee statutory identifiers, EPF / ESI / PT / LWF returns, snapshots, reconciliations, parallel runs

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `company_statutory_profiles` | table | 0 | 2 |  |  |  |  |
| `employee_statutory_details` | table | 0 | 2 |  |  |  |  |
| `epf_return_entries` | table | 0 | 7 |  | uan_hash |  |  |
| `epf_return_revisions` | table | 0 | 4 |  |  |  |  |
| `epf_return_runs` | table | 1 | 5 |  |  |  |  |
| `esi_return_entries` | table | 0 | 7 |  | ip_number_hash |  |  |
| `esi_return_runs` | table | 1 | 4 |  |  |  |  |
| `establishment_statutory_profiles` | table | 0 | 5 |  |  |  | user refs |
| `lwf_return_entries` | table | 0 | 7 |  |  |  |  |
| `lwf_returns` | table | 1 | 4 |  |  |  |  |
| `parallel_payroll_lines` | table | 0 | 4 |  |  |  |  |
| `parallel_payroll_runs` | table | 1 | 5 |  |  |  |  |
| `professional_tax_return_entries` | table | 0 | 7 |  |  |  |  |
| `professional_tax_returns` | table | 1 | 4 |  |  |  |  |
| `statutory_reconciliations` | table | 0 | 3 |  |  |  |  |
| `statutory_registrations` | table | 8 | 6 |  | registration_number_hash |  | user refs |
| `statutory_return_actions` | table | 0 | 5 |  |  |  | user refs |
| `statutory_returns` | table | 16 | 12 | export_path |  |  |  |
| `statutory_snapshots` | table | 1 | 9 |  |  |  |  |

Stored files: disk `local` (configurable), exact prefix `statutory-exports/{tenant}`

### tax: Income tax (TDS): profiles, declarations, investments, ledgers, quarterly returns, certificates

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `employee_tax_declarations` | table | 1 | 3 |  |  |  |  |
| `tds_annual_ledgers` | table | 2 | 7 |  |  |  |  |
| `tds_certificates` | table | 1 | 7 |  |  |  |  |
| `tds_employee_investments` | table | 0 | 5 |  |  |  |  |
| `tds_financial_years` | table | 1 | 4 |  |  |  |  |
| `tds_profiles` | table | 0 | 5 |  |  |  |  |
| `tds_quarterly_return_entries` | table | 0 | 6 |  | pan_hash |  |  |
| `tds_quarterly_returns` | table | 1 | 4 |  |  |  |  |

### banking: Banking: employee bank accounts

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `employee_bank_accounts` | table | 0 | 2 |  |  |  |  |

### documents: Documents: employee documents and their files, letters, letter templates, document types

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `document_types` | table | 3 | 1 |  |  |  |  |
| `employee_documents` | table | 6 | 5 | path |  |  |  |
| `letter_templates` | table | 1 | 1 |  |  |  |  |
| `letters` | table | 1 | 7 |  |  |  |  |

Stored files: disk `local` (configurable), exact prefix `tenants/{tenant}/employees`

### performance: Performance: cycles, appraisals, ratings, reviews, calibration, feedback, one-on-ones, improvement plans

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `appraisal_ratings` | table | 0 | 2 |  |  |  |  |
| `appraisal_reviews` | table | 1 | 3 |  |  |  |  |
| `appraisals` | table | 3 | 6 |  |  |  |  |
| `calibration_adjustments` | table | 0 | 4 |  |  |  | user refs |
| `calibration_sessions` | table | 1 | 4 |  |  |  |  |
| `competencies` | table | 3 | 1 |  |  |  |  |
| `feedback_entries` | table | 1 | 7 |  |  |  |  |
| `improvement_plan_checkpoints` | table | 0 | 3 |  |  |  |  |
| `improvement_plans` | table | 1 | 5 |  |  |  | user refs |
| `one_on_ones` | table | 0 | 3 |  |  |  |  |
| `performance_check_ins` | table | 0 | 5 |  |  |  |  |
| `performance_cycles` | table | 5 | 3 |  |  |  |  |
| `performance_reminder_logs` | table | 0 | 1 |  |  |  |  |
| `performance_template_versions` | table | 2 | 4 |  |  |  |  |
| `performance_templates` | table | 1 | 1 |  |  |  |  |
| `rating_scales` | table | 2 | 1 |  |  |  |  |

### goals: Goals: goals, key results, KRAs, check-ins

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `goal_check_ins` | table | 0 | 4 |  |  |  | user refs |
| `goals` | table | 4 | 7 |  |  |  | user refs |
| `key_results` | table | 1 | 2 |  |  |  |  |
| `kras` | table | 1 | 1 |  |  |  |  |

### learning: Learning: courses, paths, programmes, enrolments, completions, certificates and their files, evidence, sessions, costs

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `assessment_attempts` | table | 0 | 4 |  |  |  |  |
| `assessments` | table | 1 | 2 |  |  |  |  |
| `course_modules` | table | 0 | 2 |  |  |  |  |
| `course_versions` | table | 5 | 5 |  |  |  |  |
| `courses` | table | 12 | 7 |  |  |  |  |
| `learning_assignments` | table | 1 | 6 |  |  |  | user refs |
| `learning_certificates` | table | 1 | 9 | document_path | verification_code |  |  |
| `learning_completions` | table | 3 | 11 |  |  |  |  |
| `learning_costs` | table | 0 | 7 |  |  |  | user refs |
| `learning_enrolments` | table | 7 | 13 |  |  |  |  |
| `learning_evidence` | table | 0 | 5 | path |  |  |  |
| `learning_instructors` | table | 4 | 3 |  |  |  |  |
| `learning_path_courses` | table | 0 | 3 |  |  |  |  |
| `learning_path_versions` | table | 2 | 3 |  |  |  |  |
| `learning_paths` | table | 6 | 2 |  |  |  |  |
| `learning_program_participants` | table | 2 | 4 |  |  |  |  |
| `learning_program_versions` | table | 3 | 3 |  |  |  |  |
| `learning_programs` | table | 1 | 1 |  |  |  |  |
| `learning_providers` | table | 5 | 1 |  |  |  |  |
| `learning_reminder_logs` | table | 0 | 1 |  |  |  |  |
| `training_session_attendees` | table | 0 | 4 |  |  |  |  |
| `training_sessions` | table | 3 | 5 |  |  |  |  |

Stored files: disk `local` (configurable), exact prefix `learning/certificates/{tenant}`

### career: Career and skills: career paths, profiles, aspirations, mobility, development plans, skills, skill assessments

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `career_aspiration_entries` | table | 0 | 8 |  |  |  | user refs |
| `career_aspirations` | table | 0 | 4 |  |  |  |  |
| `career_goals` | table | 0 | 10 |  |  |  | user refs |
| `career_path_steps` | table | 0 | 3 |  |  |  |  |
| `career_path_versions` | table | 1 | 3 |  |  |  |  |
| `career_paths` | table | 3 | 6 |  |  |  |  |
| `career_profiles` | table | 0 | 4 |  |  |  |  |
| `career_tracks` | table | 6 | 1 |  |  |  |  |
| `development_needs` | table | 1 | 7 |  |  |  | user refs |
| `development_plan_items` | table | 1 | 7 |  |  |  |  |
| `development_plans` | table | 2 | 5 |  |  |  | user refs |
| `employee_skills` | table | 0 | 8 |  |  |  | user refs |
| `mobility_interests` | table | 0 | 8 |  |  |  | user refs |
| `role_requirement_versions` | table | 0 | 4 |  |  |  |  |
| `skill_assessments` | table | 2 | 8 |  |  |  |  |
| `skill_scale_versions` | table | 3 | 3 |  |  |  |  |
| `skill_scales` | table | 2 | 1 |  |  |  |  |

### talent: Talent and succession: pools, profiles, assessments, reviews, critical positions, successors

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `critical_position_assessments` | table | 0 | 3 |  |  |  |  |
| `critical_positions` | table | 4 | 4 |  |  |  | user refs |
| `readiness_assessments` | table | 0 | 6 |  |  |  |  |
| `succession_plans` | table | 1 | 6 |  |  |  | user refs |
| `successors` | table | 2 | 5 |  |  |  |  |
| `talent_assessment_model_versions` | table | 1 | 3 |  |  |  |  |
| `talent_assessment_models` | table | 1 | 1 |  |  |  |  |
| `talent_assessments` | table | 1 | 6 |  |  |  |  |
| `talent_development_actions` | table | 0 | 6 |  |  |  | user refs |
| `talent_pool_memberships` | table | 0 | 5 |  |  |  |  |
| `talent_pools` | table | 1 | 2 |  |  |  |  |
| `talent_profiles` | table | 0 | 4 |  |  |  |  |
| `talent_reminder_logs` | table | 0 | 1 |  |  |  |  |
| `talent_review_items` | table | 2 | 6 |  |  |  |  |
| `talent_review_sessions` | table | 1 | 6 |  |  |  | user refs |

### compensation: Compensation: salary structures, salary assignments, changes, cycles, budgets, ranges

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `compensation_budgets` | table | 2 | 11 |  |  |  |  |
| `compensation_changes` | table | 1 | 19 |  |  |  |  |
| `compensation_cycles` | table | 1 | 15 |  |  |  |  |
| `compensation_ranges` | table | 0 | 8 |  |  |  |  |
| `compensation_reminder_logs` | table | 0 | 1 |  |  |  |  |
| `employee_salary_assignments` | table | 4 | 7 |  |  |  | user refs |
| `salary_structure_components` | table | 0 | 4 |  |  |  |  |
| `salary_structure_versions` | table | 1 | 5 |  |  |  |  |
| `salary_structures` | table | 6 | 1 |  |  |  |  |

### assets: Assets: assets, models, categories, assignments, movements, repairs, disposals

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `asset_assignments` | table | 0 | 4 |  |  |  |  |
| `asset_categories` | table | 2 | 1 |  |  |  |  |
| `asset_disposals` | table | 0 | 3 |  |  |  |  |
| `asset_models` | table | 1 | 2 |  |  |  |  |
| `asset_movements` | table | 0 | 7 |  |  |  | user refs |
| `asset_repairs` | table | 0 | 2 |  |  |  |  |
| `assets` | table | 4 | 6 |  |  |  |  |

### service_desk: Service desk: tickets, comments and attachments, transitions, services, SLAs

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `service_definition_versions` | table | 1 | 6 |  |  |  |  |
| `service_definitions` | table | 2 | 2 |  |  |  |  |
| `service_desk_reminder_logs` | table | 0 | 1 |  |  |  |  |
| `service_sla_policies` | table | 2 | 2 |  |  |  |  |
| `ticket_access_grants` | table | 0 | 5 |  |  |  | user refs |
| `ticket_categories` | table | 2 | 4 |  |  |  |  |
| `ticket_comments` | table | 0 | 3 | attachment_path |  |  |  |
| `ticket_transitions` | table | 0 | 3 |  |  |  | user refs |
| `tickets` | table | 3 | 14 |  |  |  |  |

Stored files: disk `local` (configurable), exact prefix `tenants/{tenant}/servicedesk`

### grievances: Grievances: cases, notes and attachments, categories

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `grievance_categories` | table | 1 | 1 |  |  |  |  |
| `grievance_notes` | table | 0 | 3 | attachment_path |  |  |  |
| `grievances` | table | 1 | 4 |  |  |  |  |

Stored files: disk `local` (configurable), exact prefix `tenants/{tenant}/grievances`

### engagement: Engagement: surveys, responses and answers (anonymity preserved), campaigns, feedback, audiences

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `audiences` | table | 3 | 2 |  |  |  |  |
| `campaign_items` | table | 0 | 2 |  |  |  |  |
| `employee_feedback` | table | 0 | 3 |  |  |  |  |
| `engagement_campaigns` | table | 2 | 6 |  |  |  |  |
| `engagement_identities` | table | 0 | 2 |  |  |  |  |
| `engagement_reminder_logs` | table | 0 | 1 |  |  |  |  |
| `survey_answers` | table | 0 | 4 |  |  |  |  |
| `survey_participations` | table | 0 | 3 |  |  |  |  |
| `survey_questions` | table | 1 | 2 |  |  |  |  |
| `survey_responses` | table | 1 | 3 |  |  |  |  |
| `survey_versions` | table | 4 | 7 |  |  |  |  |
| `surveys` | table | 1 | 2 |  |  |  |  |

### communication: Communication and knowledge: announcements and attachments, recipients, preferences, articles

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `announcement_reads` | table | 0 | 3 |  |  |  |  |
| `announcements` | table | 3 | 10 | attachment_path |  |  |  |
| `article_reads` | table | 0 | 4 |  |  |  |  |
| `article_versions` | table | 1 | 5 |  |  |  |  |
| `articles` | table | 3 | 4 |  |  |  |  |
| `communication_preferences` | table | 0 | 2 |  |  |  |  |
| `communication_recipients` | table | 0 | 4 |  |  |  | user refs |

Stored files: disk `local` (configurable), exact prefix `tenants/{tenant}/communication`

### notifications: Notifications: delivery log, rules, templates, in-app notifications

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `notification_deliveries` | table | 0 | 2 |  | dedupe_key |  | user refs |
| `notification_rules` | table | 0 | 2 |  |  |  |  |
| `notification_templates` | table | 1 | 1 |  |  |  |  |
| `notifications` | child of users | 0 | 0 |  |  |  |  |

### workflows: Workflows: definitions, versions, runs, tasks, actions

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `workflow_actions` | table | 0 | 3 |  |  |  | user refs |
| `workflow_instances` | table | 10 | 4 |  |  |  |  |
| `workflow_tasks` | table | 0 | 5 |  |  |  |  |
| `workflow_versions` | table | 1 | 3 |  |  |  |  |
| `workflows` | table | 2 | 1 |  | webhook_signing_secret |  |  |

### configuration: Configuration: settings, features, custom fields and values, forms and submissions, policies, configuration changes, exchange rates

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `configuration_changes` | table | 1 | 4 |  |  |  |  |
| `custom_field_values` | table | 0 | 2 |  |  |  |  |
| `custom_fields` | table | 1 | 1 |  |  |  |  |
| `exchange_rates` | table | 0 | 1 |  |  |  |  |
| `form_submissions` | table | 1 | 5 |  |  |  |  |
| `form_versions` | table | 2 | 3 |  |  |  |  |
| `forms` | table | 4 | 1 |  |  |  |  |
| `policies` | table | 2 | 1 |  |  |  |  |
| `policy_assignment_rules` | table | 0 | 2 |  |  |  |  |
| `policy_versions` | table | 0 | 3 |  |  |  |  |
| `tenant_features` | table | 0 | 1 |  |  |  |  |
| `tenant_settings` | table | 0 | 1 |  |  |  |  |

### analytics: Analytics and AI: reports, schedules, runs and their files, dashboards, AI interactions

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `ai_interactions` | table | 0 | 2 |  |  |  | user refs |
| `dashboards` | table | 0 | 2 |  |  |  |  |
| `report_runs` | table | 0 | 4 | path |  |  |  |
| `report_schedules` | table | 1 | 2 |  |  |  |  |
| `reports` | table | 2 | 2 |  |  |  |  |

Stored files: disk `local` (configurable), exact prefix `tenants/{tenant}/reports`

### integrations: Integrations: systems, mappings, external references, inbound events, webhook endpoints and deliveries, SSO connections (never their secrets)

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `external_references` | table | 0 | 3 |  |  |  |  |
| `inbound_events` | table | 0 | 2 |  | payload |  |  |
| `integration_mappings` | table | 0 | 2 |  |  |  |  |
| `integration_systems` | table | 3 | 3 |  | inbound_secret |  | user refs |
| `sso_connections` | table | 0 | 2 |  | client_secret |  |  |
| `webhook_deliveries` | table | 0 | 2 |  |  |  |  |
| `webhook_endpoints` | table | 1 | 1 |  | secret |  |  |

### api: API keys (names, scopes, use; never secrets)

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `api_keys` | table | 2 | 2 |  | secret_hash |  | user refs |

### audit: Audit trail: the tenant's hash chain and its field changes (deleted only as a whole chain, last, through the deletion workflow)

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `audit_events` | table | 1 | 2 |  |  |  | user refs |
| `audit_event_changes` | child of audit_events | 0 | 1 |  |  |  |  |

### billing: Billing (Markedge's records): billing profile, terms, periods, notices, negotiated prices

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `billing_periods` | table | 1 | 8 |  |  |  |  |
| `negotiated_price_versions` | table | 3 | 6 |  |  |  | user refs |
| `negotiated_prices` | table | 1 | 5 |  |  |  | user refs |
| `price_change_notices` | table | 0 | 5 |  |  |  | user refs |
| `subscription_billing_terms` | table | 1 | 9 |  |  |  | user refs |
| `tenant_billing_profiles` | table | 1 | 3 |  |  |  | user refs |

### invoices: Invoices (Markedge's records): invoices, lines, tax lines, TDS claims, credit notes

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `credit_notes` | table | 1 | 6 |  |  |  | user refs |
| `invoice_lines` | table | 0 | 6 |  |  |  |  |
| `invoice_tax_lines` | table | 0 | 3 |  |  |  |  |
| `invoice_tds_claims` | table | 0 | 4 |  |  |  |  |
| `invoices` | table | 6 | 10 |  |  |  | user refs |

### payments: Payments (Markedge's records): payments, reconciliation, refunds

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `payments` | table | 1 | 4 |  |  |  | user refs |
| `refunds` | table | 0 | 6 |  |  |  |  |

### subscriptions: Subscriptions (Markedge's records): subscriptions, periods, plan assignments, entitlements, overrides

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `entitlement_overrides` | table | 0 | 3 |  |  |  | user refs |
| `subscription_periods` | table | 0 | 5 |  |  |  | user refs |
| `tenant_entitlement_profiles` | table | 0 | 2 |  |  |  |  |
| `tenant_entitlements` | table | 0 | 3 |  |  |  | user refs |
| `tenant_plan_assignments` | table | 0 | 5 |  |  |  | user refs |
| `tenant_subscriptions` | table | 7 | 2 |  |  |  | user refs |

### platform_operational: Operational state: deferred jobs, sequences, scheduler claims, replay cache, observations, UX counters

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `api_idempotency_keys` | table | 0 | 2 |  |  |  |  |
| `employee_code_sequences` | table | 0 | 1 |  |  |  |  |
| `entitlement_shadow_observations` | table | 0 | 1 |  |  |  |  |
| `number_sequences` | table | 0 | 1 |  |  |  |  |
| `scheduler_claims` | table | 0 | 1 |  |  |  |  |
| `tenant_deferred_jobs` | table | 0 | 1 |  |  |  |  |
| `ux_metrics` | table | 0 | 1 |  |  |  |  |

### offboarding_records: The exit's evidence: offboarding case, exports, legal holds, deletion plans, steps and certificates

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|
| `legal_holds` | table | 0 | 0 |  |  |  |  |
| `tenant_deletion_steps` | table | 0 | 0 |  |  |  |  |
| `tenant_deletions` | table | 0 | 0 |  |  |  |  |
| `tenant_exports` | table | 0 | 0 |  |  |  |  |
| `tenant_offboardings` | table | 0 | 0 |  |  |  |  |

### warehouse_exports: Warehouse feed snapshots (derived copies on the warehouse disk; files only)

| Table | Kind | FK in | FK out | Files | Excluded columns | Anonymised | Audit refs |
|---|---|---|---|---|---|---|---|

Stored files: disk `local` (configurable), exact prefix `warehouse/{slug}`

## Platform tables (never deleted with a tenant)

`tenants`, `migrations`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `permissions`, `compliance_rules`, `compliance_rule_verifications`, `compliance_rule_parameters`, `compliance_rule_notices`, `compliance_evidence_documents`, `statutory_export_layouts`, `plans`, `plan_versions`, `plan_entitlements`, `plan_prices`, `plan_price_versions`, `billing_markets`, `billing_supplier_profiles`, `invoice_number_series`, `tax_rules`, `configuration_versions`, `financial_approvals`, `payment_provider_events`, `audit_verification_runs`, `audit_chain_locks`, `retention_policies`
