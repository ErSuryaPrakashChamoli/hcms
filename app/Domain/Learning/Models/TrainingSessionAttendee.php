<?php

namespace App\Domain\Learning\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'training_session_id', 'employee_id', 'learning_enrolment_id', 'status', 'waitlist_position', 'registered_at', 'cancelled_at', 'feedback', 'rating'])]
class TrainingSessionAttendee extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'registered'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'waitlist_position' => 'integer', 'registered_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(LearningEnrolment::class, 'learning_enrolment_id');
    }
}
