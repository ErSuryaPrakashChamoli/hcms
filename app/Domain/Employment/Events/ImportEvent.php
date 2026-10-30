<?php

namespace App\Domain\Employment\Events;

use App\Domain\Employment\Imports\EmployeeImport;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HROne setup parity P2: import.validated (an upload waits for approval), import.approved, import.rejected (its
 * workflow rejected it; the upload is discarded) and import.completed. A workflow starts on import.validated only when
 * the upload type is under workflow approval ($startsWorkflow); notification rules may listen to all. Context holds
 * the upload's type, file name and counts, never row data. Recipients get an in-app note when no rule sends one.
 */
final class ImportEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public readonly string $name,
        public readonly EmployeeImport $import,
        public readonly array $context = [],
        public readonly array $recipientUserIds = [],
        public readonly bool $startsWorkflow = false,
    ) {}
}
