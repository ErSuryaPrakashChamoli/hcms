<?php

namespace App\Domain\Recognition\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Letters\Models\LetterTemplate;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * HROne setup parity HP-9c (Engage → Badges): a badge colleagues give one another. HROne's nine system badges are
 * seeded (RecognitionDefaults) and can be renamed, restyled or switched off (status), never deleted or re-coded. A
 * custom badge also carries HROne's custom-badge rules: who may give it (roles and rule conditions) and receive it,
 * public or private, how often one person may receive it (per calendar or rolling month, quarter or year, or ever),
 * whether a reason or an attachment is required, and the certificate (a letter template) issued with it.
 * HP-9d (Reward applicability): a badge may carry reward points (paid from the points budget to the receiver while
 * reward points are on) and show them on the social wall.
 */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'icon', 'tone', 'is_system', 'status', 'sort_order', 'visibility', 'givers', 'giver_roles', 'receivers', 'receive_limit', 'receive_period', 'receive_period_basis', 'reason_required', 'attachment_required', 'certificate_template_id', 'core_value', 'reward_points', 'show_points_on_wall'])]
class Badge extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Allowed', 'inactive' => 'Not allowed'];

    public const VISIBILITY = ['public' => 'Public (everyone who sees recognitions)', 'private' => 'Private (the receiver, their manager, the giver and HR)'];

    public const PERIODS = ['month' => 'Month', 'quarter' => 'Quarter', 'year' => 'Year', 'ever' => 'Ever'];

    public const BASES = ['calendar' => 'Calendar period (e.g. this month)', 'rolling' => 'Rolling period (e.g. the last 30 days)'];

    /** Tile tones (pos-tile-icon data-tone). */
    public const TONES = ['indigo' => 'Indigo', 'violet' => 'Violet', 'sky' => 'Sky', 'teal' => 'Teal', 'emerald' => 'Emerald', 'amber' => 'Amber', 'gold' => 'Gold', 'rose' => 'Rose'];

    public const ICONS = [
        'heroicon-o-star' => 'Star', 'heroicon-o-trophy' => 'Trophy', 'heroicon-o-sparkles' => 'Sparkles', 'heroicon-o-hand-thumb-up' => 'Thumbs up',
        'heroicon-o-heart' => 'Heart', 'heroicon-o-light-bulb' => 'Light bulb', 'heroicon-o-rocket-launch' => 'Rocket', 'heroicon-o-users' => 'Team',
        'heroicon-o-academic-cap' => 'Mentor', 'heroicon-o-face-smile' => 'Smile', 'heroicon-o-bolt' => 'Bolt', 'heroicon-o-fire' => 'Fire',
        'heroicon-o-check-badge' => 'Badge', 'heroicon-o-gift' => 'Gift', 'heroicon-o-puzzle-piece' => 'Puzzle', 'heroicon-o-shield-check' => 'Shield',
    ];

    protected $attributes = ['status' => 'active', 'is_system' => false, 'visibility' => 'public', 'icon' => 'heroicon-o-star', 'tone' => 'indigo',
        'receive_limit' => 0, 'receive_period' => 'month', 'receive_period_basis' => 'calendar', 'reason_required' => false, 'attachment_required' => false, 'sort_order' => 0, 'reward_points' => 0, 'show_points_on_wall' => false];

    protected static function booted(): void
    {
        static::saving(function (self $badge): void {
            $badge->code = strtoupper(trim((string) $badge->code));
            foreach (['status' => self::STATUSES, 'visibility' => self::VISIBILITY, 'receive_period' => self::PERIODS, 'receive_period_basis' => self::BASES, 'tone' => self::TONES, 'icon' => self::ICONS] as $attribute => $allowed) {
                if (! array_key_exists((string) $badge->getAttribute($attribute), $allowed)) {
                    throw new RuntimeException("The badge's {$attribute} is not one of the allowed values.");
                }
            }
            if ((int) $badge->receive_limit < 0) {
                throw new RuntimeException('The receiving limit cannot be negative.');
            }
            if ((int) $badge->reward_points < 0) {
                throw new RuntimeException('Reward points cannot be negative.');
            }
            if ($badge->exists && $badge->getRawOriginal('is_system') && ($badge->isDirty('code') || $badge->isDirty('is_system'))) {
                throw new RuntimeException('A standard badge keeps its code; rename it or switch it off instead.');
            }
            $badge->givers = array_values((array) ($badge->givers ?? []));
            $badge->receivers = array_values((array) ($badge->receivers ?? []));
            $badge->giver_roles = array_values(array_filter(array_map('strval', (array) ($badge->giver_roles ?? []))));
        });
        static::deleting(function (self $badge): void {
            if ($badge->is_system) {
                throw new RuntimeException('A standard badge cannot be deleted; switch it off instead.');
            }
            if ($badge->recognitions()->withoutGlobalScopes()->where('tenant_id', $badge->tenant_id)->exists()) {
                throw new RuntimeException('This badge has been given; switch it off instead of deleting it.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'givers' => 'array', 'giver_roles' => 'array', 'receivers' => 'array', 'receive_limit' => 'integer',
            'reason_required' => 'boolean', 'attachment_required' => 'boolean', 'sort_order' => 'integer',
            'reward_points' => 'integer', 'show_points_on_wall' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'recognition';
    }

    public function auditLabel(): string
    {
        return "Badge {$this->name}";
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function certificateTemplate(): BelongsTo
    {
        return $this->belongsTo(LetterTemplate::class, 'certificate_template_id');
    }

    public function recognitions(): HasMany
    {
        return $this->hasMany(Recognition::class, 'badge_id');
    }
}
