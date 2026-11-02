<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Services\SeparationDesk;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity WO-11 (§23.6.3 #2, owner decision D-WO3): HROne's bulk "Delete" of clearance tasks is "cancel with a reason"
 * here — each open stage is closed as not applicable (marked cancelled, with the reason) and kept; nothing is deleted.
 */
final class CancelClearanceOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'exit_clearances.cancel';
    }

    public function label(): string
    {
        return 'Cancel stages';
    }

    public function description(): string
    {
        return 'Closes each open stage as cancelled (not applicable), with one reason. Stages are kept with their history; nothing is deleted. Stages already cleared or closed are refused.';
    }

    public function model(): string
    {
        return ExitClearance::class;
    }

    public function permission(): string
    {
        return 'exit.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-no-symbol';
    }

    public function color(): string
    {
        return 'danger';
    }

    public function schema(): array
    {
        return [Textarea::make('reason')->label('Reason')->required()->maxLength(500)];
    }

    public function prepare(array $data, User $actor): array
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for cancelling.');
        }

        return ['reason' => mb_substr($reason, 0, 500)];
    }

    public function with(): array
    {
        return ['exitCase.employee.person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var ExitClearance $record */
        return $record->isOpen() ? null : 'Only a stage still open can be cancelled (this one is '.config('peopleos.exit.clearance_statuses.'.$record->status, $record->status).').';
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var ExitClearance $record */
        app(SeparationDesk::class)->cancelStage($record, $actor, (string) $input['reason']);

        return 'Stage cancelled';
    }

    public function recordLabel(Model $record): string
    {
        return ClearanceRecordLabel::for($record);
    }
}
