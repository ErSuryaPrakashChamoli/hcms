<?php

namespace App\Domain\Recognition\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** HROne setup parity HP-9e: one person's reaction to a wall post (one per person and post; they may change or take it back). */
#[Fillable(['tenant_id', 'wall_post_id', 'user_id', 'reaction'])]
class WallReaction extends Model
{
    use Auditable, BelongsToTenant;

    public const REACTIONS = ['like' => 'Like', 'celebrate' => 'Celebrate', 'love' => 'Love', 'clap' => 'Applause'];

    public const ICONS = ['like' => 'heroicon-o-hand-thumb-up', 'celebrate' => 'heroicon-o-sparkles', 'love' => 'heroicon-o-heart', 'clap' => 'heroicon-o-trophy'];

    protected static function booted(): void
    {
        static::saving(function (self $reaction): void {
            if (! array_key_exists((string) $reaction->reaction, self::REACTIONS)) {
                throw new RuntimeException('Unknown reaction.');
            }
        });
    }

    public function auditModule(): string
    {
        return 'recognition';
    }

    public function auditLabel(): string
    {
        return 'Wall reaction on post #'.$this->wall_post_id;
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(WallPost::class, 'wall_post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
