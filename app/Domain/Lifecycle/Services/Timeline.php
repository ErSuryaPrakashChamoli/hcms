<?php

namespace App\Domain\Lifecycle\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** The only writer of timeline entries. Services call it; nothing else should. */
final class Timeline
{
    /** @param  array<string, mixed>  $metadata */
    public function record(
        Employee $employee,
        string $category,
        string $title,
        CarbonInterface|string|null $occurredOn = null,
        ?string $description = null,
        ?Model $source = null,
        array $metadata = [],
    ): EmployeeTimelineEntry {
        return EmployeeTimelineEntry::create([
            'employee_id' => $employee->getKey(),
            'occurred_on' => Carbon::parse($occurredOn ?? now())->toDateString(),
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'actor_id' => auth()->id(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
