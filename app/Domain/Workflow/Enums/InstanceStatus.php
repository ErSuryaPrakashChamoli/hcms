<?php

namespace App\Domain\Workflow\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InstanceStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Waiting = 'waiting';
    // HROne setup parity P3: an approver sent the run back to its initiator; it resumes at the same approval step on
    // resubmission (WorkflowEngine::resubmit).
    case Returned = 'returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Returned => 'Sent back',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Waiting, self::Returned => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }

    /** Running or waiting: the engine advances it. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Running, self::Waiting], true);
    }

    /** HROne setup parity P3: statuses in which a run still owns its subject's decision (open, or sent back to its initiator). */
    public static function inFlight(): array
    {
        return [self::Running, self::Waiting, self::Returned];
    }
}
