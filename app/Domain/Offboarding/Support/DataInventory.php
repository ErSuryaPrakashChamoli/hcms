<?php

namespace App\Domain\Offboarding\Support;

use App\Domain\Platform\Models\Tenant;
use InvalidArgumentException;

/**
 * SaaS.9: the tenant data inventory. Every table of the schema is classified here (an architecture test fails on any
 * table that is not), so no tenant data can escape the export, the retention evaluation or the deletion plan.
 *
 * - CATEGORIES: what each category is, who owns it, how sensitive it is, its legal or financial significance, whether
 *   it is exported, and what a deletion does with it once its retention has ended and no legal hold covers it:
 *   - delete: hard delete of the rows and their files;
 *   - anonymise: keep the rows, remove the personal data (identity only, and only where retained records still
 *     reference the rows and do not need the identity);
 *   - retain: never deleted by a tenant deletion (Markedge's own commercial records, the exit's evidence).
 * - TABLES: every table carrying tenant_id, by category.
 * - CHILDREN: tables without tenant_id that hold a tenant's rows through a parent (pivots, change rows, sessions,
 *   in-app notifications, reset tokens).
 * - PLATFORM: tables with no tenant data, or Markedge's records that merely name a tenant (kept).
 * - FILES and prefixes(): every file a tenant owns, referenced from rows or kept under a tenant prefix.
 *
 * Retention periods are NOT here: they are configuration (RetentionPolicies), versioned and approved by a second operator.
 */
final class DataInventory
{
    public const DELETE = 'delete';

    public const ANONYMISE = 'anonymise';

    public const RETAIN = 'retain';

