<?php

namespace App\Domain\Workflow\Events;

use App\Domain\Workflow\Models\WorkflowTask;
use Illuminate\Foundation\Events\Dispatchable;

final class WorkflowTaskAssigned
{
    use Dispatchable;

    public function __construct(public readonly WorkflowTask $task, public readonly string $reason = 'assigned') {}
}
