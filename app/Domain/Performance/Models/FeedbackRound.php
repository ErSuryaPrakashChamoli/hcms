<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity HP-8h (PF-1): one round of a 360 policy for a period of its frequency, with a response window. It
 * keeps the policy's answers and a copy of each source's feedback template as they were at launch, so changing the
 * policy or a template never changes a running round. open → closed (by HR, or when the window has passed) or cancelled.
 */
#[Fillable(['tenant_id', 'policy_id', 'performance_cycle_id', 'name', 'period_start', 'period_end', 'window_opens_on', 'window_closes_on', 'status', 'auto', 'settings', 'templates', 'extensions', 'launched_by', 'closed_at', 'cancel_reason'])]
class FeedbackRound extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'open', 'auto' => false, 'extensions' => 0];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \RuntimeException('360 feedback rounds are never deleted; cancel the round instead.'));
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'window_opens_on' => 'date', 'window_closes_on' => 'date',
            'auto' => 'boolean', 'settings' => 'array', 'templates' => 'array', 'extensions' => 'integer', 'closed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return '360 feedback round '.$this->name;
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function launcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'launched_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(FeedbackParticipant::class);
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(FeedbackNomination::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Answers are taken while the round is open and today is inside its window. */
    public function windowOpen(): bool
    {
        $today = today()->toDateString();

        return $this->isOpen() && $this->window_opens_on->toDateString() <= $today && $this->window_closes_on->toDateString() >= $today;
    }

    /** A policy answer as it was at launch. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings[$key] ?? null;

        return $value === null || $value === '' || $value === [] ? $default : $value;
    }

    /** @return array{id: int, name: string, questions: list<array<string, mixed>>}|null the template a source answers */
    public function templateFor(string $relation): ?array
    {
        $template = $this->templates[$relation] ?? null;

        return is_array($template) && ! empty($template['questions']) ? $template : null;
    }
}
