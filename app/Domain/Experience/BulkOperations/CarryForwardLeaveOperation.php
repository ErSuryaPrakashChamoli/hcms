<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Services\LeaveYearEnd;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity TX-8a (matrix 24 §24.6.6 #3, global action "Carry forward leaves"): the year close of a leave year for each
 * selected employee and the chosen leave types — LeaveYearEnd::postFor, i.e. LeaveAccrual::closeYear (carry forward all,
 * none or up to the limit; lapse the rest; idempotent by its ledger keys, audited as the year close). Refused: a year that
 * has not ended, an employee outside the access scope or no longer employed. leave.carry_forward (TX-2; leave.manage still
 * grants it); the "Carry forward leaves" page starts it for selections larger than a direct run takes.
 */
final class CarryForwardLeaveOperation extends BaseBulkOperation
{
    public const KEY = 'leave_carry_forward.post';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Carry forward leaves';
    }

    public function description(): string
    {
        return 'Posts the year close of the leave year for each selected employee: the days to carry forward go into the next leave year and the rest lapse, as the leave policy says (all of it, none, or up to the limit). Rows already carried forward are left as they are; employees outside your access or no longer employed are refused.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return LeaveYearEnd::PERMISSION;
    }

    public function icon(): string
    {
        return 'heroicon-m-arrow-uturn-right';
    }

    public function schema(): array
    {
        $years = app(LeaveYearEnd::class);
        $period = $years->currentPeriod() - 1;

        return [
            Select::make('period')->label('Leave year')->options($years->yearOptions())->default($period)->required()->native(false),
            Select::make('codes')->label('Leave types')->multiple()->native(false)->placeholder('Every leave type a leave policy carries')
                ->options(collect($years->types($period))->mapWithKeys(fn ($type, string $code) => [$code => $code.' · '.$type->name])->all()),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        if (! isset($data['period']) || ! is_numeric($data['period'])) {
            throw new RuntimeException('Choose the leave year.');
        }
        $period = (int) $data['period'];
        $years = app(LeaveYearEnd::class);
        $years->assertCanPost($period);
        $codes = null;
        if (filled($data['codes'] ?? null)) {
            $codes = array_values(array_intersect(array_map('strval', (array) $data['codes']), array_keys($years->types($period))));
            if ($codes === []) {
                throw new RuntimeException('None of the chosen leave types is carried by a leave policy in that year.');
            }
        }

        return ['period' => $period, 'codes' => $codes];
    }

    public function with(): array
    {
        return ['person'];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        [, $message] = app(LeaveYearEnd::class)->postFor($record, (int) $input['period'], $input['codes'] ?? null, $actor);

        return mb_substr($message, 0, 500);
    }
}
