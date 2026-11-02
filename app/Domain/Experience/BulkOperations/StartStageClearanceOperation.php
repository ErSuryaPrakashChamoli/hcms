<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Services\Exits;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-11 (§23.6.3 #2, §23.2.2 #6 bulk "Initiate" on the Clearances register): Exits::startClearance on the exit
 * of each selected stage still serving its notice. A second stage of an exit already started is refused.
 */
final class StartStageClearanceOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'exit_clearances.start';
    }

    public function label(): string
    {
        return 'Start clearance';
    }

    public function description(): string
    {
        return 'Starts the clearance of each selected stage\'s exit (all its stages) and tells the stage owners. Exits not serving their notice are refused.';
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
        return 'heroicon-m-clipboard-document-check';
    }

    public function with(): array
    {
        return ['exitCase.employee.person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var ExitClearance $record */
        $status = $record->exitCase?->status;

        return in_array($status, ['initiated', 'notice'], true) ? null
            : ($status === 'clearance' ? 'Clearance has already started for this exit.' : 'Clearance starts only on an exit serving its notice (this one is '.config('peopleos.exit.statuses.'.$status, $status).').');
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var ExitClearance $record */
        // Read the exit again: an earlier stage of the same exit in this run may have started it already.
        $case = $record->exitCase()->firstOrFail();
        if (! in_array($case->status, ['initiated', 'notice'], true)) {
            $this->refuse('Clearance has already started for this exit.');
        }
        app(Exits::class)->startClearance($case, $actor);

        return 'Clearance started; owners notified';
    }

    public function recordLabel(Model $record): string
    {
        return ClearanceRecordLabel::for($record);
    }
}
