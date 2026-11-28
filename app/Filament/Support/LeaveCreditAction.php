<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\LeaveYear;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * "Credit leave" on Leave → Balances: add days to any employee's balance for a leave type and leave year, also when they
 * have no balance row yet (the row's own "Adjust" needs one). Posts a ledger adjustment through LeaveAdjustments, so the
 * balance row is created, the change is audited and the balance events fire exactly as for "Adjust".
 */
final class LeaveCreditAction
{
    public const NAME = 'creditLeave';

    public static function make(): Action
    {
        return Action::make(self::NAME)->label('Credit leave')->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn () => self::allowed(auth()->user()))
            ->authorize(fn () => self::allowed(auth()->user()))
            ->modalHeading('Credit leave to an employee')
            ->modalDescription('Adds days to an employee’s leave balance, also when they have no balance for that leave type and year yet. To take days away, use Adjust on the balance row.')
            ->modalSubmitActionLabel('Credit leave')
            ->schema([
                WorkforceBulkWizards::employeeSelect('employee_id', 'Employee', true)->required()->live(),
                Select::make('leave_type_id')->label('Leave type')->required()->live()
                    ->options(fn () => LeaveType::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('period_year')->label('Leave year')->required()->live()->selectablePlaceholder(false)
                    ->options(fn () => self::years())->default(fn () => app(LeaveYear::class)->periodFor(now())),
                Text::make(fn (Get $get) => self::balanceNote($get('employee_id'), $get('leave_type_id'), $get('period_year')) ?? '')
                    ->visible(fn (Get $get) => filled($get('employee_id')) && filled($get('leave_type_id'))),
                TextInput::make('days')->label('Days to credit')->numeric()->required()->minValue(0.5)->maxValue(366)->step(0.5)->rule('multiple_of:0.5')
                    ->helperText('Half days allowed.'),
                Textarea::make('note')->label('Reason')->required()->maxLength(255)->rows(2)
                    ->helperText('Kept with the entry and shown in the leave transactions and the audit trail.'),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $entry = self::credit(auth()->user(), $data);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Leave not credited')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Leave credited')
                    ->body(sprintf('%s day%s of %s credited to %s for %d.', self::days((float) $entry->days), (float) $entry->days === 1.0 ? '' : 's',
                        $entry->leaveType?->name, $entry->employee?->auditLabel(), $entry->period_year))
                    ->send();
            });
    }

    /** leave.adjust (leave.manage also grants it): the right the row's own "Adjust" needs. */
    public static function allowed(?User $user): bool
    {
        return $user !== null && $user->can('leave.adjust');
    }

    /**
     * Credit the days, after the same checks as the row's "Adjust": the right, and the employee within the user's leave
     * access scope (the balance — existing, or the one this credit starts — must be one the user may adjust).
     *
     * @param  array<string, mixed>  $data  employee_id, leave_type_id, period_year, days, note
     */
    public static function credit(?User $user, array $data): LeaveLedgerEntry
    {
        if (! self::allowed($user)) {
            throw new RuntimeException('You cannot change leave balances.');
        }
        $employee = is_numeric($data['employee_id'] ?? null) ? Employee::query()->with('person')->find((int) $data['employee_id']) : null;
        $type = is_numeric($data['leave_type_id'] ?? null) ? LeaveType::query()->where('status', 'active')->find((int) $data['leave_type_id']) : null;
        $year = (int) ($data['period_year'] ?? 0);
        $days = round((float) ($data['days'] ?? 0), 2);
        $note = trim((string) ($data['note'] ?? ''));

        if ($employee === null) {
            throw new RuntimeException('Choose an employee in your access scope.');
        }
        if ($type === null) {
            throw new RuntimeException('Choose an active leave type.');
        }
        if (! array_key_exists($year, self::years())) {
            throw new RuntimeException('Choose the previous, the current or the next leave year.');
        }
        if ($days <= 0) {
            throw new RuntimeException('Enter the days to credit (more than zero).');
        }
        if ($note === '') {
            throw new RuntimeException('Say why the leave is credited.');
        }
        if (! $user->can('adjust', self::balanceOrNew($employee, $type, $year))) {
            throw new RuntimeException('This employee is outside your leave access.');
        }

        return app(LeaveAdjustments::class)->adjust($employee, $type, $days, $note, $year)->load(['employee.person', 'leaveType']);
    }

    /** @return array<int, string> the previous, the current and the next leave year */
    public static function years(): array
    {
        $current = app(LeaveYear::class)->periodFor(now());

        return [$current - 1 => (string) ($current - 1), $current => $current.' (current)', $current + 1 => (string) ($current + 1)];
    }

    /** What the employee has now for the type and year, read without creating a balance (null when nothing is picked). */
    public static function balanceNote(mixed $employeeId, mixed $typeId, mixed $year): ?string
    {
        $employee = is_numeric($employeeId) ? Employee::query()->find((int) $employeeId) : null;
        $type = is_numeric($typeId) ? LeaveType::query()->find((int) $typeId) : null;
        if ($employee === null || $type === null || ! is_numeric($year)) {
            return null;
        }
        $balance = self::existing($employee, $type, (int) $year);
        $now = $balance === null
            ? "No {$type->name} balance for {$year} yet: this credit starts it."
            : sprintf('%s balance for %d: %s day%s available now.', $type->name, (int) $year, self::days($balance->available()), $balance->available() === 1.0 ? '' : 's');
        $policy = app(LeaveEntitlements::class)->forType($employee, $type) === null
            ? " No leave policy gives this employee {$type->name}: the days are credited all the same."
            : '';

        return $now.$policy;
    }

    private static function existing(Employee $employee, LeaveType $type, int $year): ?LeaveBalance
    {
        return LeaveBalance::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('period_year', $year)->first();
    }

    /** The balance the credit lands on: the existing row, or an unsaved one for the policy's scope check. */
    private static function balanceOrNew(Employee $employee, LeaveType $type, int $year): LeaveBalance
    {
        return self::existing($employee, $type, $year) ?? new LeaveBalance(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'period_year' => $year]);
    }

    private static function days(float $days): string
    {
        return rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
    }
}
