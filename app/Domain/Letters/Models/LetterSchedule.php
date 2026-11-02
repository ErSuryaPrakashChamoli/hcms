<?php

namespace App\Domain\Letters\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne parity WO-13 (§23.7.2 #2–5, 7, 11): a letter schedule — a template generated (and, when it needs no approval,
 * issued) for an audience either once (now or at a chosen date and time) or around an employee date (joining, birthday,
 * confirmation, resignation, leaving, retirement, work anniversary, absconding) N days before or after.
 *
 * status: saved (kept for later use, does not run) → active → completed (one-time) | paused | cancelled. Runs as the
 * person who set it up (their access scope and rights); `peopleos:letters:tick` picks due schedules every day.
 */
#[Fillable(['tenant_id', 'name', 'letter_template_id', 'signatory_ids', 'trigger', 'run_at', 'event', 'offset_days', 'audience', 'audience_criteria', 'employee_ids', 'extra', 'issue', 'email_to', 'status', 'created_by', 'last_run_on', 'runs_count', 'letters_count', 'last_error'])]
class LetterSchedule extends Model
{
    use Auditable, BelongsToTenant;

    public const TRIGGERS = ['one_time' => 'One time', 'event' => 'Event based'];

    public const AUDIENCES = ['employees' => 'Specific employees', 'criteria' => 'Employees organisation-wise', 'upcoming_joiners' => 'Upcoming joiners'];

    public const STATUSES = ['saved' => 'Saved for later use', 'active' => 'Active', 'paused' => 'Paused', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'saved', 'offset_days' => 0, 'issue' => true, 'runs_count' => 0, 'letters_count' => 0];

    protected function casts(): array
    {
        return [
            'signatory_ids' => 'array', 'audience_criteria' => 'array', 'employee_ids' => 'array', 'extra' => 'array', 'email_to' => 'array',
            'run_at' => 'datetime', 'last_run_on' => 'date', 'issue' => 'boolean', 'offset_days' => 'integer', 'runs_count' => 'integer', 'letters_count' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'letters';
    }

    public function auditLabel(): string
    {
        return (string) $this->name;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LetterTemplate::class, 'letter_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LetterScheduleItem::class);
    }

    public function letters(): HasMany
    {
        return $this->hasMany(Letter::class);
    }

    public function isEvent(): bool
    {
        return $this->trigger === 'event';
    }

    /** "Event frequency" in the schedule list: One time · now / at a date, or the event with its offset. */
    public function frequencyLabel(): string
    {
        if (! $this->isEvent()) {
            return $this->run_at === null ? 'One time · publish now' : 'One time · publish later';
        }
        $event = config("peopleos.letters.schedule_events.{$this->event}", (string) $this->event);
        $days = abs((int) $this->offset_days);

        return $days === 0 ? "{$event} · on the day" : "{$event} · {$days} day(s) ".($this->offset_days < 0 ? 'before' : 'after');
    }

    /** "Occurrence": once, or yearly for birthdays and work anniversaries, or once per employee for the other dates. */
    public function occurrenceLabel(): string
    {
        return match (true) {
            ! $this->isEvent() => 'Once',
            in_array($this->event, ['date_of_birth', 'work_anniversary'], true) => 'Every year',
            default => 'Once per employee',
        };
    }
}
