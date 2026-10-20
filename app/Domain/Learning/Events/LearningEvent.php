<?php

namespace App\Domain\Learning\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * learning.assigned / due_soon / overdue / completed / failed / certificate_expiring / session.registered,
 * and Phase 8: course.published / retired, enrolment.requested / approved / rejected / cancelled,
 * started, certificate.issued / expired / revoked, program.enrolled / completed, session.waitlisted.
 * Catalogue events carry no employee.
 */
final class LearningEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = []) {}
}
