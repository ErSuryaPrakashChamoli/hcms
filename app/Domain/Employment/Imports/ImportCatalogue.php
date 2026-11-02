<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Writer;

/**
 * Data import: every import process an organisation can run, under the head it belongs to (HROne's Import → Select
 * process: Core, Workforce, Time Office, Payroll, Performance, Engage, Asset, Project Management, Integration;
 * recruitment runs in the separate recruitment system). A process is an upload type of the staged pipeline
 * (ImportTypes) with an optional preset:
 * - `mode`: the intent is fixed (new | update) instead of asked;
 * - `defaults`: field => value used for cells left blank (one-row-one-change uploads; RowImports reads options.defaults);
 * - `columns`: the fields its template and format guide show, in order (the upload still accepts every field).
 * Several processes can share a type (Opening leave balance and Leave balance are both leave_balances). A process
 * shows only when its type is built and the person may run it. A process PeopleOS deliberately does not take from a
 * file has no type and says why (`na`); it is listed so nobody looks for it in vain.
 */
final class ImportCatalogue
{
    /** head => [label, icon, what it holds] */
    public const HEADS = [
        'core' => ['Core', 'heroicon-o-building-office-2', 'Organisation masters: branches, departments, designations, grades, places and customers.'],
        'workforce' => ['Workforce', 'heroicon-o-users', 'Employees and their profile: personal and family details, education, bank, skills and more.'],
        'time_office' => ['Time Office', 'heroicon-o-clock', 'Attendance, shifts, leave balances and requests, overtime and paid-day overrides.'],
        'payroll' => ['Payroll', 'heroicon-o-banknotes', 'Salary, statutory overrides, tax declarations, loans, reimbursements, arrears and settlements.'],
        'performance' => ['Performance', 'heroicon-o-chart-bar', 'Goals and OKRs, initiatives, review ratings, normalisation and improvement plans.'],
        'engage' => ['Engage', 'heroicon-o-sparkles', 'Badges, reward points and event attendance.'],
        'asset' => ['Asset', 'heroicon-o-computer-desktop', 'Asset types, sub types, products and the asset register.'],
        'projects' => ['Project Management', 'heroicon-o-briefcase', 'Projects and the customers they are for.'],
        'integration' => ['Integration', 'heroicon-o-link', 'Accounting (JV) codes for companies, branches, departments, employees and the other masters.'],
    ];

