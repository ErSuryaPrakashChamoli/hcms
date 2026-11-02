<?php

namespace App\Domain\Recognition\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Recognition\Models\RewardPointEntry;
use App\Domain\Recognition\Services\RewardPoints;

/**
 * Data import DI-35 ("Rewards", HROne Import → Engage; matrix 27 §27.10 #12): reward points credited (positive) or debited
 * (negative) to employees. Each row is an adjustment through RewardPoints::adjust, as "Adjust points" on the Reward points
 * page: recognition.points within the uploader's access scope, never one's own points, a reason, credits taken from the
 * points budget (refused when it is short) and debits only from points the employee has earned. Validation counts the rows
 * above in the same file against the budget and the balances. Rows only add ledger entries (no "update existing").
 */
final class RewardPointImports extends RowImports
{
    public const TYPE = 'reward_points';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'points' => ['Points', true], 'reason' => ['Reason', true]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Reward points upload';
    }

    protected function module(): string
    {
        return 'recognition';
    }

    protected function entityType(): string
    {
        return RewardPointEntry::class;
    }

    protected function aliases(): array
    {
        return ['reward_points' => 'points', 'credit_debit' => 'points', 'remarks' => 'reason', 'note' => 'reason', 'description' => 'reason'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => User::query()->find($import->uploaded_by), 'budget' => app(RewardPoints::class)->budgetAvailable(), 'balances' => []];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $points = trim((string) ($r['points'] ?? ''));
        if (! preg_match('/^[+-]?\d+$/', $points) || (int) $points === 0) {
            $errors[] = 'Points must be a whole number other than 0: positive to credit, negative to debit.';
        }
        if (trim((string) ($r['reason'] ?? '')) === '') {
            $errors[] = 'Reason is required.';
        }
        if ($errors !== [] || $employeeId === null || $context['actor'] === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $service = app(RewardPoints::class);
        $employee = Employee::query()->findOrFail($employeeId);
        $actor = $context['actor'];
        if (! $service->manages($actor, $employee) || (int) $employee->user_id === (int) $actor->getKey()) {
            return ['action' => 'error', 'errors' => ['You cannot adjust this employee\'s points.'], 'employee_id' => $employeeId];
        }
        $points = (int) $points;
        if ($points > 0 && $points > $context['budget']) {
            return ['action' => 'error', 'errors' => ['The points budget has '.$context['budget'].' points left after the rows above.'], 'employee_id' => $employeeId];
        }
        $context['balances'][$employeeId] ??= $service->earnedBalance($employee);
        if ($points < 0 && $context['balances'][$employeeId] < -$points) {
            return ['action' => 'error', 'errors' => ['The employee has '.$context['balances'][$employeeId].' points (after the rows above); they cannot lose '.(-$points).'.'], 'employee_id' => $employeeId];
        }
        $context['budget'] -= $points;
        $context['balances'][$employeeId] += $points;

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $points = (int) $r['points'];
        app(RewardPoints::class)->adjust(Employee::query()->findOrFail($row->employee_id), $points, $user, $r['reason']);

        return sprintf('%+d points %s', $points, $points > 0 ? 'credited from the budget' : 'debited back to the budget');
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Points', 'Yes', 'Whole points: positive to credit, negative to debit (e.g. 50 or -20).', '50'],
                ['Reason', 'Yes', 'Why the points change; the employee sees it in their reward points.', 'Quarterly sales contest winner'],
            ],
            'notes' => [
                'Credits come out of the points budget (Engage → Reward points → Deposit): a row is refused when the budget, after the rows above, is short.',
                'Debits take back points the employee has earned and return them to the budget; never more than they have.',
                'Each row is one adjustment in the reward points ledger, which is never edited; correct a row with another row.',
                'Needs the reward points right, the employee in your access, and never your own points.',
            ],
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->orderBy('id')->limit(2)->pluck('employee_code')->all() ?: ['EMP0001'];

        return [
            [$codes[0], '50', 'Quarterly sales contest winner'],
            [$codes[1] ?? $codes[0], '20', 'Referral of the month'],
        ];
    }
}
