<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\Checkbox;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-1 (§23.6.4 #2 bulk final clearance, §23.2.2 #7): completes each exit through Exits::complete — refused
 * where a clearance stage is still open or the settlement is not approved (unless completing without settlement).
 */
final class CompleteExitOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'exit_cases.complete';
    }

    public function label(): string
    {
        return 'Complete exit';
    }

    public function description(): string
    {
        return 'Marks each employee as exited on their last working day and disables their login. Exits with clearance stages still open, or without an approved settlement, are refused.';
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
        return 'heroicon-m-check-badge';
    }

    public function color(): string
    {
        return 'success';
    }

    public function with(): array
    {
        return ['employee.person', 'clearances', 'settlement'];
    }

    public function schema(): array
    {
        return [Checkbox::make('skip_settlement')->label('Complete without an approved settlement (e.g. no dues)')];
    }

    public function prepare(array $data, User $actor): array
    {
        return ['skip_settlement' => (bool) ($data['skip_settlement'] ?? false)];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var ExitCase $record */
        app(Exits::class)->complete($record, $actor, ! ($input['skip_settlement'] ?? false));

        return 'Exit completed';
    }
}