    /** @var array<string, array{head: string, label: string, type: string, about: string, mode?: string, defaults?: array<string, string>, columns?: list<string>, na?: string}> */
    public const PROCESSES = [
        // ── Core (package DI-1) ──
        'core.branches' => ['head' => 'core', 'label' => 'Branches', 'type' => 'locations', 'about' => 'Work locations (branches) with company, address and time zone.'],
        'core.business_regions' => ['head' => 'core', 'label' => 'Business regions', 'type' => 'business_regions', 'about' => 'Business regions that group branches.'],
        'core.business_units' => ['head' => 'core', 'label' => 'Business units', 'type' => 'business_units', 'about' => 'Business units of each company.'],
        'core.cities' => ['head' => 'core', 'label' => 'Cities', 'type' => 'geo_cities', 'about' => 'Cities, each in a state (and district).'],
        'core.customers' => ['head' => 'core', 'label' => 'Customers', 'type' => 'customers', 'about' => 'Customers that projects are run for.'],
        'core.departments' => ['head' => 'core', 'label' => 'Departments', 'type' => 'departments', 'about' => 'Departments of each company.'],
        'core.designation_skills' => ['head' => 'core', 'label' => 'Designation wise skills', 'type' => 'designation_skills', 'about' => 'The skills each designation needs, with the level expected.'],
        'core.designations' => ['head' => 'core', 'label' => 'Designations', 'type' => 'designations', 'about' => 'Designations with level, grade and experience range.'],
        'core.dimensions' => ['head' => 'core', 'label' => 'Dimension', 'type' => 'dimensions', 'about' => 'Your own organisation dimension and its values.'],
        'core.districts' => ['head' => 'core', 'label' => 'Districts', 'type' => 'geo_districts', 'about' => 'Districts, each in a state.'],
        'core.grades' => ['head' => 'core', 'label' => 'Grades', 'type' => 'grades', 'about' => 'Grades with their level and rank.'],
        'core.levels' => ['head' => 'core', 'label' => 'Levels', 'type' => 'levels', 'about' => 'Levels with their rank.'],
        'core.regions' => ['head' => 'core', 'label' => 'Regions', 'type' => 'regions', 'about' => 'Regions (zones) that branches belong to.'],
        'core.skills' => ['head' => 'core', 'label' => 'Skill master', 'type' => 'skills', 'about' => 'The skill library.'],
        'core.states' => ['head' => 'core', 'label' => 'States', 'type' => 'geo_states', 'about' => 'States used in addresses and statutory settings.'],
        'core.sub_branches' => ['head' => 'core', 'label' => 'Sub branches', 'type' => 'sub_branches', 'about' => 'Locations inside a branch.'],
        'core.sub_departments' => ['head' => 'core', 'label' => 'Sub departments', 'type' => 'sub_departments', 'about' => 'Departments inside a department.'],

        // ── Workforce (package DI-2; previous employment: DI-4) ──
        'workforce.bank' => ['head' => 'workforce', 'label' => 'Bank', 'type' => 'bank_accounts', 'about' => 'Salary bank accounts.'],
        'workforce.children' => ['head' => 'workforce', 'label' => 'Children', 'type' => 'family_members', 'about' => 'Children of employees (son or daughter by the gender).', 'defaults' => ['relation' => 'child']],
        'workforce.contract' => ['head' => 'workforce', 'label' => 'Contract', 'type' => 'employee_contracts', 'about' => 'Contract terms of contract staff: agency, contract number and dates.'],
        'workforce.dependents' => ['head' => 'workforce', 'label' => 'Dependents', 'type' => 'family_members', 'about' => 'Family members who depend on the employee.', 'defaults' => ['is_dependent' => 'yes']],
        'workforce.education' => ['head' => 'workforce', 'label' => 'Education', 'type' => 'qualifications', 'about' => 'Qualifications: degree, institute, year and score.'],
        'workforce.employee' => ['head' => 'workforce', 'label' => 'Employee', 'type' => 'employees', 'about' => 'Update employees on file: names, contact details and identifiers.', 'mode' => 'update',
            'columns' => ['employee.employee_code', 'person.first_name', 'person.middle_name', 'person.last_name', 'person.preferred_name', 'person.personal_email', 'person.personal_phone',
                'employee.work_email', 'employee.work_phone', 'employee.device_user_id', 'employee.external_reference']],
        'workforce.employee_advance' => ['head' => 'workforce', 'label' => 'Employee (advance)', 'type' => 'position_changes', 'about' => 'Dated job changes: company, branch, department, designation, grade, manager and more.'],
        'workforce.employee_code' => ['head' => 'workforce', 'label' => 'Employee code', 'type' => 'employee_codes', 'about' => 'Change employee codes (old code to new code).'],
        'workforce.other_details' => ['head' => 'workforce', 'label' => 'Employee other details', 'type' => 'custom_field_values', 'about' => 'Values of your own employee fields.'],
        'workforce.employee_settings' => ['head' => 'workforce', 'label' => 'Employee settings', 'type' => 'employee_settings', 'about' => 'Per-employee settings: attendance device id, work schedule, pay mode, birthday sharing and sign-in time zone.'],
        'workforce.employee_skills' => ['head' => 'workforce', 'label' => 'Employee skills', 'type' => 'employee_skills', 'about' => 'Skills each employee has, with level.'],
        'workforce.family' => ['head' => 'workforce', 'label' => 'Family details (parent and spouse)', 'type' => 'family_members', 'about' => 'Parents and spouse of employees.'],
        'workforce.health_insurance' => ['head' => 'workforce', 'label' => 'Health insurance', 'type' => 'health_insurance', 'about' => 'Health insurance policies and the members insured.'],
        'workforce.new_hire' => ['head' => 'workforce', 'label' => 'New hire', 'type' => 'employees', 'about' => 'Add new employees with the essentials.', 'mode' => 'new',
            'columns' => ['employee.employee_code', 'person.first_name', 'person.last_name', 'person.date_of_birth', 'person.gender', 'person.personal_email', 'person.personal_phone',
                'employee.joining_date', 'employee.work_email', 'position.company_code', 'position.location_code', 'position.department_code', 'position.designation_code',
                'position.employment_type_code', 'manager_code']],
        'workforce.new_hire_advance' => ['head' => 'workforce', 'label' => 'New hires (advance)', 'type' => 'employees', 'about' => 'Add new employees with every field: personal, job and organisation details.', 'mode' => 'new'],
        'workforce.nominees' => ['head' => 'workforce', 'label' => 'Nominee details', 'type' => 'nominations', 'about' => 'Nominees for PF, gratuity, insurance and other benefits, with shares.'],
        'workforce.personal_details' => ['head' => 'workforce', 'label' => 'Personal details', 'type' => 'employees', 'about' => 'Update personal details of employees on file.', 'mode' => 'update',
            'columns' => ['employee.employee_code', 'person.salutation', 'person.first_name', 'person.middle_name', 'person.last_name', 'person.preferred_name', 'person.date_of_birth',
                'person.gender', 'person.marital_status', 'person.nationality', 'person.personal_email', 'person.personal_phone_country_code', 'person.personal_phone', 'person.is_fresher']],
        'workforce.previous_employment' => ['head' => 'workforce', 'label' => 'Previous employment', 'type' => 'previous_employment', 'about' => 'Income and tax from a previous employer this financial year.'],
        'workforce.shortcut' => ['head' => 'workforce', 'label' => 'Shortcut', 'type' => 'shortcuts', 'about' => 'Pin page shortcuts for employees.'],
        'workforce.vendor' => ['head' => 'workforce', 'label' => 'Vendor', 'type' => 'vendors', 'about' => 'Business partners: staffing agencies, contractors and other vendors.'],
        'workforce.work_experience' => ['head' => 'workforce', 'label' => 'Work experience', 'type' => 'work_experience', 'about' => 'Earlier jobs: employer, designation and dates.'],

        // ── Time Office (package DI-3) ──
        'time_office.checkins' => ['head' => 'time_office', 'label' => 'Attendance check-ins', 'type' => 'punches', 'about' => 'Punches (check-ins and check-outs) from devices or other systems.'],
        'time_office.attendance_status' => ['head' => 'time_office', 'label' => 'Attendance status', 'type' => 'day_overrides', 'about' => 'Set the attendance status of a day, or of each half.',
            'columns' => ['employee_code', 'date', 'status', 'first_half', 'second_half', 'reason']],
        'time_office.monthly_attendance' => ['head' => 'time_office', 'label' => 'Monthly attendance', 'type' => 'attendance_month', 'about' => 'One sheet per month: a row per employee, a column per day.'],
        'time_office.comp_off' => ['head' => 'time_office', 'label' => 'Compensatory off', 'type' => 'comp_off_credits', 'about' => 'Comp-off credits for days worked, with expiry, credited as approved comp-off claims.'],
        'time_office.geo_locations' => ['head' => 'time_office', 'label' => 'Geo work locations', 'type' => 'geo_locations', 'about' => 'Places employees may check in from.'],
        'time_office.leave_balance' => ['head' => 'time_office', 'label' => 'Leave balance', 'type' => 'leave_balances', 'about' => 'Add or take away leave days.', 'defaults' => ['kind' => 'adjustment']],
        'time_office.leave_requests' => ['head' => 'time_office', 'label' => 'Leave requests', 'type' => 'leave_history', 'about' => 'Leave already taken or approved.'],
        'time_office.opening_balance' => ['head' => 'time_office', 'label' => 'Opening leave balance', 'type' => 'leave_balances', 'about' => 'Leave balances at the start of the year.', 'defaults' => ['kind' => 'opening']],
        'time_office.arrear_days' => ['head' => 'time_office', 'label' => 'Override arrear days', 'type' => 'attendance_arrears', 'about' => 'Days of an earlier month to pay or recover in the next run, set by HR per employee and month.'],
        'time_office.overtime' => ['head' => 'time_office', 'label' => 'Override overtime', 'type' => 'overtime_hours', 'about' => 'Approved overtime by day.'],
        'time_office.paid_days' => ['head' => 'time_office', 'label' => 'Override paid days', 'type' => 'paid_day_adjustments', 'about' => 'Set or reduce the paid days of a month (while paid-day adjustments are switched on).'],
        'time_office.prev_month_overtime' => ['head' => 'time_office', 'label' => 'Override previous month overtime', 'type' => 'overtime_arrears', 'about' => 'Approved overtime for days of an earlier month.'],
        'time_office.working_hours' => ['head' => 'time_office', 'label' => 'Override working hours', 'type' => 'day_overrides', 'about' => 'Set the hours worked on a day.',
            'columns' => ['employee_code', 'date', 'worked_minutes', 'reason']],
        'time_office.rh_balance' => ['head' => 'time_office', 'label' => 'Restricted holiday balance', 'type' => 'leave_balances', 'about' => 'Opening balances of the restricted-holiday leave type (RH unless the file names another leave type of that category).',
            'defaults' => ['kind' => 'opening', 'category' => 'restricted', 'leave_code' => 'RH'], 'columns' => ['employee_code', 'leave_code', 'days', 'year', 'note']],
        'time_office.shift' => ['head' => 'time_office', 'label' => 'Shift', 'type' => 'shift_roster', 'about' => 'Shift roster: the shift each employee works on each day.'],

        // ── Payroll (package DI-4) ──
        'payroll.form_12baa' => ['head' => 'payroll', 'label' => '12BAA declaration', 'type' => 'tax_12baa', 'about' => 'Tax deducted or collected elsewhere (TDS / TCS) that the employee declares.'],
        'payroll.bonus_slabs' => ['head' => 'payroll', 'label' => 'Bonus slabs', 'type' => '', 'about' => 'Bonus percentages by salary slab.',
            'na' => 'Not uploaded in PeopleOS: bonus percentages, wage ceilings, eligibility and minimum days come only from the verified Payment of Bonus rule, never from a file. The organisation sets its employer percentage (inside the rule\'s range) in the bonus run.'],
        'payroll.change_pay_group' => ['head' => 'payroll', 'label' => 'Change pay group', 'type' => 'pay_group_changes', 'about' => 'Move employees to another salary structure (pay group).'],
        'payroll.ctc_auto' => ['head' => 'payroll', 'label' => 'CTC (employee CTC auto)', 'type' => 'salaries', 'about' => 'Annual CTC; components are worked out from the salary structure.'],
        'payroll.employee_statutory' => ['head' => 'payroll', 'label' => 'Employee statutory', 'type' => 'employee_statutory', 'about' => 'PF, ESI, PT and LWF applicability and numbers (UAN, ESI number).'],
        'payroll.esi_override' => ['head' => 'payroll', 'label' => 'ESI (override ESI)', 'type' => 'stat_overrides', 'about' => 'ESI to use for a payroll month (employee share; ESI_ER in Line for the employer\'s).', 'defaults' => ['line' => 'ESI_EE']],
        'payroll.hra' => ['head' => 'payroll', 'label' => 'HRA declaration', 'type' => 'hra_declarations', 'about' => 'Rent paid, landlord and city, for the HRA exemption.'],
        'payroll.outside_income' => ['head' => 'payroll', 'label' => 'Income outside system', 'type' => 'outside_income', 'about' => 'Income paid outside payroll that counts for tax.'],
        'payroll.investments' => ['head' => 'payroll', 'label' => 'Investments', 'type' => 'investments', 'about' => 'Tax-saving investment declarations (80C, 80D …).'],
        'payroll.loans' => ['head' => 'payroll', 'label' => 'Loans', 'type' => 'loans', 'about' => 'Loans and advances with their instalments.'],
        'payroll.lwf_arrear' => ['head' => 'payroll', 'label' => 'LWF arrear', 'type' => 'stat_overrides', 'about' => 'Labour welfare fund to recover in a payroll month, arrears included (employee share; LWF_ER in Line for the employer\'s).', 'defaults' => ['line' => 'LWF_EE']],
        'payroll.tds_override' => ['head' => 'payroll', 'label' => 'Override TDS', 'type' => 'stat_overrides', 'about' => 'Income tax (TDS) to deduct in a payroll month.', 'defaults' => ['line' => 'TDS']],
        // DI-4: PeopleOS never works a perquisite's value out of the pay group (no verified valuation rule), so both presets take
        // the value for the year; "auto" leaves out the recovery (TDS spreads the tax over the remaining months), "manual" has it.
        'payroll.perquisites_auto' => ['head' => 'payroll', 'label' => 'Perquisites (auto)', 'type' => 'perquisites', 'about' => 'Perquisite values for the year; TDS spreads the tax over the remaining months.',
            'columns' => ['employee_code', 'financial_year', 'type', 'description', 'value', 'reference']],
        'payroll.perquisites_manual' => ['head' => 'payroll', 'label' => 'Perquisites (manual)', 'type' => 'perquisites', 'about' => 'Perquisites with the value and what the employee pays back; TDS taxes the difference.'],
        'payroll.pf_override' => ['head' => 'payroll', 'label' => 'PF (before salary)', 'type' => 'stat_overrides', 'about' => 'Provident fund to use for a payroll month (employee share; PF_ER or VPF in Line for the others).', 'defaults' => ['line' => 'PF_EE']],
        'payroll.pf_arrear' => ['head' => 'payroll', 'label' => 'PF arrear (before arrear)', 'type' => '', 'about' => 'Provident fund on arrears paid this month.',
            'na' => 'Not yet: PF on arrears is filed in a separate EPFO arrear return, and that file layout is not yet verified in PeopleOS. Folding it into the month\'s PF would report other months\' wages in this month\'s return, so it comes with the arrears return.'],
        'payroll.pf_options' => ['head' => 'payroll', 'label' => 'PF options (VPF)', 'type' => 'pf_options', 'about' => 'Voluntary PF and PF wage options per employee.'],
        'payroll.previous_salary' => ['head' => 'payroll', 'label' => 'Previous month salary', 'type' => 'previous_salaries', 'about' => 'Salary paid before PeopleOS this financial year, for tax and year-to-date figures.'],
        'payroll.pt_override' => ['head' => 'payroll', 'label' => 'PT (before salary)', 'type' => 'stat_overrides', 'about' => 'Professional tax to use for a payroll month.', 'defaults' => ['line' => 'PT']],
        'payroll.reimbursement_bills' => ['head' => 'payroll', 'label' => 'Reimbursement bills (pay by default)', 'type' => 'reimbursement_bills', 'about' => 'Bills for reimbursements paid with salary every month; what they pay beyond the bills is taxed from the bills date.'],
        'payroll.reimbursement_claims' => ['head' => 'payroll', 'label' => 'Reimbursement claims (pay on claim)', 'type' => 'reimbursement_claims', 'about' => 'Claims for reimbursements paid when claimed.'],
        'payroll.reimbursement_opening' => ['head' => 'payroll', 'label' => 'Reimbursement opening balance (pay on claim)', 'type' => 'reimbursement_opening', 'about' => 'Unclaimed reimbursement balances brought forward.'],
        'payroll.salary_arrear' => ['head' => 'payroll', 'label' => 'Salary arrear', 'type' => 'salary_arrears', 'about' => 'Arrears of earlier months paid in a payroll month.'],
        'payroll.salary_structure_manual' => ['head' => 'payroll', 'label' => 'Salary structure (employee CTC manual)', 'type' => 'salary_components', 'about' => 'CTC with the amount of each component given.'],
        'payroll.settlement' => ['head' => 'payroll', 'label' => 'Settlement', 'type' => 'settlements', 'about' => 'Full and final settlement amounts for leavers.'],
        'payroll.variable_pay' => ['head' => 'payroll', 'label' => 'Variable pay', 'type' => 'variable_pay', 'about' => 'Variable pay to pay in a payroll month.'],

        // ── Performance (package DI-5) ──
        'performance.goal_bank' => ['head' => 'performance', 'label' => 'Goal bank', 'type' => 'goal_bank', 'about' => 'Ready-made goals people pick from (the KRA library with suggested KPIs).'],
        'performance.goals' => ['head' => 'performance', 'label' => 'Goals', 'type' => 'goals', 'about' => 'Goals of employees, with weight and target.'],
        'performance.initiatives' => ['head' => 'performance', 'label' => 'Initiatives', 'type' => 'initiatives', 'about' => 'Initiatives employees take part in.'],
        'performance.normalise_final' => ['head' => 'performance', 'label' => 'Normalize performance ratings', 'type' => 'rating_normalisation', 'about' => 'Normalised final ratings of a performance cycle.'],
        'performance.normalise_review' => ['head' => 'performance', 'label' => 'Normalize review ratings', 'type' => 'review_normalisation', 'about' => 'Normalised ratings of a periodic review.'],
        'performance.okr_final' => ['head' => 'performance', 'label' => 'OKR final achievement', 'type' => 'okr_final', 'about' => 'Final achieved value of each key result, as a check-in.'],
        'performance.okr_total' => ['head' => 'performance', 'label' => 'OKR total achievement', 'type' => 'okr_total', 'about' => 'Total achievement of each objective, given by HR (while achievement override is switched on).'],
        'performance.improvement_plans' => ['head' => 'performance', 'label' => 'Performance improvement plan', 'type' => 'improvement_plans', 'about' => 'Improvement plans with their dates, reviewer, objectives and checkpoints, opened as drafts.'],
        'performance.review_ratings' => ['head' => 'performance', 'label' => 'Review ratings', 'type' => 'review_ratings', 'about' => 'Ratings given in the periodic reviews of a cycle.'],

        // ── Engage (package DI-5) ──
        'engage.badges' => ['head' => 'engage', 'label' => 'Badge allocation', 'type' => 'badge_awards', 'about' => 'Badges HR allocates to employees on behalf of the organisation.'],
        'engage.event_attendance' => ['head' => 'engage', 'label' => 'Event attendance', 'type' => 'event_attendance', 'about' => 'Who attended an event.'],
        'engage.rewards' => ['head' => 'engage', 'label' => 'Rewards', 'type' => 'reward_points', 'about' => 'Reward points credited from the budget or debited.'],

        // ── Asset (package DI-5) ──
        'asset.subtypes' => ['head' => 'asset', 'label' => 'Asset sub types', 'type' => 'asset_subtypes', 'about' => 'Sub types inside each asset type.'],
        'asset.types' => ['head' => 'asset', 'label' => 'Asset types', 'type' => 'asset_types', 'about' => 'Asset types (categories).'],
        'asset.assets' => ['head' => 'asset', 'label' => 'Assets', 'type' => 'assets', 'about' => 'The asset register: tag, product, serial number and purchase details.'],
        'asset.products' => ['head' => 'asset', 'label' => 'Products', 'type' => 'asset_products', 'about' => 'Products (make and model) of each asset type.'],

        // ── Project Management (package DI-1) ──
        'projects.projects' => ['head' => 'projects', 'label' => 'Projects', 'type' => 'projects', 'about' => 'Projects with customer, dates, manager and members.'],

        // ── Integration (package DI-1) ──
        'integration.jv_branch' => ['head' => 'integration', 'label' => 'JV branch mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of branches.', 'defaults' => ['dimension' => 'location'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_business_unit' => ['head' => 'integration', 'label' => 'JV business unit mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of business units.', 'defaults' => ['dimension' => 'business_unit'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_company' => ['head' => 'integration', 'label' => 'JV company mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of companies.', 'defaults' => ['dimension' => 'company'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_department' => ['head' => 'integration', 'label' => 'JV department mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of departments.', 'defaults' => ['dimension' => 'department'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_designation' => ['head' => 'integration', 'label' => 'JV designation mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of designations.', 'defaults' => ['dimension' => 'designation'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_dimension' => ['head' => 'integration', 'label' => 'JV dimension mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of your dimension values.', 'defaults' => ['dimension' => 'dimension'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_employee' => ['head' => 'integration', 'label' => 'JV employee mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of employees.', 'defaults' => ['dimension' => 'employee'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_grade' => ['head' => 'integration', 'label' => 'JV grade mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of grades.', 'defaults' => ['dimension' => 'grade'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_level' => ['head' => 'integration', 'label' => 'JV level mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of levels.', 'defaults' => ['dimension' => 'level'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_region' => ['head' => 'integration', 'label' => 'JV region mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of regions.', 'defaults' => ['dimension' => 'region'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_sub_branch' => ['head' => 'integration', 'label' => 'JV sub branch mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of sub branches.', 'defaults' => ['dimension' => 'sub_branch'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_sub_department' => ['head' => 'integration', 'label' => 'JV sub department mapping', 'type' => 'jv_mappings', 'about' => 'Accounting codes of sub departments.', 'defaults' => ['dimension' => 'sub_department'], 'columns' => ['code', 'jv_code', 'system_code']],
        // PeopleOS: the ledgers the payroll journal voucher posts each pay component to (Payroll › runs › Journal voucher).
        'integration.jv_pay_component' => ['head' => 'integration', 'label' => 'JV pay component mapping', 'type' => 'jv_mappings', 'about' => 'Ledger codes of earnings, deductions and employer contributions, for the payroll journal voucher.', 'defaults' => ['dimension' => 'pay_component'], 'columns' => ['code', 'jv_code', 'system_code']],
        'integration.jv_pay_component_payable' => ['head' => 'integration', 'label' => 'JV pay component payable mapping', 'type' => 'jv_mappings', 'about' => 'Payable ledgers of deductions and employer contributions, for the payroll journal voucher.', 'defaults' => ['dimension' => 'pay_component_payable'], 'columns' => ['code', 'jv_code', 'system_code']],
    ];

    /** The generic intent of a one-row-one-change upload (HROne's Import intent), when its service names none of its own. */
    public const ROW_MODES = [
        'new' => ['Insert new records', 'Creates records that are not in PeopleOS yet. Rows for records already on file are skipped.'],
        'update' => ['Update existing records', 'Changes records already on file. Rows for records not on file are skipped.'],
        'both' => ['Insert and update', 'Creates what is missing and updates what is on file.'],
    ];

    /** @return array{key: string, head: string, label: string, type: string, about: string, mode?: string, defaults?: array<string, string>, columns?: list<string>}|null */
    public static function find(?string $key): ?array
    {
        return $key !== null && isset(self::PROCESSES[$key]) ? ['key' => $key] + self::PROCESSES[$key] : null;
    }

    /** Whether the process's upload type exists on the pipeline. */
    public static function built(array $process): bool
    {
        return ($process['type'] ?? '') !== '' && array_key_exists($process['type'], ImportTypes::labels());
    }

    /** Why PeopleOS does not take this process from a file, or null. */
    public static function notApplicable(array $process): ?string
    {
        return $process['na'] ?? null;
    }

    /** Whether this person may run the process (its type is built and they may work on uploads of it). */
    public static function allowed(?User $user, array $process): bool
    {
        return self::built($process) && ImportTypes::allowed($user, $process['type']);
    }

    /**
     * The processes this person may run, by head, matching the search (label, about or head name).
     *
     * @return array<string, list<array<string, mixed>>> head => processes
     */
    public static function visible(?User $user, ?string $search = null): array
    {
        $needle = Str::lower(trim((string) $search));
        $out = [];
        foreach (self::PROCESSES as $key => $process) {
            $process = ['key' => $key] + $process;
            if (! self::allowed($user, $process) && self::notApplicable($process) === null) {
                continue;
            }
            if ($needle !== '' && ! Str::contains(Str::lower($process['label'].' '.$process['about'].' '.self::HEADS[$process['head']][0]), $needle)) {
                continue;
            }
            $out[$process['head']][] = $process;
        }
        foreach ($out as &$processes) {
            usort($processes, fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));
        }

        return array_intersect_key(array_replace(array_fill_keys(array_keys(self::HEADS), null), $out), $out);
    }

    /** @return array<string, array{0: string, 1: string}>|null the intents to offer: null when the upload only adds (punches) or the process fixes it */
    public static function modes(array $process): ?array
    {
        $modes = ImportTypes::modes($process['type']);
        if ($modes === null || isset($process['mode'])) {
            return null;
        }

        return $modes;
    }

    /** The intent a process starts with: its fixed mode, or Both. */
    public static function defaultMode(array $process): string
    {
        return $process['mode'] ?? 'both';
    }

    /** @return array<string, array{0: string, 1: bool}> field => [label, required], every field the upload accepts */
    public static function fields(array $process): array
    {
        $class = ImportTypes::serviceClass($process['type']);

        return $class::fields();
    }

    /**
     * The CSV for a process: the header row (format) or the header row and example rows (sample), limited to the
     * process's columns, with its defaults in the example cells. Built from the upload's own template, so the headers
     * map automatically. The monthly attendance sheet has a column per day of the month in $options['month'].
     */
    public static function csv(array $process, bool $sample, array $options = []): string
    {
        $service = ImportTypes::service($process['type']);
        $template = $service instanceof AttendanceSheetImports
            ? $service->template((string) ($options['month'] ?? AttendanceSheetImports::currentMonth()))
            : $service->template();
        $records = iterator_to_array(Reader::fromString($template)->getRecords(), false);
        $header = array_map(fn ($h) => trim((string) $h), $records[0] ?? []);
        $rows = array_slice($records, 1);

        $keep = array_keys($header);
        $fields = self::fields($process);
        if (isset($process['columns'])) {
            $labels = array_map(fn (string $field) => $fields[$field][0] ?? $field, $process['columns']);
            $keep = array_values(array_filter(array_map(fn (string $label) => array_search($label, $header, true), $labels), fn ($i) => $i !== false));
        }
        foreach ($process['defaults'] ?? [] as $field => $value) {
            $at = array_search($fields[$field][0] ?? $field, $header, true);
            if ($at !== false) {
                // An upload whose examples cover several values of the field (two rows or more per value, e.g. the JV mappings
                // per dimension) shows the process only the rows written for its own value. Otherwise the value is filled into
                // every example row, so an upload's examples must stay valid under each preset (LeaveBalanceImports: positive days).
                $own = array_values(array_filter($rows, fn (array $row) => mb_strtolower(trim((string) ($row[$at] ?? ''))) === mb_strtolower($value)));
                if (count($own) >= 2) {
                    $rows = $own;
                }
                foreach ($rows as &$row) {
                    $row[$at] = $value;
                }
                unset($row);
            }
        }

        $csv = Writer::fromString();
        $csv->insertOne(array_map(fn ($i) => $header[$i], $keep));
        if ($sample) {
            foreach ($rows as $row) {
                $csv->insertOne(array_map(fn ($i) => $row[$i] ?? '', $keep));
            }
        }

        return $csv->toString();
    }

    /** The download name of a process's CSV, e.g. "branches-sample.csv". */
    public static function fileName(array $process, bool $sample): string
    {
        return Str::slug($process['label']).($sample ? '-sample' : '-format').'.csv';
    }
}
