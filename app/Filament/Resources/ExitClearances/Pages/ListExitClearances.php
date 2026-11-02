<?php

namespace App\Filament\Resources\ExitClearances\Pages;

use App\Filament\Resources\ExitClearances\ExitClearanceResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;

/** HROne parity WO-11 (§23.6.3 #1): the Clearances register with its views, report, saved views and bulk actions. */
class ListExitClearances extends PeopleListRecords
{
    use HasRegisterTools;

    /** HROne parity WO-11: start clearance, cancel stages (D-WO3: cancel with a reason, never delete). */
    public const BULK_OPERATIONS = ['exit_clearances.start', 'exit_clearances.cancel'];

    protected static string $resource = ExitClearanceResource::class;

    public static function registerKey(): string
    {
        return 'exit_clearances';
    }

    public static function presetViews(): array
    {
        return [
            'open' => ['label' => 'Open stages', 'filters' => ['status' => ['values' => ['pending', 'blocked']]]],
            'overdue' => ['label' => 'Overdue stages', 'filters' => ['status' => ['values' => ['pending', 'blocked']], 'overdue' => ['isActive' => true]]],
            'blocked' => ['label' => 'Blocked stages', 'filters' => ['status' => ['values' => ['blocked']]]],
            'not_started' => ['label' => 'Not started (serving notice)', 'filters' => ['status' => ['values' => ['pending']], 'not_started' => ['isActive' => true]]],
            'mine' => ['label' => 'Mine to clear', 'filters' => ['status' => ['values' => ['pending', 'blocked']], 'mine' => ['isActive' => true]]],
            'closed' => ['label' => 'Cleared and not applicable', 'filters' => ['status' => ['values' => ['cleared', 'na']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['clearances_pending'];
    }

    public static function registerBulkOperations(): array
    {
        return self::BULK_OPERATIONS;
    }

    protected function getHeaderActions(): array
    {
        return [...$this->registerToolActions()];
    }
}
