<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** HROne parity WO-1 (§23.6.3 #2, §23.2.2 #6 bulk "Initiate clearance"): Exits::startClearance on each exit serving notice. */
final class StartClearanceOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'exit_cases.start_clearance';
    }

    public function label(): string
    {
        return 'Start clearance';
    }

    public function description(): string
    {
        return 'Starts the clearance stages of each exit serving its notice and tells the stage owners. Exits in any other status are refused.';
    }

    public function model(): string
    {
        return ExitCase::class;
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
        return ['employee.person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        return in_array($record->getAttribute('status'), ['initiated', 'notice'], true) ? null : 'Clearance starts only on an exit serving its notice (this one is '.config('peopleos.exit.statuses.'.$record->getAttribute('status'), $record->getAttribute('status')).').';
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var ExitCase $record */
        app(Exits::class)->startClearance($record, $actor);

        return 'Clearance started; owners notified';
    }
}
