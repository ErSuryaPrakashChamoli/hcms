<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\Report;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Ready-made report definitions an HR admin can add to their reports in one click (§84): the standard
 * catalogue of Indian and global HRMS products (employee master, attendance and leave registers, salary
 * and statutory registers, joiners and exits …) expressed over the analytics datasets. A template is only
 * offered to users who may read its dataset; the saved report then runs under the viewer's own rights.
 */
final class ReportLibrary
{
    /** Display order of template categories. */
    public const CATEGORIES = ['People', 'Attendance', 'Leave', 'Payroll & compensation', 'Statutory & income tax', 'Expense & travel', 'Joining & exit', 'Performance & learning', 'Assets & service desk', 'Administration'];

    private const ACTIVE = 'joined,probation,confirmed,active,on_leave,notice_period';

    private const PAY_LINE = ['employee_code', 'name', 'department', 'designation'];

    public function __construct(private readonly DatasetRegistry $datasets) {}

    /** @return list<array{key: string, category: string, name: string, description: string, dataset: string, definition: array<string, mixed>}> */
    public function all(): array
    {
        $who = ['employee_code', 'name', 'department', 'designation', 'location'];
        $active = self::when('employee_status', 'in', self::ACTIVE);

        $templates = [
            // People ------------------------------------------------------------------------------------
            ['employee_master', 'People', 'Employee master', 'Every active employee with organisation, manager, type and contact details.', 'employees',
                self::rows(['employee_code', 'name', 'gender', 'joining_date', 'company', 'location', 'department', 'designation', 'grade', 'employment_type', 'manager', 'work_email', 'work_phone', 'lifecycle_state'], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'employee_code')],
            ['employee_directory', 'People', 'Employee directory', 'Name, role, location and work contact details of active employees.', 'employees',
                self::rows(['employee_code', 'name', 'designation', 'department', 'location', 'work_email', 'work_phone', 'manager'], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'name')],
            ['headcount_department', 'People', 'Headcount by department', 'Active employees per department.', 'employees', self::grouped('department', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'bar', 'count', 'desc')],
            ['headcount_location', 'People', 'Headcount by location', 'Active employees per location.', 'employees', self::grouped('location', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'bar', 'count', 'desc')],
            ['headcount_company', 'People', 'Headcount by company', 'Active employees per company (legal entity).', 'employees', self::grouped('company', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'pie')],
            ['headcount_designation', 'People', 'Headcount by designation', 'Active employees per designation.', 'employees', self::grouped('designation', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'table', 'count', 'desc')],
            ['headcount_grade', 'People', 'Headcount by grade', 'Active employees per grade.', 'employees', self::grouped('grade', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'bar')],
            ['headcount_employment_type', 'People', 'Headcount by employment type', 'Permanent, contract, intern and other employment types.', 'employees', self::grouped('employment_type', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'pie')],
            ['headcount_cost_centre', 'People', 'Headcount by cost centre', 'Active employees per cost centre.', 'employees', self::grouped('cost_centre', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'table', 'count', 'desc')],
            ['gender_diversity', 'People', 'Gender diversity', 'Active employees by gender.', 'employees', self::grouped('gender', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'pie')],
            ['age_profile', 'People', 'Age profile', 'Active employees by age band.', 'employees', self::grouped('age_band', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'bar', 'age_band')],
            ['tenure_profile', 'People', 'Tenure profile', 'Active employees by length of service.', 'employees', self::grouped('tenure_band', [['fn' => 'count']], [self::when('lifecycle_state', 'in', self::ACTIVE)], 'bar')],
            ['new_joiners_this_month', 'People', 'New joiners this month', 'Employees whose joining date falls in the current month.', 'employees',
                self::rows(['employee_code', 'name', 'joining_date', 'department', 'designation', 'location', 'employment_type', 'manager'], [self::when('joining_date', 'this_month')], 'joining_date')],
            ['new_joiners_last_month', 'People', 'New joiners last month', 'Employees who joined in the previous calendar month.', 'employees',
                self::rows(['employee_code', 'name', 'joining_date', 'department', 'designation', 'location', 'employment_type', 'manager'], [self::when('joining_date', 'last_month')], 'joining_date')],
            ['joiners_by_month', 'People', 'Joiners by month (this financial year)', 'Number of joiners in each month of the financial year.', 'employees', self::grouped('joining_month', [['fn' => 'count']], [self::when('joining_date', 'this_financial_year')], 'line', 'joining_month')],
            ['upcoming_joiners', 'People', 'Upcoming joiners (next 30 days)', 'Offers accepted and pre-boarding employees expected to join soon.', 'employees',
                self::rows(['employee_code', 'name', 'expected_joining_date', 'department', 'designation', 'location', 'manager', 'lifecycle_state'], [self::when('expected_joining_date', 'next_days', '30'), self::when('lifecycle_state', 'in', 'pre_employee,preboarding,onboarding')], 'expected_joining_date')],
            ['probation_due', 'People', 'Probation ending in the next 30 days', 'Employees on probation whose probation ends soon; plan the confirmation review.', 'employees',
                self::rows(['employee_code', 'name', 'joining_date', 'probation_end_date', 'department', 'designation', 'manager'], [self::when('lifecycle_state', 'equals', 'probation'), self::when('probation_end_date', 'next_days', '30')], 'probation_end_date')],
            ['probation_overdue', 'People', 'Probation ended, not yet confirmed', 'Employees still on probation after their probation end date.', 'employees',
                self::rows(['employee_code', 'name', 'joining_date', 'probation_end_date', 'department', 'designation', 'manager'], [self::when('lifecycle_state', 'equals', 'probation'), self::when('probation_end_date', 'lt', 'today')], 'probation_end_date')],
            ['confirmations_this_month', 'People', 'Confirmations this month', 'Employees confirmed in the current month.', 'employees',
                self::rows(['employee_code', 'name', 'joining_date', 'confirmation_date', 'department', 'designation', 'manager'], [self::when('confirmation_date', 'this_month')], 'confirmation_date')],
            ['birthdays_this_month', 'People', 'Birthdays this month', 'Active employees with a birthday in the current month.', 'employees',
                self::rows(['employee_code', 'name', 'birthday', 'department', 'designation', 'location'], [self::when('lifecycle_state', 'in', self::ACTIVE), self::when('birthday_this_year', 'this_month')], 'birthday_this_year')],
            ['upcoming_birthdays', 'People', 'Upcoming birthdays (next 30 days)', 'Active employees with a birthday in the next 30 days.', 'employees',
                self::rows(['employee_code', 'name', 'next_birthday', 'department', 'location'], [self::when('lifecycle_state', 'in', self::ACTIVE), self::when('next_birthday', 'next_days', '30')], 'next_birthday')],
            ['work_anniversaries_this_month', 'People', 'Work anniversaries this month', 'Active employees completing a year of service this month.', 'employees',
                self::rows(['employee_code', 'name', 'joining_date', 'anniversary_years', 'department', 'designation', 'location'], [self::when('lifecycle_state', 'in', self::ACTIVE), self::when('work_anniversary_this_year', 'this_month')], 'work_anniversary_this_year')],
            ['upcoming_work_anniversaries', 'People', 'Upcoming work anniversaries (next 30 days)', 'Active employees reaching a service milestone soon.', 'employees',
                self::rows(['employee_code', 'name', 'joining_date', 'next_work_anniversary', 'completing_years', 'department', 'location'], [self::when('lifecycle_state', 'in', self::ACTIVE), self::when('next_work_anniversary', 'next_days', '30')], 'next_work_anniversary')],
            ['employees_without_manager', 'People', 'Employees without a reporting manager', 'Active employees with no current manager.', 'employees',
                self::rows(['employee_code', 'name', 'department', 'designation', 'location', 'joining_date'], [self::when('lifecycle_state', 'in', self::ACTIVE), self::when('manager', 'is_empty')], 'employee_code')],
            ['employees_without_login', 'People', 'Employees without a login', 'Active employees who cannot sign in to self-service yet.', 'employees',
                self::rows(['employee_code', 'name', 'work_email', 'department', 'location', 'joining_date'], [self::when('lifecycle_state', 'in', self::ACTIVE), self::when('has_login', 'equals', '0')], 'employee_code')],
            ['span_of_control', 'People', 'Span of control', 'Number of active direct reports per manager.', 'employees', self::grouped('manager', [['fn' => 'count', 'label' => 'Direct reports']], [self::when('lifecycle_state', 'in', self::ACTIVE), self::when('manager', 'not_empty')], 'table', 'count', 'desc')],
            ['exited_this_fy', 'People', 'Employees who left this financial year', 'Exit dates in the current financial year.', 'employees',
                self::rows(['employee_code', 'name', 'joining_date', 'exit_date', 'years_of_service', 'department', 'designation', 'location'], [self::when('exit_date', 'this_financial_year')], 'exit_date')],
            ['transfers', 'People', 'Transfers (this financial year)', 'Moves between locations or departments with the previous and new placement.', 'employee_movements',
                self::rows(['employee_code', 'name', 'effective_from', 'previous_location', 'location', 'previous_department', 'department', 'reason'], [self::when('change_type', 'equals', 'transfer'), self::when('effective_from', 'this_financial_year')], 'effective_from', 'desc')],
            ['promotions', 'People', 'Promotions (this financial year)', 'Promotions with the previous and new designation and grade.', 'employee_movements',
                self::rows(['employee_code', 'name', 'effective_from', 'previous_designation', 'designation', 'previous_grade', 'grade', 'department'], [self::when('change_type', 'equals', 'promotion'), self::when('effective_from', 'this_financial_year')], 'effective_from', 'desc')],
            ['position_history', 'People', 'Position change history', 'Every hire, transfer, promotion, demotion and reassignment.', 'employee_movements',
                self::rows(['employee_code', 'name', 'change_type', 'effective_from', 'effective_to', 'department', 'designation', 'location', 'grade', 'reason'], [], 'effective_from', 'desc')],
            ['manager_changes', 'People', 'Reporting manager changes (this financial year)', 'Manager assignments that started this financial year.', 'manager_changes',
                self::rows(['employee_code', 'name', 'department', 'manager_code', 'manager', 'type', 'effective_from', 'reason'], [self::when('effective_from', 'this_financial_year')], 'effective_from', 'desc')],
            ['lifecycle_changes', 'People', 'Lifecycle changes this month', 'Confirmations, notice, exits and other state changes this month.', 'lifecycle_transitions',
                self::rows(['employee_code', 'name', 'department', 'from_state', 'to_state', 'effective_date', 'reason', 'changed_by'], [self::when('effective_date', 'this_month')], 'effective_date', 'desc')],
            ['statutory_bank_register', 'People', 'Statutory IDs and bank details', 'PAN, UAN, PF, ESI and primary bank account per active employee (masked).', 'statutory_bank',
                self::rows([...$who, 'pan', 'uan', 'pf_number', 'esic_number', 'has_aadhaar', 'tax_regime', 'bank_name', 'ifsc', 'account_number', 'bank_verified'], [$active], 'employee_code')],
            ['missing_pan', 'People', 'Missing PAN', 'Active employees with no PAN on file (TDS at a higher rate applies).', 'statutory_bank', self::rows([...$who, 'joining_date'], [$active, self::when('has_pan', 'equals', '0')], 'employee_code')],
            ['missing_uan', 'People', 'Missing UAN (PF applicable)', 'Active PF members without a UAN.', 'statutory_bank', self::rows([...$who, 'joining_date'], [$active, self::when('pf_applicable', 'equals', '1'), self::when('has_uan', 'equals', '0')], 'employee_code')],
            ['missing_esic', 'People', 'Missing ESI IP number (ESI applicable)', 'Active ESI-covered employees without an IP number.', 'statutory_bank', self::rows([...$who, 'joining_date'], [$active, self::when('esi_applicable', 'equals', '1'), self::when('has_esic', 'equals', '0')], 'employee_code')],
            ['missing_bank', 'People', 'Missing bank account', 'Active employees with no bank account; salary cannot be credited.', 'statutory_bank', self::rows([...$who, 'joining_date'], [$active, self::when('has_bank', 'equals', '0')], 'employee_code')],
            ['bank_unverified', 'People', 'Bank accounts not verified', 'Active employees whose bank account is not yet verified.', 'statutory_bank', self::rows([...$who, 'bank_name', 'ifsc', 'account_number'], [$active, self::when('has_bank', 'equals', '1'), self::when('bank_verified', 'equals', '0')], 'employee_code')],
            ['nominees', 'People', 'Nominees', 'Nominees and their share per employee (PF, gratuity, insurance).', 'family_members',
                self::rows(['employee_code', 'name', 'department', 'member', 'relation', 'date_of_birth', 'nominee_share'], [$active, self::when('is_nominee', 'equals', '1')], 'employee_code')],
            ['dependants', 'People', 'Dependants (insurance)', 'Dependants per employee for group health insurance.', 'family_members',
                self::rows(['employee_code', 'name', 'member', 'relation', 'gender', 'date_of_birth', 'age'], [$active, self::when('is_dependent', 'equals', '1')], 'employee_code')],
            ['emergency_contacts', 'People', 'Emergency contacts', 'Emergency contacts of active employees.', 'emergency_contacts',
                self::rows(['employee_code', 'name', 'department', 'location', 'contact', 'relation', 'phone', 'alternate_phone'], [$active], 'employee_code')],
            ['qualifications', 'People', 'Education and qualifications', 'Qualifications of active employees.', 'qualifications',
                self::rows(['employee_code', 'name', 'department', 'qualification', 'specialisation', 'institution', 'year_of_completion', 'is_verified'], [$active], 'employee_code')],
            ['documents_expiring', 'People', 'Documents expiring in the next 60 days', 'Passports, visas, licences and other documents about to expire.', 'employee_documents',
                self::rows(['employee_code', 'name', 'department', 'document_type', 'title', 'expires_on', 'days_to_expiry'], [self::when('expires_on', 'next_days', '60'), self::when('status', 'not_equals', 'archived')], 'expires_on')],
            ['documents_expired', 'People', 'Expired documents', 'Current documents whose expiry date has passed.', 'employee_documents',
                self::rows(['employee_code', 'name', 'department', 'document_type', 'title', 'expires_on'], [self::when('is_expired', 'equals', '1'), self::when('status', 'not_equals', 'archived'), $active], 'expires_on')],
            ['documents_pending_verification', 'People', 'Documents pending verification', 'Uploaded documents waiting for HR verification.', 'employee_documents',
                self::rows(['employee_code', 'name', 'document_type', 'title', 'uploaded_on'], [self::when('status', 'equals', 'pending')], 'uploaded_on')],
            // HROne setup parity CA-4: mandatory document types not on file (pending or verified).
            ['documents_missing', 'People', 'Missing documents', 'Active employees without a document every employee must have.', 'missing_documents',
                self::rows(['employee_code', 'name', 'department', 'location', 'document_type', 'document_category'], [self::when('employee_status', 'in', self::ACTIVE), self::when('required_of_everyone', 'equals', '1')], 'employee_code')],
            ['documents_missing_joiners', 'People', 'Missing joining documents', 'Upcoming joiners without a document needed before joining.', 'missing_documents',
                self::rows(['employee_code', 'name', 'expected_joining_date', 'department', 'document_type'], [self::when('employee_status', 'in', 'pre_employee,preboarding,onboarding'), self::when('required_for_onboarding', 'equals', '1')], 'expected_joining_date')],
            ['letters_issued_this_month', 'People', 'Letters issued this month', 'Offer, appointment, confirmation, relieving and other letters issued this month.', 'letters',
                self::rows(['number', 'employee_code', 'name', 'type', 'subject', 'issued_on', 'approved_by'], [self::when('issued_on', 'this_month')], 'issued_on', 'desc')],
            ['letters_pending', 'People', 'Letters awaiting approval or issue', 'Letters in draft or pending approval.', 'letters',
                self::rows(['number', 'employee_code', 'name', 'type', 'subject', 'status', 'requested_on', 'requested_by'], [self::when('status', 'in', 'draft,pending_approval,approved')], 'requested_on')],
            // HROne setup parity HP-9c: the badges ledger and who was recognised most.
            ['badges_ledger_this_month', 'People', 'Badges ledger (this month)', 'Every badge given this month: badge, receiver, giver, reason and visibility.', 'badges_ledger',
                self::rows(['given_on', 'badge', 'employee_code', 'name', 'department', 'given_by', 'reason', 'visibility', 'status'], [self::when('given_on', 'this_month')], 'given_on', 'desc')],
            ['badges_by_employee', 'People', 'Badges received per employee (this financial year)', 'Badges received by each employee this financial year (withdrawn ones left out).', 'badges_ledger',
                self::grouped('employee', [['fn' => 'count']], [self::when('given_on', 'this_financial_year'), self::when('status', 'equals', 'given')], 'table', 'count', 'desc')],
            // HROne setup parity HP-9d / HP-9f: reward points and the happiness index.
            ['reward_points_this_month', 'People', 'Reward points ledger (this month)', 'Every reward points movement this month: deposits, credits, awards, badge points, redemptions, lapses and expiry.', 'reward_points_ledger',
                self::rows(['date', 'wallet', 'kind', 'points', 'employee_code', 'name', 'colleague', 'amount', 'paid_in_payroll'], [self::when('date', 'this_month')], 'date', 'desc')],
            ['reward_points_redeemed', 'People', 'Reward points redeemed (this financial year)', 'Points redeemed through payroll this financial year, with the amount and whether payroll has paid it.', 'reward_points_ledger',
                self::rows(['date', 'employee_code', 'name', 'department', 'points', 'amount', 'currency', 'paid_in_payroll'], [self::when('date', 'this_financial_year'), self::when('kind_code', 'equals', 'redeem')], 'date', 'desc')],
            ['happiness_index_trend', 'People', 'Happiness index by period', 'Average mood and the share of happy check-ins per period and group (small groups suppressed).', 'happiness_index',
                self::rows(['period', 'period_end', 'group', 'respondents', 'average_mood', 'happy_share', 'unhappy_share'], [], 'period_end', 'desc')],

            // Attendance --------------------------------------------------------------------------------
            ['attendance_today', 'Attendance', 'Daily attendance (today)', 'Status, shift, in/out time and hours for every employee today.', 'attendance',
                self::rows(['employee_code', 'name', 'department', 'shift', 'first_in', 'last_out', 'worked_hours', 'late_minutes', 'status'], [self::when('date', 'today')], 'employee_code')],
            ['attendance_yesterday', 'Attendance', 'Daily attendance (yesterday)', 'Yesterday\'s processed attendance.', 'attendance',
                self::rows(['employee_code', 'name', 'department', 'shift', 'first_in', 'last_out', 'worked_hours', 'late_minutes', 'early_leave_minutes', 'status', 'exceptions'], [self::when('date', 'yesterday')], 'employee_code')],
            ['absentees_today', 'Attendance', 'Absentees today', 'Employees marked absent today.', 'attendance',
                self::rows(['employee_code', 'name', 'department', 'designation', 'location', 'shift'], [self::when('date', 'today'), self::when('status', 'equals', 'absent')], 'employee_code')],
            ['attendance_status_today', 'Attendance', 'Attendance status split (today)', 'Present, absent, leave, WFH and others today.', 'attendance', self::grouped('status', [['fn' => 'count']], [self::when('date', 'today')], 'pie')],
            ['monthly_summary_this_month', 'Attendance', 'Monthly attendance summary (this month)', 'Present, absent, leave, week-off, holiday, late and hours per employee.', 'attendance_monthly',
                self::rows(['employee_code', 'name', 'department', 'present_days', 'half_days', 'absent_days', 'leave_days', 'unpaid_leave_days', 'weekly_offs', 'holidays', 'wfh_days', 'on_duty_days', 'missed_punch_days', 'late_count', 'worked_hours', 'overtime_hours'], [self::when('month_start', 'this_month')], 'employee_code')],
            ['monthly_summary_last_month', 'Attendance', 'Monthly attendance summary (last month)', 'Last month\'s attendance per employee, for payroll inputs.', 'attendance_monthly',
                self::rows(['employee_code', 'name', 'department', 'days_recorded', 'days_worked', 'present_days', 'half_days', 'absent_days', 'leave_days', 'unpaid_leave_days', 'weekly_offs', 'holidays', 'wfh_days', 'on_duty_days', 'missed_punch_days', 'late_count', 'worked_hours', 'overtime_hours'], [self::when('month_start', 'last_month')], 'employee_code')],
            ['late_coming_this_month', 'Attendance', 'Late coming (this month)', 'Every late arrival this month with shift start and first punch.', 'attendance',
                self::rows(['date', 'employee_code', 'name', 'department', 'shift', 'scheduled_start', 'first_in', 'late_minutes'], [self::when('date', 'this_month'), self::when('is_late', 'equals', '1')], 'date', 'desc')],
            ['late_summary_this_month', 'Attendance', 'Late coming summary by employee (this month)', 'Late arrivals and minutes per employee this month.', 'attendance_monthly',
                self::rows(['employee_code', 'name', 'department', 'late_count', 'late_minutes', 'early_leave_count', 'early_leave_minutes'], [self::when('month_start', 'this_month'), self::when('late_count', 'gt', '0')], 'late_count', 'desc')],
            ['early_leaving_this_month', 'Attendance', 'Early leaving (this month)', 'Departures before shift end this month.', 'attendance',
                self::rows(['date', 'employee_code', 'name', 'department', 'shift', 'scheduled_end', 'last_out', 'early_leave_minutes'], [self::when('date', 'this_month'), self::when('is_early_leave', 'equals', '1')], 'date', 'desc')],
            ['missed_punches_this_month', 'Attendance', 'Missed punches (this month)', 'Days with a missing IN or OUT punch, to chase regularisation.', 'attendance',
                self::rows(['date', 'employee_code', 'name', 'department', 'first_in', 'last_out', 'status', 'exceptions', 'is_regularised'], [self::when('date', 'this_month'), self::when('missed_punch', 'equals', '1')], 'date', 'desc')],
            ['overtime_this_month', 'Attendance', 'Overtime register (this month)', 'Overtime worked and approved per day.', 'attendance',
                self::rows(['date', 'employee_code', 'name', 'department', 'shift', 'worked_hours', 'overtime_claimed_hours', 'overtime_hours', 'overtime_status'], [self::when('date', 'this_month'), self::when('overtime_claimed_hours', 'gt', '0')], 'date', 'desc')],
            ['overtime_by_department', 'Attendance', 'Overtime hours by department (this month)', 'Approved overtime hours per department.', 'attendance', self::grouped('department', [['fn' => 'sum', 'field' => 'overtime_hours', 'label' => 'Overtime hours']], [self::when('date', 'this_month'), self::when('overtime_hours', 'gt', '0')], 'bar')],
            ['work_hours_this_month', 'Attendance', 'Work hours (this month)', 'Days worked and hours per employee this month.', 'attendance_monthly',
                self::rows(['employee_code', 'name', 'department', 'days_worked', 'worked_hours', 'overtime_hours'], [self::when('month_start', 'this_month')], 'worked_hours', 'desc')],
            ['wfh_on_duty_this_month', 'Attendance', 'Work from home and on-duty days (this month)', 'WFH, on-duty and field-duty days this month.', 'attendance',
                self::rows(['date', 'employee_code', 'name', 'department', 'status', 'note'], [self::when('date', 'this_month'), self::when('status', 'in', 'wfh,on_duty,field_duty')], 'date', 'desc')],
            ['absence_by_department', 'Attendance', 'Absence by department (last 30 days)', 'Absent days against recorded days per department.', 'attendance', self::grouped('department', [['fn' => 'sum', 'field' => 'is_absent', 'label' => 'Absent days'], ['fn' => 'count', 'label' => 'Days recorded']], [self::when('date', 'last_days', '30')], 'bar')],
            ['regularisations_pending', 'Attendance', 'Regularisation requests pending', 'Open missed-punch, WFH and on-duty requests, oldest first.', 'attendance_regularisations',
                self::rows(['date', 'employee_code', 'name', 'department', 'type', 'requested_in', 'requested_out', 'reason', 'applied_on', 'pending_days'], [self::when('status', 'equals', 'pending')], 'pending_days', 'desc')],
            ['regularisations_this_month', 'Attendance', 'Regularisations (this month)', 'All regularisation requests for this month with the decision.', 'attendance_regularisations',
                self::rows(['date', 'employee_code', 'name', 'type', 'reason', 'status', 'reviewed_by', 'reviewed_on'], [self::when('date', 'this_month')], 'date', 'desc')],
            ['punch_log_today', 'Attendance', 'Punch log (today)', 'Every punch received today with its source and device.', 'attendance_punches',
                self::rows(['employee_code', 'name', 'time', 'direction', 'source', 'device', 'geo_tagged', 'processing_status'], [self::when('date', 'today')], 'time')],
            ['punch_failures', 'Attendance', 'Punches that failed processing', 'Punches that could not be matched or processed, with the error.', 'attendance_punches',
                self::rows(['date', 'time', 'employee_code', 'name', 'source', 'device', 'processing_error'], [self::when('processing_status', 'equals', 'failed')], 'date', 'desc')],
            ['shift_roster', 'Attendance', 'Current work schedule (roster)', 'The work schedule each employee is on today.', 'shift_roster',
                self::rows(['employee_code', 'name', 'department', 'location', 'schedule', 'effective_from', 'effective_to'], [self::when('is_current', 'equals', '1')], 'schedule')],
            ['holiday_list', 'Attendance', 'Holiday list (this year)', 'Holidays of every calendar in the current year.', 'holidays',
                self::rows(['calendar', 'date', 'day', 'name', 'type', 'is_half_day'], [self::when('date', 'this_year')], 'date')],

            // Leave -------------------------------------------------------------------------------------
            ['leave_balance', 'Leave', 'Leave balance (current leave year)', 'Opening, credited, availed, lapsed, closing and available balance per employee and leave type.', 'leave_balances',
                self::rows(['employee_code', 'name', 'department', 'leave_type', 'opening', 'accrued', 'used', 'encashed', 'lapsed', 'closing', 'pending', 'available'], [self::when('is_current_year', 'equals', '1'), $active], 'employee_code')],
            ['leave_balance_by_type', 'Leave', 'Leave balance summary by leave type', 'Totals per leave type for the current leave year.', 'leave_balances', self::grouped('leave_type', [['fn' => 'sum', 'field' => 'accrued', 'label' => 'Credited'], ['fn' => 'sum', 'field' => 'used', 'label' => 'Availed'], ['fn' => 'sum', 'field' => 'closing', 'label' => 'Closing'], ['fn' => 'count', 'label' => 'Employees']], [self::when('is_current_year', 'equals', '1'), $active], 'table')],
            ['leave_balance_by_department', 'Leave', 'Leave balance by department', 'Available leave per department for the current leave year (leave liability).', 'leave_balances', self::grouped('department', [['fn' => 'sum', 'field' => 'available', 'label' => 'Available days'], ['fn' => 'sum', 'field' => 'used', 'label' => 'Availed days']], [self::when('is_current_year', 'equals', '1'), $active], 'bar')],
            ['negative_leave_balance', 'Leave', 'Negative leave balances', 'Employees who have used more leave than they have.', 'leave_balances',
                self::rows(['employee_code', 'name', 'department', 'leave_type', 'closing', 'pending', 'available'], [self::when('is_current_year', 'equals', '1'), self::when('available', 'lt', '0')], 'available')],
            ['high_leave_balance', 'Leave', 'High leave balances (30 days or more)', 'Employees carrying a large balance; candidates for encashment or planned leave.', 'leave_balances',
                self::rows(['employee_code', 'name', 'department', 'leave_type', 'closing', 'available'], [self::when('is_current_year', 'equals', '1'), self::when('available', 'gte', '30'), $active], 'available', 'desc')],
            ['leave_ledger', 'Leave', 'Leave ledger (current leave year)', 'Every credit, debit, lapse and encashment posted this leave year.', 'leave_ledger',
                self::rows(['entry_date', 'employee_code', 'name', 'leave_type', 'type_label', 'credit', 'debit', 'note', 'posted_by'], [self::when('is_current_year', 'equals', '1')], 'entry_date', 'desc')],
            ['leave_availed_this_year', 'Leave', 'Leave availed (this year)', 'Approved leave taken this calendar year.', 'leave',
                self::rows(['employee_code', 'name', 'department', 'leave_type', 'from_date', 'to_date', 'days', 'reason'], [self::when('status', 'equals', 'approved'), self::when('from_date', 'this_year')], 'from_date', 'desc')],
            ['leave_availed_by_employee', 'Leave', 'Leave availed by employee (this year)', 'Total approved leave days per employee this year.', 'leave', self::grouped('employee', [['fn' => 'sum', 'field' => 'days', 'label' => 'Days'], ['fn' => 'count', 'label' => 'Requests']], [self::when('status', 'equals', 'approved'), self::when('from_date', 'this_year')], 'table', 'sum_days', 'desc')],
            ['leave_availed_by_type', 'Leave', 'Leave availed by leave type (this year)', 'Approved leave days per leave type this year.', 'leave', self::grouped('leave_type', [['fn' => 'sum', 'field' => 'days', 'label' => 'Days']], [self::when('status', 'equals', 'approved'), self::when('from_date', 'this_year')], 'bar')],
            ['leave_availed_by_department', 'Leave', 'Leave availed by department (this year)', 'Approved leave days per department this year.', 'leave', self::grouped('department', [['fn' => 'sum', 'field' => 'days', 'label' => 'Days']], [self::when('status', 'equals', 'approved'), self::when('from_date', 'this_year')], 'bar')],
            ['leave_this_month', 'Leave', 'Leave this month', 'Approved and pending leave starting this month.', 'leave',
                self::rows(['employee_code', 'name', 'department', 'leave_type', 'from_date', 'to_date', 'days', 'status'], [self::when('from_date', 'this_month'), self::when('status', 'in', 'approved,pending')], 'from_date')],
            ['on_leave_today', 'Leave', 'Who is on leave today', 'Employees on approved leave today.', 'leave',
                self::rows(['employee_code', 'name', 'department', 'designation', 'leave_type', 'from_date', 'to_date', 'days'], [self::when('on_leave_today', 'equals', '1')], 'name')],
            ['pending_leave_approvals', 'Leave', 'Pending leave approvals', 'Leave requests waiting for a decision, oldest first.', 'leave',
                self::rows(['employee_code', 'name', 'department', 'leave_type', 'from_date', 'to_date', 'days', 'applied_on', 'pending_days'], [self::when('status', 'equals', 'pending')], 'pending_days', 'desc')],
            ['lop_leave_this_month', 'Leave', 'Leave without pay (this month)', 'Approved unpaid leave this month (loss of pay).', 'leave',
                self::rows(['employee_code', 'name', 'department', 'leave_type', 'from_date', 'to_date', 'days'], [self::when('is_paid', 'equals', '0'), self::when('status', 'equals', 'approved'), self::when('from_date', 'this_month')], 'employee_code')],
            ['backdated_leave', 'Leave', 'Back-dated leave applications', 'Leave applied after it had started (this year).', 'leave',
                self::rows(['employee_code', 'name', 'leave_type', 'from_date', 'to_date', 'days', 'applied_on', 'status'], [self::when('is_backdated', 'equals', '1'), self::when('from_date', 'this_year')], 'from_date', 'desc')],
            ['rejected_cancelled_leave', 'Leave', 'Rejected and cancelled leave (this year)', 'Leave that was rejected or cancelled this year.', 'leave',
                self::rows(['employee_code', 'name', 'leave_type', 'from_date', 'days', 'status', 'reviewed_by', 'review_note'], [self::when('status', 'in', 'rejected,cancelled'), self::when('from_date', 'this_year')], 'from_date', 'desc')],
            ['comp_off_credits', 'Leave', 'Comp-off credits', 'Compensatory off credited this leave year.', 'leave_ledger',
                self::rows(['entry_date', 'employee_code', 'name', 'leave_type', 'credit', 'note'], [self::when('type', 'equals', 'comp_off_credit'), self::when('is_current_year', 'equals', '1')], 'entry_date', 'desc')],
            ['lapsed_leave', 'Leave', 'Lapsed and expired leave', 'Leave lapsed at year end or expired.', 'leave_ledger',
                self::rows(['entry_date', 'year', 'employee_code', 'name', 'leave_type', 'debit', 'note'], [self::when('type', 'in', 'lapse,expiry')], 'entry_date', 'desc')],
            ['leave_encashments', 'Leave', 'Leave encashment register', 'Encashment requests with days and decision.', 'leave_encashments',
                self::rows(['year', 'employee_code', 'name', 'department', 'leave_type', 'days', 'status', 'requested_on', 'reviewed_by', 'reviewed_on'], [], 'requested_on', 'desc')],
            // HROne parity HP-1 ------------------------------------------------------------------------------
            ['leave_donations_register', 'Leave', 'Leave donation report', 'Leave given between colleagues, with the decision.', 'leave_donations',
                self::rows(['employee_code', 'name', 'recipient', 'leave_type', 'year', 'days', 'reason', 'status', 'reviewed_by'], [], 'applied_on', 'desc')],
            ['time_office_pending', 'Attendance', 'Time-office requests pending', 'Open short leave, on-duty, shift change, overtime, comp-off and restricted holiday requests, oldest first.', 'time_office_requests',
                self::rows(['from_date', 'employee_code', 'name', 'department', 'type', 'part', 'minutes', 'reason', 'applied_on', 'pending_days'], [self::when('status', 'in', 'Pending,Cancellation requested')], 'pending_days', 'desc')],
            ['attendance_reconciliation', 'Attendance', 'Attendance reconciliation (time-office requests this month)', 'Every time-office request raised this month with its decision.', 'time_office_requests',
                self::rows(['from_date', 'to_date', 'employee_code', 'name', 'type', 'part', 'minutes', 'days', 'status', 'reviewed_by'], [self::when('from_date', 'this_month')], 'from_date', 'desc')],
            ['short_leave_requests', 'Attendance', 'Short leave requests', 'Short leave taken, with times and decision.', 'time_office_requests',
                self::rows(['from_date', 'employee_code', 'name', 'department', 'start_time', 'end_time', 'minutes', 'reason', 'status'], [self::when('type', 'equals', 'Short leave')], 'from_date', 'desc')],
            ['on_duty_requests', 'Attendance', 'On duty requests', 'On-duty requests (full, half day and hours) with the decision.', 'time_office_requests',
                self::rows(['from_date', 'to_date', 'employee_code', 'name', 'part', 'start_time', 'end_time', 'reason', 'status'], [self::when('type', 'equals', 'On duty')], 'from_date', 'desc')],
            ['comp_off_requests', 'Attendance', 'Compensatory off requests', 'Comp-off claims for work on off days, with days and expiry.', 'time_office_requests',
                self::rows(['from_date', 'employee_code', 'name', 'minutes', 'days', 'expires_on', 'status', 'reviewed_by'], [self::when('type', 'equals', 'Comp-off claim')], 'from_date', 'desc')],
            ['overtime_claims', 'Attendance', 'Overtime requests', 'Overtime claimed by employees, with minutes and decision.', 'time_office_requests',
                self::rows(['from_date', 'employee_code', 'name', 'department', 'minutes', 'reason', 'status', 'reviewed_by'], [self::when('type', 'equals', 'Overtime claim')], 'from_date', 'desc')],
            ['restricted_holiday_requests', 'Attendance', 'Restricted holiday requests', 'Optional holidays chosen by employees.', 'time_office_requests',
                self::rows(['from_date', 'employee_code', 'name', 'holiday', 'status', 'reviewed_by'], [self::when('type', 'equals', 'Restricted holiday')], 'from_date', 'desc')],
            ['shift_change_requests', 'Attendance', 'Shift change requests', 'Shift changes asked for, with the shift and decision.', 'time_office_requests',
                self::rows(['from_date', 'to_date', 'employee_code', 'name', 'shift', 'reason', 'status'], [self::when('type', 'equals', 'Shift change')], 'from_date', 'desc')],
            ['late_penalties_month', 'Attendance', 'Attendance penalty detail (this month)', 'Late-mark penalties this month with the late marks counted and the days.', 'attendance_penalties',
                self::rows(['date', 'employee_code', 'name', 'department', 'late_count', 'action', 'days', 'status'], [self::when('date', 'this_month')], 'date', 'desc')],
            ['late_penalty_days_by_department', 'Attendance', 'Late-mark loss-of-pay days by department', 'Loss-of-pay days from late-mark penalties per department.', 'attendance_penalties',
                self::grouped('department', [['fn' => 'sum', 'field' => 'days', 'label' => 'Days'], ['fn' => 'count', 'label' => 'Penalties']], [], 'bar')],
            ['attendance_arrear_days', 'Attendance', 'Arrear days', 'Days corrected after the payroll lock, with the paid-day difference.', 'attendance_arrears',
                self::rows(['date', 'employee_code', 'name', 'status_before', 'status_after', 'delta_days', 'status'], [], 'date', 'desc')],
            ['checkin_devices_register', 'Attendance', 'Check-in devices (UUID details)', 'Phones and browsers employees check in from, and their approval.', 'checkin_devices',
                self::rows(['employee_code', 'name', 'device', 'platform', 'status', 'first_seen', 'last_seen'], [], 'last_seen', 'desc')],

            // Payroll & compensation ------------------------------------------------------------------------
            // HROne parity HP-3: perquisites, income outside payroll, override audit, VPF.
            ['perquisite_register', 'Payroll & compensation', 'Perquisite register', 'Perquisites recorded for TDS this year with their taxable value.', 'tax_income_entries',
                self::rows(['financial_year', 'employee_code', 'name', 'type', 'description', 'amount', 'recovered', 'taxable_amount', 'status'], [self::when('kind', 'equals', 'Perquisite')], 'employee_code')],
            ['income_outside_payroll', 'Payroll & compensation', 'Out-of-salary payments and income outside payroll', 'Payments made outside the payroll run, with the tax, PF and PT deducted on them.', 'tax_income_entries',
                self::rows(['paid_on', 'employee_code', 'name', 'description', 'amount', 'taxable_amount', 'tds_deducted', 'pf_employee', 'professional_tax', 'status'], [self::when('kind', 'equals', 'Paid outside payroll')], 'paid_on', 'desc')],
            ['statutory_override_audit', 'Payroll & compensation', 'Statutory override audit', 'Every PF, ESI, PT, LWF and TDS amount set by hand, by whom and why.', 'statutory_overrides',
                self::rows(['month', 'employee_code', 'name', 'line', 'amount', 'reason', 'set_by', 'set_on', 'status', 'cancel_reason'], [], 'set_on', 'desc')],
            // HROne setup parity BP-13: pay modes.
            ['cash_cheque_register', 'Payroll & compensation', 'Cash and cheque payments register', 'Salaries and reimbursements paid in cash or by cheque, per employee and pay month.', 'salary_payments',
                self::rows(['period', 'payment_date', 'employee_code', 'name', 'department', 'salary_mode', 'reimbursement_mode', 'cash', 'cheque', 'net_pay', 'run_status'], [self::when('cash_cheque_amount', 'gt', '0')], 'period', 'desc')],
            ['salary_by_pay_mode_last_month', 'Payroll & compensation', 'Salary paid by mode (last month)', 'How much of last month\'s salary went by bank transfer, in cash and by cheque.', 'salary_payments',
                self::grouped('salary_mode', [['fn' => 'sum', 'field' => 'net_pay', 'label' => 'Net pay'], ['fn' => 'count', 'label' => 'Employees']], [self::when('period_start', 'last_month')], 'pie')],
            ['vpf_register', 'Payroll & compensation', 'Voluntary PF register', 'Employees contributing voluntary PF, and PF wage-basis choices.', 'pf_options',
                self::rows(['effective_from', 'employee_code', 'name', 'vpf_mode', 'vpf_value', 'wage_basis', 'reason'], [], 'effective_from', 'desc')],
            ['salary_register_last_month', 'Payroll & compensation', 'Salary register (last month)', 'Paid days, earnings, deductions and net pay per employee for last month.', 'payroll',
                self::rows([...self::PAY_LINE, 'paid_days', 'lop_days', 'basic', 'hra', 'gross', 'pf_employee', 'esi_employee', 'professional_tax', 'tds', 'total_deductions', 'net_pay', 'employer_cost'], [self::when('period_start', 'last_month')], 'employee_code')],
            ['salary_register_this_month', 'Payroll & compensation', 'Salary register (this month)', 'This month\'s finalized or paid payroll per employee.', 'payroll',
                self::rows([...self::PAY_LINE, 'paid_days', 'lop_days', 'basic', 'hra', 'gross', 'pf_employee', 'esi_employee', 'professional_tax', 'tds', 'total_deductions', 'net_pay', 'employer_cost'], [self::when('period_start', 'this_month')], 'employee_code')],
            ['component_register_last_month', 'Payroll & compensation', 'Component-wise salary register (last month)', 'Every earning, deduction and employer contribution per employee.', 'payroll_components',
                self::rows(['employee_code', 'name', 'department', 'component_code', 'component', 'component_type', 'amount'], [self::when('period_start', 'last_month')], 'employee_code')],
            ['earnings_by_component', 'Payroll & compensation', 'Earnings by component (last month)', 'Total paid per earning component.', 'payroll_components', self::grouped('component', [['fn' => 'sum', 'field' => 'amount', 'label' => 'Amount'], ['fn' => 'count', 'label' => 'Employees']], [self::when('period_start', 'last_month'), self::when('component_type', 'equals', 'Earning')], 'bar')],
            ['deductions_by_component', 'Payroll & compensation', 'Deductions by component (last month)', 'Total deducted per deduction component.', 'payroll_components', self::grouped('component', [['fn' => 'sum', 'field' => 'amount', 'label' => 'Amount'], ['fn' => 'count', 'label' => 'Employees']], [self::when('period_start', 'last_month'), self::when('component_type', 'equals', 'Deduction')], 'bar')],
            ['payroll_cost_by_period', 'Payroll & compensation', 'Payroll cost by month', 'Gross, net and employer cost per payroll month.', 'payroll', self::grouped('period', [['fn' => 'sum', 'field' => 'gross', 'label' => 'Gross'], ['fn' => 'sum', 'field' => 'net_pay', 'label' => 'Net pay'], ['fn' => 'sum', 'field' => 'employer_cost', 'label' => 'Employer cost'], ['fn' => 'count', 'label' => 'Employees']], [], 'bar', 'period')],
            ['payroll_cost_by_department', 'Payroll & compensation', 'Payroll cost by department (last month)', 'Gross, net and employer cost per department.', 'payroll', self::grouped('department', [['fn' => 'sum', 'field' => 'gross', 'label' => 'Gross'], ['fn' => 'sum', 'field' => 'employer_cost', 'label' => 'Employer cost'], ['fn' => 'count', 'label' => 'Employees']], [self::when('period_start', 'last_month')], 'bar')],
            ['payroll_cost_by_location', 'Payroll & compensation', 'Payroll cost by location (last month)', 'Gross, net and employer cost per location.', 'payroll', self::grouped('location', [['fn' => 'sum', 'field' => 'gross', 'label' => 'Gross'], ['fn' => 'sum', 'field' => 'employer_cost', 'label' => 'Employer cost'], ['fn' => 'count', 'label' => 'Employees']], [self::when('period_start', 'last_month')], 'bar')],
            ['lop_days_last_month', 'Payroll & compensation', 'Loss of pay days (last month)', 'Employees with LOP days in last month\'s payroll.', 'payroll',
                self::rows([...self::PAY_LINE, 'days_in_period', 'paid_days', 'lop_days', 'gross'], [self::when('period_start', 'last_month'), self::when('lop_days', 'gt', '0')], 'lop_days', 'desc')],
            ['bank_transfer_last_month', 'Payroll & compensation', 'Bank transfer check (last month)', 'Net pay with bank and IFSC per employee to reconcile the bank advice (account masked).', 'payroll',
                self::rows(['employee_code', 'name', 'bank_name', 'ifsc', 'account_number', 'net_pay'], [self::when('period_start', 'last_month')], 'bank_name')],
            ['ytd_salary_summary', 'Payroll & compensation', 'Year-to-date salary summary (this financial year)', 'Gross, deductions, net pay and TDS per employee for the financial year so far.', 'payroll', self::grouped('employee', [['fn' => 'sum', 'field' => 'gross', 'label' => 'Gross'], ['fn' => 'sum', 'field' => 'total_deductions', 'label' => 'Deductions'], ['fn' => 'sum', 'field' => 'net_pay', 'label' => 'Net pay'], ['fn' => 'sum', 'field' => 'tds', 'label' => 'TDS'], ['fn' => 'count', 'label' => 'Months']], [self::when('period_start', 'this_financial_year')], 'table')],
            ['adjustments_last_month', 'Payroll & compensation', 'Arrears and one-time pay (last month)', 'Arrears, bonuses, reimbursements, recoveries and corrections in last month\'s payroll.', 'payroll_adjustments',
                self::rows(['employee_code', 'name', 'type_label', 'description', 'amount', 'taxable', 'status'], [self::when('period_start', 'last_month')], 'employee_code')],
            ['arrears_this_fy', 'Payroll & compensation', 'Arrears (this financial year)', 'Arrear payments this financial year.', 'payroll_adjustments',
                self::rows(['period', 'employee_code', 'name', 'description', 'amount', 'status'], [self::when('type', 'equals', 'arrear'), self::when('period_start', 'this_financial_year')], 'period', 'desc')],
            ['reimbursements_this_fy', 'Payroll & compensation', 'Reimbursements paid (this financial year)', 'Reimbursements paid through payroll this financial year.', 'payroll_adjustments',
                self::rows(['period', 'employee_code', 'name', 'description', 'amount', 'status'], [self::when('type', 'equals', 'reimbursement'), self::when('period_start', 'this_financial_year')], 'period', 'desc')],
            ['payslips_not_viewed', 'Payroll & compensation', 'Payslips not yet viewed', 'Published payslips the employee has not opened.', 'payslips',
                self::rows(['period', 'employee_code', 'name', 'department', 'number', 'generated_on'], [self::when('is_viewed', 'equals', '0')], 'period', 'desc')],
            ['ctc_register', 'Payroll & compensation', 'CTC register (current salary)', 'Current annual and monthly CTC, fixed and variable, per employee.', 'ctc_register',
                self::rows(['employee_code', 'name', 'department', 'designation', 'structure', 'ctc_annual', 'ctc_monthly', 'fixed_annual', 'variable_annual', 'effective_from'], [self::when('has_salary', 'equals', '1'), $active], 'employee_code')],
            ['ctc_by_department', 'Payroll & compensation', 'CTC by department', 'Total and average current CTC per department.', 'ctc_register', self::grouped('department', [['fn' => 'sum', 'field' => 'ctc_annual', 'label' => 'Total CTC'], ['fn' => 'avg', 'field' => 'ctc_annual', 'label' => 'Average CTC'], ['fn' => 'count', 'label' => 'Employees']], [self::when('has_salary', 'equals', '1'), $active], 'bar')],
            ['employees_without_salary', 'Payroll & compensation', 'Active employees without an approved salary', 'They cannot be paid until a salary is approved.', 'ctc_register',
                self::rows(['employee_code', 'name', 'department', 'designation', 'location', 'joining_date'], [self::when('has_salary', 'equals', '0'), $active], 'joining_date')],
            ['salary_revisions_this_fy', 'Payroll & compensation', 'Salary revisions (this financial year)', 'Approved increments and promotions with old CTC, new CTC and % hike.', 'salary_revisions',
                self::rows(['employee_code', 'name', 'department', 'change_type', 'effective_from', 'previous_ctc', 'new_ctc', 'increase_amount', 'increase_percent', 'status'], [self::when('status', 'in', 'approved,scheduled,effective'), self::when('effective_from', 'this_financial_year')], 'effective_from', 'desc')],
            ['pending_salary_revisions', 'Payroll & compensation', 'Salary revisions awaiting approval', 'Proposed compensation changes not yet approved.', 'salary_revisions',
                self::rows(['reference', 'employee_code', 'name', 'change_type', 'effective_from', 'previous_ctc', 'new_ctc', 'increase_percent', 'status'], [self::when('is_pending', 'equals', '1')], 'effective_from')],
            ['average_increase_by_department', 'Payroll & compensation', 'Average increase by department (this financial year)', 'Average % hike of approved revisions per department.', 'salary_revisions', self::grouped('department', [['fn' => 'avg', 'field' => 'increase_percent', 'label' => 'Average increase %'], ['fn' => 'count', 'label' => 'Revisions']], [self::when('status', 'in', 'approved,scheduled,effective'), self::when('effective_from', 'this_financial_year')], 'bar')],
            // HROne parity AN-26: loans, reimbursements and variable pay (HROne Payroll reports #32–34, 47, 48, 50, 51, 60–62).
            ['loan_ledger', 'Payroll & compensation', 'Loan ledger (this financial year)', 'Every EMI due this financial year, month by month: principal, interest and how it was recovered.', 'loan_installments',
                self::rows(['due_month', 'loan_number', 'employee_code', 'name', 'department', 'loan_type', 'sequence', 'principal_part', 'interest_part', 'amount', 'status_label', 'recovered_on'], [self::when('due_on', 'this_financial_year')], 'due_month')],
            ['loan_requests', 'Payroll & compensation', 'Loan requests', 'Loans and salary advances asked for, with the terms, any policy exception and the decision.', 'loans',
                self::rows(['number', 'requested_on', 'employee_code', 'name', 'department', 'loan_type', 'principal', 'installments', 'emi', 'beyond_policy', 'status_label', 'reviewed_on', 'reviewed_by'], [], 'requested_on', 'desc')],
            ['loan_summary', 'Payroll & compensation', 'Loan summary (disbursed loans)', 'Disbursed loans with what has been recovered, what is outstanding and the next recovery month.', 'loans',
                self::rows(['number', 'employee_code', 'name', 'loan_type', 'disbursed_on', 'principal', 'emi', 'installments', 'installments_recovered', 'recovered', 'outstanding', 'next_due_month', 'status_label'], [self::when('status', 'in', 'active,closed')], 'disbursed_on', 'desc')],
            ['loans_outstanding', 'Payroll & compensation', 'Loans outstanding (till date)', 'Outstanding amount of loans still being recovered, per loan type.', 'loans',
                self::grouped('loan_type', [['fn' => 'sum', 'field' => 'outstanding', 'label' => 'Outstanding'], ['fn' => 'count', 'label' => 'Loans']], [self::when('status', 'equals', 'active')], 'bar', 'sum_outstanding', 'desc')],
            ['reimbursement_audit', 'Payroll & compensation', 'Reimbursement audit (this financial year)', 'Per employee and reimbursement: credited this year, claimed and lapsed, and the balance.', 'reimbursement_ledger',
                self::grouped('employee_component', [['fn' => 'sum', 'field' => 'credit', 'label' => 'Credited'], ['fn' => 'sum', 'field' => 'debit', 'label' => 'Claimed or lapsed'], ['fn' => 'sum', 'field' => 'amount', 'label' => 'Balance']], [self::when('month_start', 'this_financial_year')], 'table', 'employee_component')],
            ['reimbursement_monthly_ledger', 'Payroll & compensation', 'Reimbursement monthly ledger (last month)', 'Every reimbursement credit and claim paid last month, per employee and component.', 'reimbursement_ledger',
                self::rows(['month', 'employee_code', 'name', 'department', 'component', 'kind_label', 'credit', 'debit', 'note'], [self::when('month_start', 'last_month')], 'employee_code')],
            ['reimbursement_requests', 'Payroll & compensation', 'Reimbursement requests', 'Reimbursement claims with their bills, the amount claimed and approved, and the status.', 'reimbursement_claims',
                self::rows(['claim_date', 'employee_code', 'name', 'department', 'component', 'amount', 'bills', 'bills_total', 'approved_amount', 'status_label', 'reviewed_on'], [], 'claim_date', 'desc')],
            ['reimbursement_summary', 'Payroll & compensation', 'Reimbursement summary by component (this financial year)', 'Credited, claimed or lapsed and balance per reimbursement component.', 'reimbursement_ledger',
                self::grouped('component', [['fn' => 'sum', 'field' => 'credit', 'label' => 'Credited'], ['fn' => 'sum', 'field' => 'debit', 'label' => 'Claimed or lapsed'], ['fn' => 'sum', 'field' => 'amount', 'label' => 'Balance']], [self::when('month_start', 'this_financial_year')], 'bar')],
            ['variable_paid', 'Payroll & compensation', 'Variable pay paid (this financial year)', 'Incentives, bonuses and plan payouts paid by payroll this financial year, by pay month.', 'variable_pay_requests',
                self::rows(['pay_month', 'employee_code', 'name', 'department', 'component', 'plan', 'plan_month', 'amount'], [self::when('status', 'equals', 'paid'), self::when('pay_month_start', 'this_financial_year')], 'pay_month', 'desc')],
            ['variable_pay_plan', 'Payroll & compensation', 'Variable pay plans (month-wise schedule)', 'Active variable pay plans with each month and its target, the payouts entered and paid, and the next month due.', 'variable_pay_plans',
                self::rows(['employee_code', 'name', 'plan', 'component', 'schedule', 'total_target', 'payouts_entered', 'paid_total', 'months_without_payout', 'next_month'], [self::when('status', 'equals', 'active')], 'employee_code')],
            ['variable_requests', 'Payroll & compensation', 'Variable pay requests', 'Variable pay asked for each pay month, against the plan target where there is one, with its status.', 'variable_pay_requests',
                self::rows(['pay_month', 'employee_code', 'name', 'department', 'component', 'plan', 'plan_month', 'plan_target', 'amount', 'status_label', 'requested_on', 'reviewed_on'], [], 'pay_month', 'desc')],

            // Statutory & income tax ------------------------------------------------------------------------
            ['pf_register_last_month', 'Statutory & income tax', 'PF contribution register (last month)', 'UAN, wages and employee / employer PF per member.', 'payroll',
                self::rows(['employee_code', 'name', 'uan', 'paid_days', 'gross', 'basic', 'pf_employee', 'pf_employer'], [self::when('period_start', 'last_month'), self::when('pf_employee', 'gt', '0')], 'employee_code')],
            ['esi_register_last_month', 'Statutory & income tax', 'ESI contribution register (last month)', 'IP number, days, wages and employee / employer ESI.', 'payroll',
                self::rows(['employee_code', 'name', 'esic_number', 'paid_days', 'gross', 'esi_employee', 'esi_employer'], [self::when('period_start', 'last_month'), self::when('esi_employee', 'gt', '0')], 'employee_code')],
            ['pt_register_last_month', 'Statutory & income tax', 'Professional tax register (last month)', 'Gross and PT deducted per employee and location.', 'payroll',
                self::rows(['employee_code', 'name', 'location', 'gross', 'professional_tax'], [self::when('period_start', 'last_month'), self::when('professional_tax', 'gt', '0')], 'location')],
            ['lwf_register_this_fy', 'Statutory & income tax', 'Labour welfare fund deductions (this financial year)', 'Employee and employer LWF per payroll month.', 'payroll',
                self::rows(['period', 'employee_code', 'name', 'location', 'lwf_employee', 'lwf_employer'], [self::when('period_start', 'this_financial_year'), self::when('lwf_employee', 'gt', '0')], 'period')],
            ['statutory_summary_by_month', 'Statutory & income tax', 'Statutory contributions by month', 'PF, ESI, PT, LWF and TDS totals per payroll month.', 'payroll', self::grouped('period', [['fn' => 'sum', 'field' => 'pf_employee', 'label' => 'PF (EE)'], ['fn' => 'sum', 'field' => 'pf_employer', 'label' => 'PF (ER)'], ['fn' => 'sum', 'field' => 'esi_employee', 'label' => 'ESI (EE)'], ['fn' => 'sum', 'field' => 'esi_employer', 'label' => 'ESI (ER)'], ['fn' => 'sum', 'field' => 'professional_tax', 'label' => 'PT'], ['fn' => 'sum', 'field' => 'lwf_employee', 'label' => 'LWF (EE)'], ['fn' => 'sum', 'field' => 'tds', 'label' => 'TDS']], [self::when('period_start', 'this_financial_year')], 'table', 'period')],
            ['tds_register_this_fy', 'Statutory & income tax', 'TDS deducted (this financial year)', 'Taxable earnings and TDS per employee per month.', 'payroll',
                self::rows(['period', 'employee_code', 'name', 'taxable_earnings', 'tds'], [self::when('period_start', 'this_financial_year'), self::when('tds', 'gt', '0')], 'period')],
            ['tds_by_employee', 'Statutory & income tax', 'TDS by employee (year to date)', 'Taxable earnings and TDS per employee for the financial year so far.', 'payroll', self::grouped('employee', [['fn' => 'sum', 'field' => 'taxable_earnings', 'label' => 'Taxable earnings'], ['fn' => 'sum', 'field' => 'tds', 'label' => 'TDS']], [self::when('period_start', 'this_financial_year')], 'table', 'sum_tds', 'desc')],
            ['investment_declarations', 'Statutory & income tax', 'Investment declaration status', 'Regime, status, declared and proved totals per employee and financial year.', 'tax_declarations',
                self::rows(['financial_year', 'employee_code', 'name', 'department', 'regime', 'status', 'declared_total', 'proof_total', 'proofs_pending', 'verified_on'], [], 'employee_code')],
            ['declaration_status_summary', 'Statutory & income tax', 'Declarations by status', 'How many declarations are draft, submitted and verified.', 'tax_declarations', self::grouped('status', [['fn' => 'count']], [], 'pie')],
            ['tax_regime_split', 'Statutory & income tax', 'Old vs new tax regime', 'Employees per tax regime as declared.', 'tax_declarations', self::grouped('regime', [['fn' => 'count']], [], 'pie')],
            ['proofs_pending', 'Statutory & income tax', 'Investment proofs pending verification', 'Proofs submitted and not yet verified.', 'tax_investments',
                self::rows(['financial_year', 'employee_code', 'name', 'section', 'item', 'declared_amount', 'proof_amount', 'has_document'], [self::when('proof_status', 'equals', 'pending'), self::when('proof_amount', 'gt', '0')], 'employee_code')],
            ['section_wise_declarations', 'Statutory & income tax', 'Declarations by section', 'Declared and proved amounts per section (80C, 80D, HRA …).', 'tax_investments', self::grouped('section', [['fn' => 'sum', 'field' => 'declared_amount', 'label' => 'Declared'], ['fn' => 'sum', 'field' => 'proof_amount', 'label' => 'Proof'], ['fn' => 'count', 'label' => 'Items']], [], 'bar')],

            // Joining & exit --------------------------------------------------------------------------------
            ['onboarding_in_progress', 'Joining & exit', 'Onboarding in progress', 'New joiners with open onboarding, least progressed first.', 'onboarding',
                self::rows(['employee_code', 'name', 'department', 'joining_date', 'template', 'progress', 'tasks_pending', 'tasks_overdue', 'mandatory_pending'], [self::when('status', 'equals', 'in_progress')], 'progress')],
            ['overdue_onboarding_tasks', 'Joining & exit', 'Overdue onboarding tasks', 'Onboarding tasks past their due date, with the owner.', 'onboarding_tasks',
                self::rows(['employee_code', 'name', 'phase', 'task', 'owner', 'due_on', 'is_mandatory'], [self::when('is_overdue', 'equals', '1')], 'due_on')],
            ['pending_joining_documents', 'Joining & exit', 'Joining documents not yet collected', 'Document tasks still pending in onboarding.', 'onboarding_tasks',
                self::rows(['employee_code', 'name', 'document_type', 'task', 'due_on', 'is_mandatory'], [self::when('type', 'equals', 'document'), self::when('status', 'equals', 'pending')], 'employee_code')],
            ['bgv_open', 'Joining & exit', 'Background verification in progress', 'Open verification cases with checks still open.', 'bgv',
                self::rows(['employee_code', 'name', 'provider', 'status', 'initiated_on', 'tat_days', 'checks', 'checks_open'], [self::when('status', 'in', 'initiated,in_progress')], 'initiated_on')],
            ['bgv_discrepancies', 'Joining & exit', 'Background verification discrepancies', 'Cases closed with a discrepancy or failure.', 'bgv',
                self::rows(['employee_code', 'name', 'provider', 'result', 'checks_flagged', 'completed_on'], [self::when('result', 'in', 'discrepancy,failed')], 'completed_on', 'desc')],
            ['resignations_this_month', 'Joining & exit', 'Resignations this month', 'Resignations initiated in the current month.', 'exits',
                self::rows(['employee_code', 'name', 'department', 'designation', 'resignation_date', 'notice_days', 'last_working_day', 'status'], [self::when('type', 'equals', 'resignation'), self::when('initiated_on', 'this_month')], 'initiated_on', 'desc')],
            ['exits_this_fy', 'Joining & exit', 'Separations (this financial year)', 'Every exit with type, last working day, reason and F&F status.', 'exits',
                self::rows(['employee_code', 'name', 'department', 'designation', 'joining_date', 'type', 'last_working_day', 'tenure_months', 'reason_for_leaving', 'settlement_status'], [self::when('last_working_day', 'this_financial_year'), self::when('status', 'not_equals', 'withdrawn')], 'last_working_day', 'desc')],
            ['exits_by_month', 'Joining & exit', 'Exits by month', 'Completed exits per month.', 'exits', self::grouped('month', [['fn' => 'count']], [self::when('status', 'equals', 'completed')], 'line', 'month')],
            ['exits_by_type', 'Joining & exit', 'Exits by type (this financial year)', 'Resignations, terminations, retirements and others.', 'exits', self::grouped('type', [['fn' => 'count']], [self::when('last_working_day', 'this_financial_year'), self::when('status', 'not_in', 'withdrawn,cancelled')], 'pie')],
            ['exits_by_department', 'Joining & exit', 'Exits by department (this financial year)', 'Exits per department this financial year.', 'exits', self::grouped('department', [['fn' => 'count']], [self::when('last_working_day', 'this_financial_year'), self::when('status', 'not_in', 'withdrawn,cancelled')], 'bar')],
            // Expense & travel (HROne setup parity EX-G) -------------------------------------------------------
            ['expense_advance_amount', 'Expense & travel', 'Expense advance (advance amount)', 'Every advance requested, with the amount asked for, approved, how it was paid and what is outstanding.', 'expense_advances',
                self::rows(['number', 'requested_on', 'employee_code', 'name', 'department', 'purpose', 'currency', 'amount', 'amount_base', 'approved_amount', 'status', 'paid_channel', 'disbursed_on', 'outstanding'], [], 'requested_on', 'desc')],
            ['expense_advance_recovery_register', 'Expense & travel', 'Expense advance recovery register', 'Advance amounts recovered through payroll or the full and final settlement, with the reference.', 'expense_advance_ledger',
                self::rows(['entry_date', 'employee_code', 'name', 'department', 'advance', 'amount', 'through', 'reference'], [self::when('kind', 'equals', 'Recovered')], 'entry_date', 'desc')],
            ['expense_payment_register', 'Expense & travel', 'Expense payment register', 'Approved expense reports with what is owed: waiting for payment, scheduled for payroll or paid, and how.', 'expense_reports',
                self::rows(['number', 'employee_code', 'name', 'department', 'decided_on', 'total_approved', 'advance_adjusted', 'net_payable', 'payment_status', 'payment_channel', 'scheduled_on', 'paid_on', 'paid_month', 'payment_reference'],
                    [self::when('status', 'in', 'Approved,Partly approved,Paid'), self::when('net_payable', 'gt', '0')], 'decided_on', 'desc')],
            ['expense_receipts_by_amount', 'Expense & travel', 'Expense receipt by amount', 'Amount-type expense receipts with merchant, invoice, tax and decision.', 'expense_receipts',
                self::rows(['number', 'expense_date', 'employee_code', 'name', 'department', 'category', 'merchant', 'invoice_number', 'currency', 'amount', 'base_amount', 'tax_amount', 'claimable_amount', 'approved_amount', 'is_exceptional', 'report_number', 'status'],
                    [self::when('type', 'equals', 'Amount')], 'expense_date', 'desc')],
            ['expense_receipts_by_mileage', 'Expense & travel', 'Expense receipt by mileage', 'Mileage-type expense receipts with the route, distance, rate and decision.', 'expense_receipts',
                self::rows(['number', 'expense_date', 'employee_code', 'name', 'department', 'category', 'from_place', 'to_place', 'distance_km', 'rate_per_km', 'base_amount', 'claimable_amount', 'approved_amount', 'distance_edited', 'report_number', 'status'],
                    [self::when('type', 'equals', 'Mileage')], 'expense_date', 'desc')],
            ['expense_receipt_details', 'Expense & travel', 'Expense receipt details', 'Amount and mileage receipts together, with limits, flags and the report they went in.', 'expense_receipts',
                self::rows(['number', 'expense_date', 'employee_code', 'name', 'department', 'category', 'expense_group', 'type', 'base_amount', 'distance_km', 'limit_amount', 'claimable_amount', 'over_limit_amount', 'approved_amount', 'is_exceptional', 'suspected_duplicate', 'report_number', 'status'], [], 'expense_date', 'desc')],
            ['expense_report_details', 'Expense & travel', 'Expense report details', 'Each report with what was claimed, approved, settled against advances, payable and paid.', 'expense_reports',
                self::rows(['number', 'title', 'employee_code', 'name', 'department', 'period_from', 'period_to', 'receipts', 'total_claimed', 'total_approved', 'advance_adjusted', 'net_payable', 'paid_amount', 'status', 'payment_status', 'paid_on'], [], 'submitted_on', 'desc')],
            ['outstanding_expense_advances', 'Expense & travel', 'Outstanding expense advance', 'Dispensed advances not yet settled by reports, refunds or recovery, oldest settle-by date first.', 'expense_advances',
                self::rows(['number', 'employee_code', 'name', 'department', 'disbursed_on', 'approved_amount', 'adjusted', 'refunded', 'recovered', 'outstanding', 'settle_by', 'days_overdue', 'recovery_due_on'],
                    [self::when('status', 'equals', 'Dispensed'), self::when('outstanding', 'gt', '0')], 'settle_by')],
            ['expense_reports_pending', 'Expense & travel', 'Expense reports waiting for approval', 'Submitted reports not yet decided, longest waiting first.', 'expense_reports',
                self::rows(['number', 'submitted_on', 'employee_code', 'name', 'department', 'title', 'receipts', 'total_claimed', 'pending_days'], [self::when('status', 'equals', 'Submitted')], 'pending_days', 'desc')],
            ['expense_by_category_this_year', 'Expense & travel', 'Expense by category (this financial year)', 'Approved expense per category this financial year.', 'expense_receipts',
                self::grouped('category', [['fn' => 'sum', 'field' => 'approved_amount', 'label' => 'Approved'], ['fn' => 'count', 'label' => 'Receipts']], [self::when('status', 'in', 'Approved,Partly approved'), self::when('expense_date', 'this_financial_year')], 'bar', 'sum_approved_amount', 'desc')],
            ['expense_by_department_this_year', 'Expense & travel', 'Expense by department (this financial year)', 'Approved expense per department this financial year.', 'expense_receipts',
                self::grouped('department', [['fn' => 'sum', 'field' => 'approved_amount', 'label' => 'Approved'], ['fn' => 'count', 'label' => 'Receipts']], [self::when('status', 'in', 'Approved,Partly approved'), self::when('expense_date', 'this_financial_year')], 'bar', 'sum_approved_amount', 'desc')],
            // HROne setup parity EX-F: HROne's travel templates.
            ['live_trip', 'Expense & travel', 'Live trip', 'Employees travelling today on an approved trip, with where they are going and their bookings.', 'travel_requests',
                self::rows(['number', 'employee_code', 'name', 'department', 'destinations', 'start_date', 'end_date', 'is_international', 'bookings_open', 'status'], [self::when('trip_state', 'equals', 'Live')], 'end_date')],
            ['travel_details', 'Expense & travel', 'Travel details', 'Every journey of every trip: mode, from, to, dates, who books it and the booking.', 'travel_request_legs',
                self::rows(['trip', 'employee_code', 'name', 'department', 'category', 'mode', 'from_city', 'to_city', 'starts_on', 'ends_on', 'preferred_time', 'booked_by', 'booking_status', 'booking_reference', 'provider', 'booking_cost', 'trip_status'],
                    [self::when('kind', 'equals', 'Travel')], 'starts_on', 'desc')],
            ['travel_summary', 'Expense & travel', 'Travel summary', 'Each trip with its dates, places, estimated cost, advance, bookings and what was claimed against it.', 'travel_requests',
                self::rows(['number', 'employee_code', 'name', 'department', 'purpose', 'start_date', 'end_date', 'days', 'destinations', 'is_international', 'estimated_cost_base', 'advance', 'booked_cost', 'claimed_amount', 'approved_amount', 'status', 'trip_state'], [], 'start_date', 'desc')],
            ['accommodation_details', 'Expense & travel', 'Accommodation details', 'Every stay of every trip: city, check-in, check-out, nights, who books it and the booking.', 'travel_request_legs',
                self::rows(['trip', 'employee_code', 'name', 'department', 'category', 'to_city', 'to_state', 'starts_on', 'ends_on', 'nights', 'booked_by', 'booking_status', 'booking_reference', 'provider', 'booking_cost', 'trip_status'],
                    [self::when('kind', 'equals', 'Accommodation')], 'starts_on', 'desc')],
            ['expense_paid_by_month', 'Expense & travel', 'Expense paid by month (this financial year)', 'Reports paid each month, outside payroll, with payroll or at exit.', 'expense_reports',
                self::grouped('paid_month', [['fn' => 'sum', 'field' => 'paid_amount', 'label' => 'Paid'], ['fn' => 'count', 'label' => 'Reports']], [self::when('status', 'equals', 'Paid'), self::when('paid_on', 'this_financial_year')], 'line', 'paid_month')],

            ['exit_reasons', 'Joining & exit', 'Reasons for leaving', 'Reasons given in exit interviews.', 'exits', self::grouped('reason_for_leaving', [['fn' => 'count']], [self::when('reason_for_leaving', 'not_empty')], 'pie')],
            ['notice_period_tracker', 'Joining & exit', 'Notice period tracker', 'Employees serving notice with their last working day.', 'exits',
                self::rows(['employee_code', 'name', 'department', 'manager', 'resignation_date', 'notice_days', 'last_working_day', 'days_to_last_day', 'pending_clearances'], [self::when('status', 'in', 'initiated,notice,clearance')], 'last_working_day')],
            ['clearances_pending', 'Joining & exit', 'Exit clearances pending', 'No-dues clearances not yet given, by owner.', 'exit_clearances',
                self::rows(['case', 'employee_code', 'name', 'last_working_day', 'clearance', 'owner', 'status', 'recoverable_amount'], [self::when('status', 'in', 'pending,blocked')], 'last_working_day')],
            ['fnf_pending', 'Joining & exit', 'Full and final settlement pending', 'Settlements not yet paid, oldest exit first.', 'final_settlements',
                self::rows(['case', 'employee_code', 'name', 'last_working_day', 'days_since_last_day', 'status', 'net_amount'], [self::when('status', 'not_equals', 'paid')], 'last_working_day')],
            ['fnf_register', 'Joining & exit', 'Full and final settlement register', 'Earnings, recoveries and net payable per settlement.', 'final_settlements',
                self::rows(['case', 'employee_code', 'name', 'exit_type', 'last_working_day', 'total_earnings', 'leave_encashment', 'gratuity', 'total_deductions', 'net_amount', 'status', 'paid_on'], [], 'last_working_day', 'desc')],

            // Performance & learning ------------------------------------------------------------------------
            ['goals_progress', 'Performance & learning', 'Goal progress', 'Active goals with weight, progress and due date.', 'goals',
                self::rows(['cycle', 'employee_code', 'name', 'department', 'title', 'weight', 'progress', 'due_date', 'status'], [self::when('status', 'equals', 'active')], 'employee_code')],
            ['overdue_goals', 'Performance & learning', 'Overdue goals', 'Active goals past their due date and not complete.', 'goals',
                self::rows(['employee_code', 'name', 'title', 'progress', 'due_date'], [self::when('is_overdue', 'equals', '1')], 'due_date')],
            ['rating_distribution', 'Performance & learning', 'Rating distribution', 'Employees per final rating.', 'performance', self::grouped('final_label', [['fn' => 'count']], [self::when('final_label', 'not_empty')], 'pie')],
            ['appraisal_status', 'Performance & learning', 'Appraisal status', 'Appraisals per status in each cycle.', 'performance', self::grouped('status', [['fn' => 'count']], [], 'bar')],
            ['active_pips', 'Performance & learning', 'Active improvement plans', 'Open PIPs with end dates and days left.', 'improvement_plans',
                self::rows(['employee_code', 'name', 'department', 'manager', 'start_date', 'end_date', 'days_left', 'status'], [self::when('status', 'not_in', 'closed,cancelled,withdrawn')], 'end_date')],
            // HROne setup parity HP-8j: initiatives (HROne "Initiative details").
            ['initiative_details', 'Performance & learning', 'Initiative details', 'Every initiative with its type, linked goal, due date, completion and feedback.', 'initiatives',
                self::rows(['employee_code', 'name', 'department', 'initiative_type', 'title', 'goal', 'assigned_by', 'due_date', 'progress', 'status', 'feedback_given'], [], 'due_date')],
            // HROne parity AN-27: performance reports (HROne Performance reports #1–7, 9, 10, 12, 15, 17, 18, 20, 23, 24).
            ['one_on_one_details', 'Performance & learning', '1-on-1 details', 'One-on-ones with who they were with, who asked for them, the agenda, status and follow-up actions.', 'one_on_ones',
                self::rows(['scheduled_on', 'employee_code', 'name', 'department', 'with', 'initiated_by', 'agenda', 'status', 'held_on', 'action_items', 'action_items_open', 'feedback_given'], [], 'scheduled_on', 'desc')],
            ['feedback_360_status', 'Performance & learning', '360 feedback status', 'Per employee and 360 round: the nomination status and how many reviewers were asked, have answered or declined.', 'feedback_360_status',
                self::rows(['round', 'period_end', 'employee_code', 'name', 'department', 'nominator', 'nomination_status', 'asked', 'given', 'declined', 'pending', 'round_status'], [], 'round')],
            ['feedback_360_nominations', 'Performance & learning', '360 nomination status', 'Where each employee\'s 360 nominations stand: to nominate, waiting for approval, approved or sent back.', 'feedback_360_status',
                self::rows(['round', 'employee_code', 'name', 'department', 'nominator', 'nomination_status', 'nominated', 'submitted_on', 'decided_on'], [], 'nomination_status')],
            ['feedback_360_details', 'Performance & learning', '360 feedback details', 'Per employee and source: asked, answered and the average score (anonymous sources only from the policy minimum; never single answers).', 'feedback_360_results',
                self::rows(['round', 'employee_code', 'name', 'department', 'source', 'anonymous', 'asked', 'answered', 'declined', 'average_score', 'note'], [], 'employee_code')],
            ['company_check_ins', 'Performance & learning', 'Company check-in report (this month)', 'Every goal check-in across the organisation this month, at every goal level.', 'check_ins',
                self::rows(['checked_in_on', 'level_label', 'owner', 'goal', 'key_result', 'previous_progress', 'progress', 'confidence', 'note', 'by'], [self::when('checked_in_on', 'this_month')], 'checked_in_on', 'desc')],
            ['employee_goal_check_ins', 'Performance & learning', 'Employee goal check-ins (this month)', 'Check-ins on employee goals this month, by employee, with the manager.', 'check_ins',
                self::rows(['employee_code', 'name', 'department', 'manager', 'goal', 'checked_in_on', 'previous_progress', 'progress', 'change', 'confidence', 'note'], [self::when('level', 'equals', 'employee'), self::when('checked_in_on', 'this_month')], 'employee_code')],
            ['team_check_ins', 'Performance & learning', 'Team check-in report (this month)', 'Check-ins on team goals this month.', 'check_ins',
                self::rows(['checked_in_on', 'owner', 'goal', 'key_result', 'previous_progress', 'progress', 'confidence', 'note', 'by'], [self::when('level', 'equals', 'team'), self::when('checked_in_on', 'this_month')], 'checked_in_on', 'desc')],
            ['check_in_conversations', 'Performance & learning', 'Check-in conversations', 'Employee and manager check-ins per period: submitted, reviewed, goals updated and actions agreed.', 'performance_check_ins',
                self::rows(['period', 'employee_code', 'name', 'department', 'manager', 'cadence', 'status', 'submitted_on', 'reviewed_on', 'goals_updated', 'actions'], [], 'period', 'desc')],
            ['continuous_feedback', 'Performance & learning', 'Continuous feedback', 'Praise and constructive feedback given, about whom, by whom (anonymous givers never named) and on which goal or competency.', 'continuous_feedback',
                self::rows(['given_on', 'employee_code', 'name', 'department', 'type', 'author', 'visibility', 'goal', 'competency', 'message'], [self::when('type_code', 'in', 'praise,constructive')], 'given_on', 'desc')],
            ['goal_sheet_status', 'Performance & learning', 'Goal status (goals not created or not approved)', 'Goal sheets that are not active yet: not created, draft, waiting for approval or sent back.', 'goal_plans',
                self::rows(['cycle', 'employee_code', 'name', 'department', 'manager', 'sheet_status', 'goals', 'total_weight', 'versions', 'latest_plan_status', 'submitted_on', 'decided_on'], [self::when('sheet_status_key', 'not_equals', 'active')], 'sheet_status')],
            ['goals_not_created', 'Performance & learning', 'Goals not created', 'Employees of a cycle who have no goal yet.', 'goal_plans',
                self::rows(['cycle', 'employee_code', 'name', 'department', 'designation', 'manager'], [self::when('sheet_status_key', 'equals', 'not_created')], 'employee_code')],
            ['pip_evaluations', 'Performance & learning', 'PIP evaluations', 'Checkpoints and checklist items of improvement plans: due, owner, status and review.', 'pip_checkpoints',
                self::rows(['employee_code', 'name', 'department', 'manager', 'plan_status', 'checkpoint', 'kind', 'owner', 'due_date', 'status_label', 'reviewed_on', 'reviewed_by'], [], 'due_date')],
            ['performance_feedback_details', 'Performance & learning', 'Performance feedback details', 'The strengths, areas to improve and comments written in each submitted review.', 'appraisal_reviews',
                self::rows(['cycle', 'employee_code', 'name', 'level', 'reviewer', 'overall_rating', 'strengths', 'improvements', 'comments', 'submitted_on'], [self::when('status', 'equals', 'submitted'), self::when('has_comments', 'equals', '1')], 'employee_code')],
            ['performance_review_details', 'Performance & learning', 'Performance review details', 'The final rating of every goal, KPI and competency per employee and cycle (the last review level).', 'appraisal_ratings',
                self::rows(['cycle', 'employee_code', 'name', 'department', 'item_type', 'item', 'weight', 'rating', 'achievement_pct'], [self::when('is_final_level', 'equals', '1')], 'employee_code')],
            ['performance_review_details_by_approver', 'Performance & learning', 'Performance review details by approver', 'Every goal, KPI and competency rating given at each review level, with the reviewer.', 'appraisal_ratings',
                self::rows(['cycle', 'employee_code', 'name', 'level', 'reviewer', 'item_type', 'item', 'weight', 'rating', 'achievement_pct'], [], 'employee_code')],
            ['performance_review_rating_by_approver', 'Performance & learning', 'Performance review rating by approver', 'The overall rating given at each review level of each appraisal, with the reviewer and status.', 'appraisal_reviews',
                self::rows(['cycle', 'employee_code', 'name', 'department', 'level', 'reviewer', 'status', 'overall_rating', 'submitted_on'], [], 'employee_code')],
            ['tni_details', 'Performance & learning', 'TNI details (training needs)', 'Open training and development needs per employee: competency or skill, levels, priority and target date.', 'development_needs',
                self::rows(['employee_code', 'name', 'department', 'title', 'competency', 'skill', 'current_level', 'target_level', 'source', 'priority', 'status_label', 'target_date'], [self::when('status', 'in', 'open,in_progress')], 'target_date')],
            ['mandatory_learning', 'Performance & learning', 'Mandatory learning completion', 'Completion and overdue counts per mandatory course.', 'learning', self::grouped('course', [['fn' => 'count'], ['fn' => 'sum', 'field' => 'is_completed', 'label' => 'Completed'], ['fn' => 'sum', 'field' => 'is_overdue', 'label' => 'Overdue']], [self::when('is_mandatory', 'equals', '1')], 'table')],
            ['learning_overdue', 'Performance & learning', 'Overdue learning', 'Learning assignments past their due date.', 'learning',
                self::rows(['employee_code', 'name', 'department', 'course', 'due_on', 'progress'], [self::when('is_overdue', 'equals', '1')], 'due_on')],

            // Assets & service desk -------------------------------------------------------------------------
            ['assets_assigned', 'Assets & service desk', 'Assets assigned to employees', 'Every asset in use with its custodian.', 'assets',
                self::rows(['asset_tag', 'name', 'category', 'custodian', 'location', 'condition', 'warranty_until'], [self::when('status', 'equals', 'assigned')], 'custodian')],
            ['assets_by_category', 'Assets & service desk', 'Assets by category and status', 'Number of assets per category.', 'assets', self::grouped('category', [['fn' => 'count'], ['fn' => 'sum', 'field' => 'purchase_cost', 'label' => 'Purchase cost']], [], 'bar')],
            ['tickets_by_category', 'Assets & service desk', 'HR tickets by category (this month)', 'Service desk tickets raised this month per category.', 'servicedesk', self::grouped('category', [['fn' => 'count'], ['fn' => 'sum', 'field' => 'breached', 'label' => 'SLA breached']], [self::when('raised_on', 'this_month')], 'bar')],
            ['sla_breaches', 'Assets & service desk', 'SLA breaches (this month)', 'Tickets that missed their SLA this month.', 'servicedesk',
                self::rows(['number', 'service', 'category', 'employee_code', 'priority', 'status', 'agent', 'raised_on', 'resolution_hours'], [self::when('raised_on', 'this_month'), self::when('breached', 'equals', '1')], 'raised_on', 'desc')],

            // Administration --------------------------------------------------------------------------------
            ['user_access_review', 'Administration', 'User access review', 'Every user with roles, two-factor status and last sign-in.', 'users',
                self::rows(['name', 'email', 'employee_code', 'status', 'roles', 'mfa_enabled', 'last_login', 'days_since_login'], [], 'name')],
            ['users_without_mfa', 'Administration', 'Active users without two-factor', 'Active accounts that have not enabled two-factor authentication.', 'users',
                self::rows(['name', 'email', 'roles', 'last_login'], [self::when('status', 'equals', 'active'), self::when('mfa_enabled', 'equals', '0')], 'name')],
            ['dormant_users', 'Administration', 'Dormant users (no sign-in for 60 days)', 'Active accounts unused for 60 days or never used.', 'users',
                self::rows(['name', 'email', 'roles', 'last_login', 'days_since_login', 'created_on'], [self::when('status', 'equals', 'active'), self::when('days_since_login', 'gt', '60')], 'days_since_login', 'desc')],
        ];

        return array_map(fn (array $t) => ['key' => $t[0], 'category' => $t[1], 'name' => $t[2], 'description' => $t[3], 'dataset' => $t[4], 'definition' => $t[5]], $templates);
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return collect($this->all())->firstWhere('key', $key);
    }

