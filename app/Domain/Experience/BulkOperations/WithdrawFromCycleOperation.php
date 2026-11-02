<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Services\Appraisals;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity GO-2 (matrix 27 §27.2.2 #9, §27.3.3 #5 — the safe alternative to HROne's "Delete goals" / "Delete reviews";
 * owner decision PER-10 / HP-8k: nothing is deleted): withdraws each appraisal from its cycle through Appraisals::withdraw
 * — pending reviews and periodic reviews withdrawn, the pending goal sheet and the open goals cancelled, with one reason
 * for the run. Finalized appraisals are refused (immutable), and so is one's own. performance.manage within the person's
 * access scope.
 */
final class WithdrawFromCycleOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'appraisals.withdraw';
    }

    public function label(): string
    {
        return 'Withdraw from cycle';
    }

    public function description(): string
    {
        return 'Each appraisal leaves its cycle: its pending reviews are withdrawn and the employee\'s open goals and pending goal sheet of the cycle are cancelled with the reason. Nothing is deleted; finalized appraisals are refused.';
    }

    public function model(): string
    {
        return Appraisal::class;
    }

    public function permission(): string
    {
        return 'performance.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-arrow-left-start-on-rectangle';
    }

    public function color(): string
    {
        return 'danger';
    }

    public function schema(): array
    {
        return [Textarea::make('reason')->label('Why are they withdrawn from the cycle?')->required()->maxLength(500)];
    }

    public function prepare(array $data, User $actor): array
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Say why the employees are withdrawn from the cycle.');
        }

        return ['reason' => mb_substr($reason, 0, 500)];
    }

    public function with(): array
    {
        return ['employee.person', 'cycle'];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Appraisal $record */
        app(Appraisals::class)->withdraw($record, (string) $input['reason'], $actor);

        return 'Withdrawn from '.($record->cycle?->name ?? 'the cycle');
    }
}
