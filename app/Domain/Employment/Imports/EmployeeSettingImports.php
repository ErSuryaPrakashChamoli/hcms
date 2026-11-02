<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Attendance\Services\ScheduleAssignments;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Services\PaymentModes;
use App\Domain\People\Actions\UpdatePersonAction;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Data import DI-2 (Workforce → Employee settings): the per-employee settings PeopleOS keeps, one row per employee, each
 * through the path its own screen uses — every column optional, blank keeps what is on file:
 *
 * - device user id (the id an attendance device knows the person by): the employee record, as the employee edit screen
 *   and the employee import write it (employee.update; unique in the tenant);
 * - whether colleagues see the birthday / wedding anniversary (the wishes on Birthdays and the wall): UpdatePersonAction,
 *   as the employee edit screen (employee.update);
 * - the work schedule (weekly pattern of shifts and weekly offs) from a date: ScheduleAssignments, as the Employee 360's
 *   "Assign work schedule" (attendance.manage);
 * - how salary and reimbursements are paid (bank transfer, cash, cheque; the paying account) from a date:
 *   PaymentModes::set, as Payroll → Payment modes (payroll.manage);
 * - the time zone of the employee's sign-in: the user record, as Users → Edit (user.update).
 *
 * Every row is a change of an employee on file, so the upload has no "new or existing" choice.
 */