    /** @return Collection<string, list<array<string, mixed>>> templates the user may use, grouped by category in display order */
    public function availableTo(User $user, string $search = ''): Collection
    {
        $allowed = $this->datasets->availableTo($user);
        $search = mb_strtolower(trim($search));

        return collect($this->all())
            ->filter(fn (array $t) => isset($allowed[$t['dataset']]))
            ->filter(fn (array $t) => $search === '' || str_contains(mb_strtolower($t['name'].' '.$t['description'].' '.$t['category']), $search))
            ->groupBy('category')
            ->sortBy(fn ($items, string $category) => array_search($category, self::CATEGORIES, true))
            ->map(fn (Collection $items) => $items->values()->all());
    }

    /** HROne parity WO-1: operators a register may hand to a template (its own filters, see Filament\Support\RegisterReport::filtersFrom). */
    public const REGISTER_OPERATORS = ['equals', 'not_equals', 'in', 'not_in', 'gte', 'lte', 'between'];

    /**
     * Save a template as the user's own report. $employeeCode narrows it to one employee (when the dataset
     * has an employee code); $shared publishes it to everyone who can read the dataset (analytics.manage).
     * HROne parity WO-1: $filters are extra conditions from the register the report was opened from (its filters);
     * only fields of the dataset and the REGISTER_OPERATORS are kept, the rest is dropped.
     *
     * @param  list<array{field: string, operator: string, value: string|null}>  $filters
     */
    public function create(string $key, User $user, ?string $name = null, ?string $employeeCode = null, bool $shared = false, array $filters = []): Report
    {
        $template = $this->find($key) ?? throw new RuntimeException('Unknown report template.');
        $dataset = $this->datasets->get($template['dataset']);
        if (! $dataset->allowedFor($user)) {
            throw new RuntimeException('You cannot report on the '.$dataset->label().' dataset.');
        }
        if ($shared && ! $user->hasPermission('analytics.manage')) {
            throw new RuntimeException('Only analytics managers can share reports with everyone.');
        }

        $definition = $template['definition'];
        $employeeCode = trim((string) $employeeCode);
        if ($employeeCode !== '') {
            if (! isset($dataset->fields()['employee_code'])) {
                throw new RuntimeException('This report cannot be narrowed to one employee.');
            }
            $definition['filters'][] = self::when('employee_code', 'equals', $employeeCode);
        }
        foreach ($filters as $filter) {
            $field = (string) ($filter['field'] ?? '');
            $operator = (string) ($filter['operator'] ?? '');
            if (isset($dataset->fieldsFor($user)[$field]) && in_array($operator, self::REGISTER_OPERATORS, true)) {
                $definition['filters'][] = self::when($field, $operator, isset($filter['value']) ? mb_substr((string) $filter['value'], 0, 20000) : null);
            }
        }

        return Report::create([
            'name' => trim((string) $name) ?: $template['name'].($employeeCode !== '' ? ' — '.$employeeCode : ''),
            'description' => $template['description'],
            'dataset' => $template['dataset'],
            'definition' => $definition,
            'owner_id' => $user->id,
            'is_shared' => $shared,
        ]);
    }

    /** @return array{field: string, operator: string, value: string|null} */
    private static function when(string $field, string $operator, ?string $value = null): array
    {
        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }

    /** @param  list<string>  $fields @param  list<array<string, mixed>>  $filters */
    private static function rows(array $fields, array $filters = [], ?string $sort = null, string $direction = 'asc'): array
    {
        return ['fields' => $fields, 'filters' => $filters, 'sort' => $sort ? ['field' => $sort, 'dir' => $direction] : null, 'visualization' => ['type' => 'table']];
    }

    /** @param  list<array<string, mixed>>  $aggregations @param  list<array<string, mixed>>  $filters */
    private static function grouped(string $by, array $aggregations, array $filters = [], string $chart = 'bar', ?string $sort = null, string $direction = 'asc'): array
    {
        return [
            'fields' => [$by], 'filters' => $filters, 'group_by' => $by, 'aggregations' => $aggregations,
            'sort' => $sort ? ['field' => $sort, 'dir' => $direction] : null, 'visualization' => ['type' => $chart],
        ];
    }
}
