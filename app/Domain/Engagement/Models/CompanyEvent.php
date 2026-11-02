<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Location;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * HROne parity GO-15 (Engage → Events): a company event — a town hall, a celebration, a workshop — held once or on
 * several sessions, online, offline or both, for an audience (a saved audience or criteria, resolved in the preparer's
 * scope when it is published). Draft → published → completed, or cancelled with a reason (invitees are told; nothing is
 * deleted). Changes go through `CompanyEvents`; once published, the schedule and the audience stay as they were.
 */
#[Fillable([
    'tenant_id', 'title', 'event_type', 'event_sub_type', 'description', 'agenda', 'host_employee_id', 'is_mandatory', 'allow_nomination', 'seats', 'mode',
    'location_id', 'venue', 'meeting_url', 'timezone', 'recurrence', 'starts_at', 'ends_at', 'attachments', 'video_url', 'audience_id', 'audience_criteria',
    'pre_feedback_template_id', 'post_feedback_template_id', 'status', 'invited_count', 'created_by', 'scope_user_id', 'published_at', 'published_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason', 'completed_at',
])]
class CompanyEvent extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'cancelled' => 'Cancelled', 'completed' => 'Completed'];

    public const MODES = ['online' => 'Online meeting', 'offline' => 'Offline meeting', 'hybrid' => 'Hybrid'];

    /** Fixed once the event is published: its schedule and its audience (cancel and add a new event to change them). */
    public const FROZEN = ['timezone', 'recurrence', 'starts_at', 'ends_at', 'audience_id', 'audience_criteria', 'is_mandatory'];

    protected $attributes = ['status' => 'draft', 'mode' => 'offline', 'is_mandatory' => false, 'allow_nomination' => false];

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            if (! array_key_exists((string) $event->status, self::STATUSES) || ! array_key_exists((string) $event->mode, self::MODES)) {
                throw new RuntimeException('Unknown event status or mode.');
            }
            if ($event->exists && $event->getRawOriginal('status') !== 'draft' && array_intersect(array_keys($event->getDirty()), self::FROZEN) !== []) {
                throw new RuntimeException('A published event keeps its schedule and audience; cancel it and add a new one to change them.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Events are never deleted; cancel the event instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean', 'allow_nomination' => 'boolean', 'seats' => 'integer', 'invited_count' => 'integer', 'recurrence' => 'array',
            'attachments' => 'array', 'audience_criteria' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'published_at' => 'datetime',
            'cancelled_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return 'Event '.$this->title;
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CompanyEventSession::class, 'event_id')->orderBy('sequence');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyEventInvitation::class, 'event_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'host_employee_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function preFeedbackTemplate(): BelongsTo
    {
        return $this->belongsTo(FeedbackTemplate::class, 'pre_feedback_template_id');
    }

    public function postFeedbackTemplate(): BelongsTo
    {
        return $this->belongsTo(FeedbackTemplate::class, 'post_feedback_template_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** Seats only limit an optional event: everyone invited to a mandatory one is expected. */
    public function limitsSeats(): bool
    {
        return ! $this->is_mandatory && $this->seats !== null;
    }

    public function typeLabel(): ?string
    {
        return app(OptionLists::class)->label('event_types', $this->event_type);
    }

    public function subTypeLabel(): ?string
    {
        return app(OptionLists::class)->label('event_sub_types', $this->event_sub_type);
    }

    public function modeLabel(): string
    {
        return self::MODES[$this->mode] ?? (string) $this->mode;
    }

    /** Where it is held, as people read it: the location / venue, the meeting link, or both. */
    public function placeLabel(): string
    {
        $place = collect([$this->location?->name, $this->venue])->filter()->implode(', ');

        return match ($this->mode) {
            'online' => 'Online',
            'hybrid' => $place !== '' ? $place.' and online' : 'Online',
            default => $place !== '' ? $place : 'To be announced',
        };
    }
}