    /**
     * owner: tenant (the customer's data) | markedge (Markedge's own books) | platform (operational or the exit's evidence).
     * sensitivity: personal | sensitive (special or financial personal data) | business | operational.
     * significance: none | employment | statutory | financial | audit (what retention policies are usually about).
     *
     * @var array<string, array{label: string, owner: string, sensitivity: string, significance: string, export: bool, action: string, anonymisable: bool}>
     */
    public const CATEGORIES = [
        'identity' => ['label' => 'Identity: sign-in accounts, roles, access scopes, invitations, password history, preferences', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => true],
        'people' => ['label' => 'People: personal details, birthdays and birthday wishes, addresses, family, emergency contacts, qualifications, skills, self-service profile change requests', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'employment', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'employment' => ['label' => 'Employment: employees, positions, reporting lines, lifecycle, onboarding, exits, alumni, background checks, imports', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'employment', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'organisation' => ['label' => 'Organisation: companies, legal entities, establishments, structure, positions, workforce plans', 'owner' => 'tenant', 'sensitivity' => 'business', 'significance' => 'statutory', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'attendance' => ['label' => 'Attendance: punches, uploaded day marks, records, regularisations, shifts, schedules, holidays, devices', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'employment', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'leave' => ['label' => 'Leave: types, requests, balances, ledger, encashments', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'employment', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'payroll' => ['label' => 'Payroll: periods, runs, entries, lines, payslips, adjustments, components', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'statutory', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'statutory' => ['label' => 'Statutory: registrations, employee statutory identifiers, EPF / ESI / PT / LWF returns, snapshots, reconciliations, parallel runs, compliance report packs and their files', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'statutory', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'tax' => ['label' => 'Income tax (TDS): profiles, declarations, investments, ledgers, quarterly returns, certificates', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'statutory', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'banking' => ['label' => 'Banking: employee bank accounts and payment modes, the company\'s paying bank accounts, bank file layouts, account number rules', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'financial', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'documents' => ['label' => 'Documents: employee documents and their files, letters, letter templates, signing authorities and their signatures, document types', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'employment', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'performance' => ['label' => 'Performance: cycles, appraisals, ratings, reviews, periodic reviews, recommendations, calibration (distribution targets, 9-box bands), feedback, 360 feedback rounds, nominations and answers (including external client reviewers: name and e-mail only), one-on-ones, improvement plans, initiatives', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'employment', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'goals' => ['label' => 'Goals: goals, key results, KRAs, check-ins', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'learning' => ['label' => 'Learning: courses, paths, programmes, enrolments, completions, certificates and their files, evidence, sessions, costs', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'employment', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'career' => ['label' => 'Career and skills: career paths, profiles, aspirations, mobility, development plans, skills, skill assessments', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'talent' => ['label' => 'Talent and succession: pools, profiles, assessments, reviews, critical positions, successors', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'compensation' => ['label' => 'Compensation: salary structures, salary assignments, changes, cycles, budgets, ranges', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'financial', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'assets' => ['label' => 'Assets: assets, models and their documents, categories, sub types, assignments, movements, repairs, disposals, asset requests', 'owner' => 'tenant', 'sensitivity' => 'business', 'significance' => 'financial', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'service_desk' => ['label' => 'Service desk: tickets, comments and attachments, transitions, services, SLAs, resolver rules', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        // HROne setup parity (Expense): masters (EX-A), receipts and reports (EX-C), advances and their ledger (EX-D); trips join as built.
        'expense' => ['label' => 'Expense and travel: expense categories and limits, groups, location groups and their places, tax types, expense receipts and their tax lines and files, expense reports and their approval parts and trips, expense advances and their ledger, travel requests and their legs, approval parts, bookings and files', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'financial', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'grievances' => ['label' => 'Grievances: cases, notes and attachments, categories', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'employment', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'engagement' => ['label' => 'Engagement: surveys, responses and answers (anonymity preserved), campaigns, feedback, audiences, mission statements, badges and recognitions (and their attachments), reward points, the social wall (and its attachments) and mood check-ins', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'communication' => ['label' => 'Communication and knowledge: announcements and attachments, recipients, preferences, articles', 'owner' => 'tenant', 'sensitivity' => 'business', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'notifications' => ['label' => 'Notifications: delivery log, rules, templates, in-app notifications', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'workflows' => ['label' => 'Workflows: definitions, versions, runs, tasks, actions, request discussions and send-backs, out-of-office delegations and archived inbox items, bulk runs and their per-record results', 'owner' => 'tenant', 'sensitivity' => 'business', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'configuration' => ['label' => 'Configuration: settings, features, employee code series, number series, custom fields and values, forms and submissions, policies, configuration changes, exchange rates', 'owner' => 'tenant', 'sensitivity' => 'business', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'analytics' => ['label' => 'Analytics and AI: reports, schedules, runs and their files, dashboards, AI interactions', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'integrations' => ['label' => 'Integrations: systems, mappings, external references, inbound events, webhook endpoints, deliveries and their attempts, SSO connections (never their secrets), queued webhook / API log exports and their files', 'owner' => 'tenant', 'sensitivity' => 'business', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'api' => ['label' => 'API keys (names, scopes, IP allowlists, rate limits, use; never secrets) and their call logs (method, path, status, duration, IP, user agent; never bodies or headers)', 'owner' => 'tenant', 'sensitivity' => 'operational', 'significance' => 'none', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'audit' => ['label' => 'Audit trail: the tenant\'s hash chain and its field changes (deleted only as a whole chain, last, through the deletion workflow)', 'owner' => 'tenant', 'sensitivity' => 'sensitive', 'significance' => 'audit', 'export' => true, 'action' => self::DELETE, 'anonymisable' => false],
        'billing' => ['label' => 'Billing (Markedge\'s records): billing profile, terms, periods, notices, negotiated prices', 'owner' => 'markedge', 'sensitivity' => 'business', 'significance' => 'financial', 'export' => true, 'action' => self::RETAIN, 'anonymisable' => false],
        'invoices' => ['label' => 'Invoices (Markedge\'s records): invoices, lines, tax lines, TDS claims, credit notes', 'owner' => 'markedge', 'sensitivity' => 'business', 'significance' => 'financial', 'export' => true, 'action' => self::RETAIN, 'anonymisable' => false],
        'payments' => ['label' => 'Payments (Markedge\'s records): payments, reconciliation, refunds', 'owner' => 'markedge', 'sensitivity' => 'business', 'significance' => 'financial', 'export' => true, 'action' => self::RETAIN, 'anonymisable' => false],
        'subscriptions' => ['label' => 'Subscriptions (Markedge\'s records): subscriptions, periods, plan assignments, entitlements, overrides', 'owner' => 'markedge', 'sensitivity' => 'business', 'significance' => 'financial', 'export' => true, 'action' => self::RETAIN, 'anonymisable' => false],
        'platform_operational' => ['label' => 'Operational state: deferred jobs, sequences, scheduler claims, replay cache, observations, UX counters', 'owner' => 'platform', 'sensitivity' => 'operational', 'significance' => 'none', 'export' => false, 'action' => self::DELETE, 'anonymisable' => false],
        'offboarding_records' => ['label' => 'The exit\'s evidence: offboarding case, exports, legal holds, deletion plans, steps and certificates', 'owner' => 'platform', 'sensitivity' => 'business', 'significance' => 'audit', 'export' => false, 'action' => self::RETAIN, 'anonymisable' => false],
        'warehouse_exports' => ['label' => 'Warehouse feed snapshots (derived copies on the warehouse disk; files only)', 'owner' => 'tenant', 'sensitivity' => 'personal', 'significance' => 'none', 'export' => false, 'action' => self::DELETE, 'anonymisable' => false],
    ];

    /** @var array<string, list<string>> category => tables carrying tenant_id */
    public const TABLES = [
        'identity' => ['experience_preferences', 'roles', 'user_access_scopes', 'user_invitations', 'users',
            // HROne setup parity: hashes of recent passwords while the tenant restricts reuse (never exported: `password`).
            'password_histories',
            // HROne parity CA-9 / SV-7: module data-access groups (members, the units they reach) and role membership rules.
            'access_scope_groups', 'access_scope_group_users', 'access_scope_group_rules', 'role_membership_rules'],
        // HROne parity MS-3: profile_change_requests — self-service profile changes; their new values are encrypted until the
        // request is decided, then purged (highly sensitive while they wait: peopleos.data_classification).
        'people' => ['birthday_wishes', 'people', 'person_addresses', 'person_certifications', 'person_emergency_contacts', 'person_experiences', 'person_family_members', 'person_qualifications', 'person_skills', 'skills', 'qualifications', 'qualification_specialisations', 'profile_change_requests'],
        'employment' => ['confirmation_reviews', 'workforce_reasons', 'clearance_checklists', 'transfer_requests', 'feedback_requests', 'alumni_profiles', 'alumni_requests', 'bgv_cases', 'bgv_checks', 'employee_import_rows', 'employee_imports', 'employee_lifecycle_transitions', 'employee_positions', 'employee_timeline_entries', 'employees', 'exit_cases', 'exit_clearances', 'exit_interviews', 'final_settlement_lines', 'final_settlements', 'onboarding_plans', 'onboarding_tasks', 'onboarding_template_items', 'onboarding_templates', 'reporting_relationships'],
        'organisation' => ['organisation_contacts', 'business_units', 'companies', 'cost_centres', 'departments', 'designations', 'divisions', 'employee_categories', 'employee_establishment_assignments', 'employment_types', 'establishments', 'grades', 'job_families', 'legal_entities', 'levels', 'locations', 'organisation_nodes', 'position_change_requests', 'position_versions', 'positions', 'profit_centres', 'teams', 'work_modes', 'workforce_budgets', 'workforce_plan_lines', 'workforce_plan_versions', 'workforce_plans', 'workforce_reminder_logs', 'workforce_scenarios'],
        'attendance' => ['attendance_devices', 'attendance_marks', 'attendance_punches', 'attendance_records', 'attendance_regularisations', 'holiday_calendar_rules', 'holiday_calendars', 'holidays', 'shift_breaks', 'shifts', 'work_schedule_assignments', 'work_schedule_rules', 'work_schedules',
            // HROne parity HP-1: time-office requests, roster and HR overrides, penalties, arrears, geo locations, check-in devices.
            'time_office_requests', 'attendance_shift_overrides', 'attendance_overrides', 'attendance_penalties', 'attendance_arrears', 'attendance_geo_fences', 'attendance_location_requests', 'attendance_employee_devices',
            // HROne setup parity: location pings for continuous tracking.
            'attendance_location_pings'],
        'leave' => ['leave_balances', 'leave_encashments', 'leave_ledger_entries', 'leave_requests', 'leave_types', 'leave_donations'],
        'payroll' => ['payroll_adjustments', 'payroll_entries', 'payroll_entry_lines', 'payroll_periods', 'payroll_runs', 'payslips', 'salary_components',
            // HROne parity HP-2: loans and salary advances.
            'loans', 'loan_installments', 'salary_holds', 'variable_pay_requests', 'reimbursement_claims', 'reimbursement_ledger_entries',
            // HROne setup parity BP-9: loan guarantors and variable pay plans.
            'loan_guarantors', 'variable_pay_plans'],
        'statutory' => ['company_statutory_profiles', 'compliance_report_packs', 'employee_statutory_details', 'epf_return_entries', 'epf_return_revisions', 'epf_return_runs', 'esi_return_entries', 'esi_return_runs', 'establishment_statutory_profiles', 'lwf_return_entries', 'lwf_returns', 'parallel_payroll_lines', 'parallel_payroll_runs', 'professional_tax_return_entries', 'professional_tax_returns', 'statutory_profile_corrections', 'statutory_reconciliations', 'statutory_registrations', 'statutory_return_actions', 'statutory_returns', 'statutory_snapshots',
            // HROne parity HP-3: audited statutory overrides and employee PF options (VPF).
            'statutory_overrides', 'employee_pf_options'],
        'tax' => ['employee_tax_declarations', 'tds_annual_ledgers', 'tds_certificates', 'tds_employee_investments', 'tds_financial_years', 'tds_profiles', 'tds_quarterly_return_entries', 'tds_quarterly_returns',
            // HROne parity HP-3: perquisites and income paid outside payroll.
            'tax_income_entries'],
        'banking' => ['employee_bank_accounts',
            // HROne setup parity BP-13 / CA-5: payment modes, the company's paying accounts, bank file layouts, account number rules.
            'employee_payment_modes', 'company_bank_accounts', 'bank_file_layouts', 'bank_account_rules'],
        'documents' => ['document_types', 'employee_documents', 'letter_templates', 'letters', 'letter_signatories'],
        'performance' => ['appraisal_ratings', 'appraisal_reviews', 'appraisals', 'calibration_adjustments', 'calibration_sessions', 'competencies', 'feedback_entries', 'improvement_plan_checkpoints', 'improvement_plans', 'initiative_feedback', 'initiatives', 'kpi_rating_formulas', 'one_on_one_feedback', 'one_on_ones', 'performance_check_ins', 'performance_cycles', 'performance_distribution_targets', 'performance_lookups', 'performance_nine_box_bands', 'performance_reminder_logs', 'performance_template_versions', 'performance_templates', 'rating_scales',
            // HROne setup parity HP-8h / HP-8i: 360 feedback rounds, nomination sets, nominations (external client reviewers are
            // held by name and e-mail only; their link token is stored hashed and is a secret column), the answers (no link to
            // an anonymous reviewer), and periodic reviews.
            'feedback_nominations', 'feedback_participants', 'feedback_responses', 'feedback_rounds', 'performance_period_reviews'],
        'goals' => ['goal_check_ins', 'goal_plans', 'goal_template_items', 'goal_templates', 'goals', 'key_results', 'kras', 'performance_units'],
        'learning' => ['assessment_attempts', 'assessments', 'course_modules', 'course_versions', 'courses', 'learning_assignments', 'learning_certificates', 'learning_completions', 'learning_costs', 'learning_enrolments', 'learning_evidence', 'learning_instructors', 'learning_path_courses', 'learning_path_versions', 'learning_paths', 'learning_program_participants', 'learning_program_versions', 'learning_programs', 'learning_providers', 'learning_reminder_logs', 'training_session_attendees', 'training_sessions'],
        'career' => ['career_aspiration_entries', 'career_aspirations', 'career_goals', 'career_path_steps', 'career_path_versions', 'career_paths', 'career_profiles', 'career_tracks', 'development_needs', 'development_plan_items', 'development_plans', 'employee_skills', 'mobility_interests', 'role_requirement_versions', 'skill_assessments', 'skill_scale_versions', 'skill_scales'],
        'talent' => ['critical_position_assessments', 'critical_positions', 'readiness_assessments', 'succession_plans', 'successors', 'talent_assessment_model_versions', 'talent_assessment_models', 'talent_assessments', 'talent_development_actions', 'talent_pool_memberships', 'talent_pools', 'talent_profiles', 'talent_reminder_logs', 'talent_review_items', 'talent_review_sessions'],
        'compensation' => ['compensation_budgets', 'compensation_changes', 'compensation_cycles', 'compensation_ranges', 'compensation_reminder_logs', 'employee_salary_assignments', 'salary_structure_components', 'salary_structure_versions', 'salary_structures'],
        'assets' => ['asset_assignments', 'asset_categories', 'asset_disposals', 'asset_models', 'asset_movements', 'asset_repairs', 'asset_subtypes', 'assets',
            // HROne setup parity AS-B: asset requests (new, replacement, return; joiners and exits).
            'asset_requests'],
        'expense' => ['expense_categories', 'expense_category_limits', 'expense_groups', 'expense_location_group_places', 'expense_location_groups', 'expense_tax_types',
            // HROne setup parity EX-C / EX-D: receipts (files under tenants/{id}/expense), reports, advances.
            'expense_receipts', 'expense_receipt_taxes', 'expense_reports', 'expense_report_parts', 'expense_advances', 'expense_advance_entries',
            // HROne setup parity EX-F: travel requests (files under tenants/{id}/expense/travel), their legs and bookings, report trips.
            'travel_requests', 'travel_request_legs', 'expense_report_travel',
            // Owner decision 2026-10-29: the approval parts of trips spanning several workflow groups.
            // HROne parity XO-1 / XO-2 add columns only (travel_requests / expense_advances.cancel_refused_at,
            // expense_receipts.duplicate_matches): same category, exported and deleted with their rows, none secret.
            'travel_request_parts'],
        'service_desk' => ['service_definition_versions', 'service_definitions', 'service_desk_reminder_logs', 'service_resolver_rules', 'service_sla_policies', 'ticket_access_grants', 'ticket_categories', 'ticket_comments', 'ticket_transitions', 'tickets'],
        'grievances' => ['grievance_categories', 'grievance_notes', 'grievances'],
        'engagement' => ['audiences', 'campaign_items', 'employee_feedback', 'engagement_campaigns', 'engagement_identities', 'engagement_reminder_logs', 'survey_answers', 'survey_participations', 'survey_questions', 'survey_responses', 'survey_versions', 'surveys',
            // HROne setup parity HP-9b / HP-9c: mission statements, badges and the recognitions ledger.
            'mission_statements', 'badges', 'recognitions',
            // HROne setup parity HP-9d / HP-9e / HP-9f: the reward points ledger and budget lock, the social wall, the mood bot
            // (reasons, participations and check-ins, anonymity preserved).
            'reward_point_budgets', 'reward_point_entries', 'wall_posts', 'wall_reactions', 'wall_comments', 'mood_reasons', 'mood_participations', 'mood_checkins'],
        'communication' => ['announcement_reads', 'announcements', 'article_reads', 'article_versions', 'articles', 'communication_preferences', 'communication_recipients'],
        'notifications' => ['notification_deliveries', 'notification_rules', 'notification_templates'],
        'workflows' => ['inbox_archives', 'request_returns', 'task_comments', 'task_delegations',
            // HROne parity WO-1: bulk runs on registers and each record's outcome.
            'bulk_runs', 'bulk_run_items', 'workflow_actions', 'workflow_instances', 'workflow_tasks', 'workflow_versions', 'workflows',
            // HROne parity RQ-8: decisions taken in the Approval Center with the due time shown (the approver's Insight).
            'inbox_decisions'],
        'configuration' => ['configuration_changes', 'custom_field_values', 'custom_fields', 'employee_code_series', 'number_series', 'option_lists', 'exchange_rates', 'form_submissions', 'form_versions', 'forms', 'policies', 'policy_assignment_rules', 'policy_versions', 'tenant_features', 'tenant_settings', 'question_banks', 'question_bank_questions', 'feedback_templates'],
        // HROne parity CA-10 adds columns only (dashboards.preset, applicability, viewer_user_ids, email_user_ids, the e-mail
        // cadence, refresh_day): the shared views' organisation filter, named viewers and recipients (user ids) — same
        // category, exported and deleted with their rows, none secret.
        'analytics' => ['ai_interactions', 'dashboards', 'report_runs', 'report_schedules', 'reports'],
        'integrations' => ['external_references', 'inbound_events', 'integration_mappings', 'integration_systems', 'sso_connections', 'webhook_deliveries', 'webhook_endpoints',
            // HROne parity SV-26: each HTTP attempt of a delivery, and queued CSV exports of the webhook / API call logs.
            'webhook_delivery_attempts', 'integration_log_exports'],
        // HROne parity CA-11: the API call log.
        'api' => ['api_keys', 'api_request_logs'],
        'audit' => ['audit_events'],
        'billing' => ['billing_periods', 'negotiated_price_versions', 'negotiated_prices', 'price_change_notices', 'subscription_billing_terms', 'tenant_billing_profiles'],
        'invoices' => ['credit_notes', 'invoice_lines', 'invoice_tax_lines', 'invoice_tds_claims', 'invoices'],
        'payments' => ['payments', 'refunds'],
        'subscriptions' => ['entitlement_overrides', 'subscription_periods', 'tenant_entitlement_profiles', 'tenant_entitlements', 'tenant_plan_assignments', 'tenant_subscriptions'],
        'platform_operational' => ['api_idempotency_keys', 'employee_code_sequences', 'entitlement_shadow_observations', 'number_sequences', 'scheduler_claims', 'tenant_deferred_jobs', 'ux_metrics'],
        'offboarding_records' => ['legal_holds', 'tenant_deletion_steps', 'tenant_deletions', 'tenant_exports', 'tenant_offboardings', 'tenant_retention_terms'],
        'warehouse_exports' => [],
    ];

    /**
     * Tables without tenant_id that hold a tenant's rows through a parent row. A deletion removes them before their parent.
     *
     * @var array<string, array{category: string, parent: string, column: string, parent_column?: string, morph?: string}>
     */
    public const CHILDREN = [
        'role_user' => ['category' => 'identity', 'parent' => 'users', 'column' => 'user_id'],
        'permission_role' => ['category' => 'identity', 'parent' => 'roles', 'column' => 'role_id'],
        'sessions' => ['category' => 'identity', 'parent' => 'users', 'column' => 'user_id'],
        'password_reset_tokens' => ['category' => 'identity', 'parent' => 'users', 'column' => 'email', 'parent_column' => 'email'],
        'designation_employment_type' => ['category' => 'organisation', 'parent' => 'designations', 'column' => 'designation_id'],
        'notifications' => ['category' => 'notifications', 'parent' => 'users', 'column' => 'notifiable_id', 'morph' => 'notifiable_type'],
        'audit_event_changes' => ['category' => 'audit', 'parent' => 'audit_events', 'column' => 'audit_event_id'],
    ];

    /** @var list<string> tables with no tenant data, or Markedge's records that only name a tenant (never deleted by a tenant deletion) */
    public const PLATFORM = [
        'tenants', // the tombstone: kept, so retained records and certificates keep their tenant
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'permissions', 'compliance_rules', 'compliance_rule_verifications', 'compliance_rule_parameters', 'compliance_rule_notices', 'compliance_evidence_documents', 'statutory_export_layouts',
        'plans', 'plan_versions', 'plan_entitlements', 'plan_prices', 'plan_price_versions', 'billing_markets', 'billing_supplier_profiles', 'invoice_number_series', 'tax_rules', 'configuration_versions',
        'financial_approvals', 'payment_provider_events', // Markedge's records (subject_tenant_id / resolved_tenant_id)
        'audit_verification_runs', 'audit_chain_locks', 'retention_policies',
        // SaaS.10: Markedge's record of a signup (provisioned_tenant_id): what was applied for and accepted. The contact's
        // name and address are removed from it at activation (the tenant's user account holds them, under the deletion
        // controls); applications that never became a tenant keep them until a retention decision (D10-6).
        'signup_applications',
        // The holiday library: platform holiday lists tenants copy from (a copied holiday is the tenant's own row in `holidays`).
        'holiday_library_lists', 'holiday_library_entries', 'country_regions',
    ];

    /**
     * Files referenced from rows: table => list of path columns, each with its disk column or its configured disk role.
     *
     * @var array<string, list<array{path: string, disk_column?: string, disk?: string}>>
     */
    public const FILES = [
        'employee_documents' => [['path' => 'path', 'disk_column' => 'disk']],
        'employee_imports' => [['path' => 'path', 'disk_column' => 'disk']],
        'letter_templates' => [['path' => 'file_path', 'disk_column' => 'file_disk']],
        'letters' => [['path' => 'file_path', 'disk_column' => 'file_disk']],
        'letter_signatories' => [['path' => 'signature_path', 'disk_column' => 'signature_disk']],
        // HROne setup parity CA-2 / CA-4: company logos and document type samples (documents disk).
        'companies' => [['path' => 'logo_path', 'disk' => 'documents']],
        'document_types' => [['path' => 'sample_path', 'disk' => 'documents']],
        'learning_evidence' => [['path' => 'path', 'disk_column' => 'disk']],
        'report_runs' => [['path' => 'path', 'disk_column' => 'disk']],
        'announcements' => [['path' => 'attachment_path', 'disk' => 'documents']],
        'grievance_notes' => [['path' => 'attachment_path', 'disk' => 'documents']],
        'ticket_comments' => [['path' => 'attachment_path', 'disk' => 'documents']],
        'learning_certificates' => [['path' => 'document_path', 'disk' => 'documents']],
        'people' => [['path' => 'photo_path', 'disk' => 'documents']],
        'establishments' => [['path' => 'verification_evidence_path', 'disk' => 'compliance']],
        'legal_entities' => [['path' => 'verification_evidence_path', 'disk' => 'compliance']],
        'statutory_returns' => [['path' => 'export_path', 'disk' => 'compliance']],
        'compliance_report_packs' => [['path' => 'path', 'disk_column' => 'disk']],
        // HROne parity SV-26: queued webhook / API call log exports (documents disk, tenants/{id}/integrations).
        'integration_log_exports' => [['path' => 'path', 'disk_column' => 'disk']],
    ];

    /** @var list<string> credentials and secrets: never exported, from any table */
    public const SECRET_COLUMNS = [
        'password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes', 'secret', 'secret_hash',
        'token_hash', 'client_secret', 'inbound_secret', 'webhook_signing_secret',
        // HROne parity SV-26: a webhook's previous signing secret during its rotation grace period.
        'previous_secret',
    ];

    /** @var array<string, list<string>> derived lookup hashes and internal caches: not exported */
    public const EXCLUDED_COLUMNS = [
        'epf_return_entries' => ['uan_hash'], 'esi_return_entries' => ['ip_number_hash'], 'tds_quarterly_return_entries' => ['pan_hash'],
        'statutory_registrations' => ['registration_number_hash'], 'inbound_events' => ['payload'], 'notification_deliveries' => ['dedupe_key'],
        'learning_certificates' => ['verification_code'],
    ];

    /**
     * Anonymisation (identity only): the personal columns replaced when sign-in accounts must stay because retained
     * records still reference them. Values are fixed or derived from the row id (deterministic; unique where the column
     * is unique), so references stay intact and nothing identifies the person.
     *
     * @var array<string, array<string, string>>
     */
    public const ANONYMISED_COLUMNS = [
        'users' => [
            'name' => 'fixed:Deleted user', 'email' => 'id:deleted-user-{id}@deleted.invalid', 'password' => 'random',
            'remember_token' => 'null', 'app_authentication_secret' => 'null', 'app_authentication_recovery_codes' => 'null',
            'sso_subject' => 'null', 'sso_connection_id' => 'null', 'external_id' => 'null', 'email_verified_at' => 'null',
            'last_login_at' => 'null', 'status' => 'fixed:suspended', 'timezone' => 'null', 'locale' => 'null',
        ],
    ];

    /** @return list<string> */
    public static function categories(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /** @return array<string, string> table => category, for every table with tenant_id */
    public static function tableCategories(): array
    {
        $map = [];
        foreach (self::TABLES as $category => $tables) {
            foreach ($tables as $table) {
                $map[$table] = $category;
            }
        }

        return $map;
    }

    public static function categoryOf(string $table): ?string
    {
        return self::tableCategories()[$table] ?? (self::CHILDREN[$table]['category'] ?? null);
    }

    /** @return list<string> the category's child tables (no tenant_id) */
    public static function childrenOf(string $category): array
    {
        return array_keys(array_filter(self::CHILDREN, fn (array $c) => $c['category'] === $category));
    }

    /** @return list<string> */
    public static function excludedColumns(string $table): array
    {
        return [...self::SECRET_COLUMNS, ...(self::EXCLUDED_COLUMNS[$table] ?? [])];
    }

    public static function disk(string $role): string
    {
        return (string) match ($role) {
            'documents' => config('peopleos.documents.disk', 'local'),
            'compliance' => config('peopleos.storage.compliance_disk', 'local'),
            'warehouse' => config('peopleos.enterprise.warehouse_disk', 'local'),
            default => throw new InvalidArgumentException("Unknown disk role [{$role}]."),
        };
    }

    /**
     * Storage prefixes a tenant owns (generated folders, and anything left behind by a row), by category. A deletion
     * sweeps them after the rows' own files, so no orphaned file remains. Prefixes are exact (no wildcard), so a tenant
     * prefix can never reach platform files.
     *
     * @return array<string, list<array{disk: string, prefix: string}>>
     */
    public static function prefixes(Tenant $tenant): array
    {
        $id = $tenant->getKey();

        return [
            'documents' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/employees"], ['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/letters"],
                // HROne setup parity CA-4: document type sample files.
                ['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/document-types"]],
            // HROne setup parity CA-1: files held by Setup settings (e.g. the organisation's logo).
            'configuration' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/settings"]],
            'employment' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/imports"]],
            'grievances' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/grievances"]],
            'communication' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/communication"]],
            'service_desk' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/servicedesk"]],
            // HROne setup parity (Expense): receipt and advance files (EX-C, EX-D; expense_receipts.attachments and
            // expense_advances.attachments list them) and travel attachments (EX-F) are kept under this folder.
            'expense' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/expense"]],
            // HROne setup parity HP-9c: attachments of recognitions (recognitions.attachments lists them).
            // HROne setup parity HP-9e: attachments of HR posts on the social wall (wall_posts.attachments lists them).
            'engagement' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/recognition"], ['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/wall"]],
            // HROne setup parity AS-A: product (asset model) documents (asset_models.documents lists them).
            'assets' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/assets"]],
            'analytics' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/reports"]],
            // HROne parity SV-26: queued webhook / API call log exports.
            'integrations' => [['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/integrations"]],
            'learning' => [['disk' => self::disk('documents'), 'prefix' => "learning/certificates/{$id}"]],
            'statutory' => [['disk' => self::disk('compliance'), 'prefix' => "statutory-exports/{$id}"]],
            // Record verification evidence of the tenant's own records (compliance-evidence/{table}/{tenant}/...). Never a
            // wildcard: platform rule and layout evidence live beside it (compliance-evidence/layouts/{layout id}/...).
            'organisation' => [
                // HROne setup parity CA-2: company logos.
                ['disk' => self::disk('documents'), 'prefix' => "tenants/{$id}/organisation"],
                ['disk' => self::disk('compliance'), 'prefix' => "compliance-evidence/establishments/{$id}"],
                ['disk' => self::disk('compliance'), 'prefix' => "compliance-evidence/legal_entities/{$id}"],
            ],
            'warehouse_exports' => [['disk' => self::disk('warehouse'), 'prefix' => "warehouse/{$tenant->slug}"]],
        ];
    }
}
