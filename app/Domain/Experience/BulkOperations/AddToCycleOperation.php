<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Appraisals;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity GO-2 (matrix 27 §27.2.2 #3–4, §27.3.3 #3; HROne "Initiate goal / review cycle for selected employees / OU /
 * bulk"): adds each employee to a running performance cycle through Appraisals::addEmployee — the launch path (eligibility,
 * one appraisal per employee and cycle, the reviews of the open stages, periodic reviews, the mapped goal sheet). An
 * employee already in the cycle gets the open stages' reviews brought up to date; a withdrawn or ineligible one is refused
 * with the reason. performance.manage within the person's access scope.
 */
final class AddToCycleOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.add_to_cycle';
    }

    public function label(): string
    {
        return 'Add to performance cycle';
    }

    public function description(): string
    {
        return 'Each employee gets an appraisal in the running cycle (the launch eligibility applies), the reviews of the stages already open and the mapped goal sheet. Employees already in the cycle have their open reviews brought up to date; withdrawn or ineligible employees are refused with the reason.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'performance.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-user-plus';
    }

    public function schema(): array
    {
        return [
            Select::make('performance_cycle_id')->label('Performance cycle')->required()->native(false)
                ->options(fn () => PerformanceCycle::query()->where('status', 'active')->orderByDesc('period_start')->pluck('name', 'id')->all()),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $cycle = PerformanceCycle::query()->find((int) ($data['performance_cycle_id'] ?? 0));
        if ($cycle === null || $cycle->status !== 'active') {
            throw new RuntimeException('Choose a running performance cycle.');
        }

        return ['performance_cycle_id' => $cycle->id];
    }

    public function with(): array
    {
        return ['person', 'currentManager'];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $cycle = PerformanceCycle::query()->findOrFail((int) $input['performance_cycle_id']);
        $result = app(Appraisals::class)->addEmployee($cycle, $record, $actor);

        return $result['created']
            ? 'Added to '.$cycle->name.($result['reviews'] > 0 ? ' with '.$result['reviews'].' review(s)' : '')
            : 'Already in '.$cycle->name.($result['reviews'] > 0 ? '; '.$result['reviews'].' review(s) added' : '; nothing to add');
    }
}