final class EmployeeSettingImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'employee_settings';

    /** setting group => the right its screen needs */
    private const RIGHTS = ['employee' => 'employee.update', 'schedule' => ScheduleAssignments::PERMISSION, 'pay_mode' => 'payroll.manage', 'time_zone' => 'user.update'];

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'device_user_id' => ['Device user id', false], 'birthday_shared' => ['Show birthday to colleagues', false],
            'wedding_anniversary_shared' => ['Show wedding anniversary to colleagues', false], 'work_schedule' => ['Work schedule', false],
            'schedule_from' => ['Schedule from', false], 'salary_pay_mode' => ['Salary pay mode', false], 'reimbursement_pay_mode' => ['Reimbursement pay mode', false],
            'paying_account' => ['Paying account', false], 'pay_mode_from' => ['Pay mode from', false], 'time_zone' => ['Time zone', false], 'reason' => ['Reason', false],
        ];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Employee settings upload';
    }

    protected function module(): string
    {
        return 'employment';
    }

    protected function entityType(): string
    {
        return Employee::class;
    }

    protected function aliases(): array
    {
        return ['biometric_id' => 'device_user_id', 'attendance_device_id' => 'device_user_id', 'device_id' => 'device_user_id', 'birthday_visible' => 'birthday_shared',
            'share_birthday' => 'birthday_shared', 'anniversary_visible' => 'wedding_anniversary_shared', 'share_wedding_anniversary' => 'wedding_anniversary_shared',
            'schedule' => 'work_schedule', 'weekly_off' => 'work_schedule', 'weekly_off_schedule' => 'work_schedule', 'schedule_code' => 'work_schedule',
            'pay_mode' => 'salary_pay_mode', 'payment_mode' => 'salary_pay_mode', 'salary_payment_mode' => 'salary_pay_mode', 'reimbursement_mode' => 'reimbursement_pay_mode',
            'paid_from' => 'paying_account', 'debit_account' => 'paying_account', 'timezone' => 'time_zone', 'time_zone_preference' => 'time_zone', 'remarks' => 'reason'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with(['person', 'user'])->find($employeeId);
        [$settings, $problems] = $this->parse($r, $employee);
        $errors = [...$errors, ...$problems];
        if ($errors !== [] || $employee === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        if (isset($context['seen'][$employeeId])) {
            return ['action' => 'error', 'errors' => ["This employee is also in row {$context['seen'][$employeeId]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$employeeId] = $row->row_number;
        if (isset($settings['device_user_id'])) {
            $device = mb_strtolower($settings['device_user_id']);
            if (isset($context['devices'][$device])) {
                return ['action' => 'error', 'errors' => ["Device user id '{$settings['device_user_id']}' is also in row {$context['devices'][$device]}."], 'employee_id' => $employeeId];
            }
            $context['devices'][$device] = $row->row_number;
        }

        $changes = $this->changes($employee, $settings);
        if (isset($changes['schedule']) && ($problem = $this->scheduleProblem($employee, $settings['schedule_from'])) !== null) {
            return ['action' => 'error', 'errors' => [$problem], 'employee_id' => $employeeId];
        }
        $refusals = [];
        foreach (array_unique(array_map(fn (string $group) => self::RIGHTS[$group], array_keys($changes))) as $permission) {
            $refusals[] = $this->rightsProblem($import, $employee, $permission);
        }
        if (($refusals = array_values(array_filter($refusals))) !== []) {
            return ['action' => 'error', 'errors' => $refusals, 'employee_id' => $employeeId];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'matched_on' => ['employee code']];

        return $changes === []
            ? ['action' => 'skip', 'match' => $match + ['reason' => 'These settings are already on file.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['changes' => array_merge(...array_values($changes))], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        $employee->loadMissing('user');
        [$settings, $problems] = $this->parse($r, $employee);
        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }
        $changes = $this->changes($employee, $settings);
        $reason = trim((string) ($r['reason'] ?? '')) ?: $this->why($import);
        $done = [];

        if (isset($changes['employee'])) {
            app(ProfileChangeGuard::class)->authorize($user, $employee, 'employee.update', $this->origin($import));
            $person = array_intersect_key($settings, array_flip(['birthday_shared', 'wedding_anniversary_shared']));
            if ($person !== [] && $employee->person !== null) {
                app(UpdatePersonAction::class)->handle($employee->person, $person, $reason);
            }
            if (array_key_exists('device_user_id', $settings) && $settings['device_user_id'] !== $employee->device_user_id) {
                $employee->withAuditReason($reason)->update(['device_user_id' => $settings['device_user_id']]);
            }
            $done[] = implode(', ', $changes['employee']);
        }
        if (isset($changes['schedule'])) {
            if (($problem = $this->scheduleProblem($employee, $settings['schedule_from'])) !== null) {
                throw new RuntimeException($problem);
            }
            app(ScheduleAssignments::class)->assign($employee, $settings['schedule'], $settings['schedule_from'], $reason, $user);
            $done[] = "work schedule {$settings['schedule']->name} from {$settings['schedule_from']}";
        }
        if (isset($changes['pay_mode'])) {
            [$salaryAccount, $reimbursementAccount] = $this->payAccounts($settings);
            app(PaymentModes::class)->set($employee, $settings['pay_mode_from'], $settings['salary_pay_mode'], $salaryAccount, $settings['reimbursement_pay_mode'], $reimbursementAccount, $reason, $user);
            $done[] = 'pay mode from '.$settings['pay_mode_from'];
        }
        if (isset($changes['time_zone'])) {
            if (! $user->hasPermission('user.update')) {
                throw new RuntimeException('Changing a sign-in\'s time zone needs user.update.');
            }
            $employee->user->withAuditReason($reason)->update(['timezone' => $settings['time_zone']]);
            $done[] = 'time zone '.$settings['time_zone'];
        }

        return $done === [] ? "{$employee->employee_code}: already on file" : "{$employee->employee_code}: ".implode('; ', $done);
    }

    public function format(): array
    {
        $modes = app(PaymentModes::class)->enabled();
        $accounts = CompanyBankAccount::query()->where('status', 'active')->orderBy('alias')->pluck('alias')->filter()->all();

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Device user id', 'No', 'The id the attendance device knows the person by, if it is not the employee code (up to 64 characters; unique).', '1045'],
                ['Show birthday to colleagues', 'No', 'yes or no: the birthday shows in Birthdays and wishes on the wall.', 'yes'],
                ['Show wedding anniversary to colleagues', 'No', 'yes or no.', 'no'],
                ['Work schedule', 'No', 'A work schedule (weekly pattern of shifts and weekly offs) by code or name: '.implode(', ', WorkSchedule::query()->where('status', 'active')->orderBy('name')->limit(20)->pluck('code')->filter()->all()).'.', 'GEN-5D'],
                ['Schedule from', 'No', 'The day the schedule starts (YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY). Blank: today.', now()->toDateString()],
                ['Salary pay mode', 'No', 'One of the pay modes in use: '.implode(', ', array_map(fn ($code, $label) => "{$code} ({$label})", array_keys($modes), $modes)).'.', 'online_transfer'],
                ['Reimbursement pay mode', 'No', 'As the salary pay mode; blank: as the salary.', ''],
                ['Paying account', 'No', 'For bank transfers: the company\'s paying account by its alias'.($accounts === [] ? '' : ' ('.implode(', ', $accounts).')').'. Blank: the account at the employee\'s bank, else the default.', ''],
                ['Pay mode from', 'No', 'The day the pay mode applies from. Blank: the first day of this month.', now()->startOfMonth()->toDateString()],
                ['Time zone', 'No', 'The time zone of the employee\'s sign-in, as in the time-zone database (Asia/Kolkata, Europe/London …).', 'Asia/Kolkata'],
                ['Reason', 'No', 'Why the settings change (audited). Blank: the upload\'s name.', 'Go-live settings'],
            ],
            'notes' => [
                'Every column but the employee code is optional; a blank cell keeps what is on file. A row whose settings are already on file is skipped.',
                'Each setting is changed as on its own screen and needs that screen\'s right: device user id and sharing need employee.update, the work schedule attendance.manage, the pay mode payroll.manage, the time zone user.update (the employee needs a sign-in).',
                'A work schedule or pay mode starts on its date; the one in force before ends the day before. History is kept.',
                'Not in PeopleOS per employee: sign-in on several devices, two-factor sign-in, single sign-on ids and module licences (Teams, WhatsApp, learning, projects, live tracking, facial recognition) are organisation settings or not offered; check-in rules (mobile, web, geo, Wi-Fi, selfie) come from the check-in policy of the employee\'s group; sign-ins are given with "Invite to sign in" on the employee list.',
            ],
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->orderBy('id')->limit(2)->pluck('employee_code')->all();
        $schedule = WorkSchedule::query()->where('status', 'active')->orderBy('id')->first();
        $mode = array_key_first(app(PaymentModes::class)->enabled()) ?? 'online_transfer';

        return [
            [$codes[0] ?? 'EMP0001', '1045', 'yes', 'no', $schedule?->code ?? '', $schedule ? now()->toDateString() : '', $mode, '', '', now()->startOfMonth()->toDateString(), 'Asia/Kolkata', 'Go-live settings'],
            [$codes[1] ?? 'EMP0002', '1046', 'no', '', '', '', '', '', '', '', '', 'Go-live settings'],
        ];
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The settings a row gives, read (the work schedule as its model, the paying account as its id), with what is wrong.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function parse(array $r, ?Employee $employee): array
    {
        $errors = [];
        $settings = [];
        if (isset($r['device_user_id'])) {
            $device = trim($r['device_user_id']);
            if (mb_strlen($device) > 64) {
                $errors[] = 'Device user id is longer than 64 characters.';
            } elseif (($holder = Employee::query()->withoutGlobalScope(AccessScope::class)->where('device_user_id', $device)->where('id', '!=', $employee?->getKey() ?? 0)->value('employee_code')) !== null) {
                $errors[] = "Device user id '{$device}' is already used by employee {$holder}.";
            }
            $settings['device_user_id'] = $device;
        }
        foreach (['birthday_shared' => 'Show birthday to colleagues', 'wedding_anniversary_shared' => 'Show wedding anniversary to colleagues'] as $field => $label) {
            if (($flag = $this->yesNo($r[$field] ?? null, $label, $errors)) !== null) {
                $settings[$field] = $flag;
            }
        }
        if (isset($r['work_schedule'])) {
            $value = mb_strtolower(trim($r['work_schedule']));
            $schedules = WorkSchedule::query()->where('status', 'active')->get();
            $schedule = $schedules->first(fn (WorkSchedule $s) => mb_strtolower((string) $s->code) === $value) ?? $schedules->first(fn (WorkSchedule $s) => mb_strtolower(trim((string) $s->name)) === $value);
            $schedule === null ? $errors[] = "Work schedule '{$r['work_schedule']}' is not an active work schedule." : $settings['schedule'] = $schedule;
            $settings['schedule_from'] = $this->dateCell($r['schedule_from'] ?? null, 'Schedule from', $errors) ?? now()->toDateString();
        } elseif (isset($r['schedule_from'])) {
            $errors[] = 'Schedule from needs a work schedule.';
        }
        if (isset($r['salary_pay_mode']) || isset($r['reimbursement_pay_mode']) || isset($r['paying_account'])) {
            $enabled = app(PaymentModes::class)->enabled();
            $current = $employee === null ? null : app(PaymentModes::class)->for((int) $employee->getKey(), now());
            $salary = isset($r['salary_pay_mode']) ? self::codeIn($enabled, $r['salary_pay_mode']) : ($current?->salary_mode ?? 'online_transfer');
            if ($salary === null) {
                $errors[] = 'Salary pay mode must be one of the pay modes in use: '.implode(', ', array_keys($enabled)).'.';
            }
            $reimbursement = isset($r['reimbursement_pay_mode']) ? self::codeIn($enabled, $r['reimbursement_pay_mode']) : null;
            if (isset($r['reimbursement_pay_mode']) && $reimbursement === null) {
                $errors[] = 'Reimbursement pay mode must be one of the pay modes in use: '.implode(', ', array_keys($enabled)).'.';
            }
            $account = null;
            if (isset($r['paying_account'])) {
                $account = CompanyBankAccount::query()->where('status', 'active')->get()
                    ->first(fn (CompanyBankAccount $a) => mb_strtolower(trim((string) $a->alias)) === mb_strtolower(trim($r['paying_account'])))?->getKey();
                if ($account === null) {
                    $errors[] = "Paying account '{$r['paying_account']}' is not an active paying account.";
                } elseif ($salary !== 'online_transfer' && ($reimbursement ?? $salary) !== 'online_transfer') {
                    $errors[] = 'A paying account goes with bank transfers only.';
                }
            }
            $settings += ['salary_pay_mode' => $salary, 'reimbursement_pay_mode' => $reimbursement, 'paying_account' => $account === null ? null : (int) $account,
                'pay_mode_from' => $this->dateCell($r['pay_mode_from'] ?? null, 'Pay mode from', $errors) ?? now()->startOfMonth()->toDateString()];
        } elseif (isset($r['pay_mode_from'])) {
            $errors[] = 'Pay mode from needs a salary pay mode.';
        }
        if (isset($r['time_zone'])) {
            $zone = collect(timezone_identifiers_list())->first(fn (string $z) => mb_strtolower($z) === mb_strtolower(trim($r['time_zone'])));
            if ($zone === null) {
                $errors[] = "Time zone '{$r['time_zone']}' is not a time zone (for example Asia/Kolkata).";
            } elseif ($employee !== null && $employee->user_id === null) {
                $errors[] = "{$employee->employee_code} has no sign-in to set a time zone for.";
            }
            $settings['time_zone'] = $zone;
        }
        if ($settings === [] && $errors === []) {
            $errors[] = 'Give at least one setting.';
        }

        return [$settings, array_values(array_unique($errors))];
    }

    /**
     * What a row would change, by setting group (group => the fields that change).
     *
     * @return array<string, list<string>>
     */
    private function changes(Employee $employee, array $settings): array
    {
        $changes = [];
        foreach (['device_user_id' => $employee->device_user_id, 'birthday_shared' => $employee->person?->birthday_shared, 'wedding_anniversary_shared' => $employee->person?->wedding_anniversary_shared] as $field => $current) {
            if (array_key_exists($field, $settings) && ! self::sameValue($current, $settings[$field])) {
                $changes['employee'][] = $field;
            }
        }
        if (isset($settings['schedule'])) {
            $inForce = app(ScheduleAssignments::class)->on($employee, $settings['schedule_from']);
            if ((int) $inForce?->work_schedule_id !== (int) $settings['schedule']->getKey()) {
                $changes['schedule'] = ['work_schedule'];
            }
        }
        if (isset($settings['salary_pay_mode'])) {
            $inForce = app(PaymentModes::class)->for((int) $employee->getKey(), Carbon::parse($settings['pay_mode_from']));
            $same = $inForce !== null ? $this->samePayMode($inForce, $settings) : ($settings['salary_pay_mode'] === 'online_transfer' && $settings['paying_account'] === null
                && in_array($settings['reimbursement_pay_mode'], [null, 'online_transfer'], true));
            if (! $same) {
                $changes['pay_mode'] = ['pay_mode'];
            }
        }
        if (isset($settings['time_zone']) && $employee->user !== null && (string) $employee->user->timezone !== $settings['time_zone']) {
            $changes['time_zone'] = ['time_zone'];
        }

        return $changes;
    }

    /** A schedule assignment already starting on or after the date would overlap the new one. */
    private function scheduleProblem(Employee $employee, string $from): ?string
    {
        $later = WorkScheduleAssignment::query()->where('employee_id', $employee->getKey())->whereDate('effective_from', '>=', $from)->orderBy('effective_from')->first();

        return $later === null ? null : 'A work schedule already starts on '.$later->effective_from->toDateString().': use a later date, or change it on the Employee 360.';
    }

    /** @return array{0: ?int, 1: ?int} the paying account of the salary and of reimbursements (bank transfers only) */
    private function payAccounts(array $settings): array
    {
        $account = $settings['paying_account'] ?? null;

        return [$settings['salary_pay_mode'] === 'online_transfer' ? $account : null, ($settings['reimbursement_pay_mode'] ?? $settings['salary_pay_mode']) === 'online_transfer' ? $account : null];
    }

    private function samePayMode(EmployeePaymentMode $mode, array $settings): bool
    {
        [$salaryAccount, $reimbursementAccount] = $this->payAccounts($settings);

        return $mode->salary_mode === $settings['salary_pay_mode'] && (int) $mode->salary_bank_account_id === (int) $salaryAccount
            && $mode->reimbursementMode() === ($settings['reimbursement_pay_mode'] ?? $settings['salary_pay_mode'])
            && (int) ($mode->reimbursement_mode === null ? $mode->salary_bank_account_id : $mode->reimbursement_bank_account_id) === (int) $reimbursementAccount;
    }

    /** Why the uploader may not change this group of settings for this employee (the screen's own right), or null. */
    private function rightsProblem(EmployeeImport $import, Employee $employee, string $permission): ?string
    {
        if ($permission === 'employee.update') {
            return $this->refusal($import, $employee, $permission);
        }
        $actor = $this->uploader($import);
        if ($actor === null || ! $actor->hasPermission($permission)) {
            return "This needs {$permission}.";
        }

        return app(AccessScopes::class)->allows($actor, $employee) ? null : 'That employee is outside your organisation scope.';
    }
}
