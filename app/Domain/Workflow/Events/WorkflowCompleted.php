<?php

namespace App\Domain\Workflow\Events;

use App\Domain\Workflow\Models\WorkflowInstance;
use Illuminate\Foundation\Events\Dispatchable;

final class WorkflowCompleted
{
    use Dispatchable;

    public function __construct(public readonly WorkflowInstance $instance) {}
}
