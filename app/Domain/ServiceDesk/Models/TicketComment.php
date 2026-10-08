<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Case communication. The visibility decides who reads a comment:
 * - **employee:** the requester and the case team;
 * - **internal:** HR on the case, never the employee or the API;
 * - **restricted:** only people with explicit access to the case.
 *
 * `is_internal` mirrors "not employee-visible" for older readers. Each comment has at most one private
 * attachment (size, type and hash recorded). Comments are append-only.
 */
#[Fillable(['tenant_id', 'ticket_id', 'author_id', 'body', 'is_internal', 'visibility', 'attachment_path', 'attachment_name', 'attachment_size', 'attachment_mime', 'attachment_sha256'])]
class TicketComment extends Model
{
    use BelongsToTenant;

    public const VISIBILITIES = ['employee', 'internal', 'restricted'];

    protected $attributes = ['visibility' => 'employee', 'is_internal' => false];

    protected static function booted(): void
    {
        static::saving(function (self $comment): void {
            if (! in_array($comment->visibility, self::VISIBILITIES, true)) {
                throw new \RuntimeException('Unknown comment visibility.');
            }
            $comment->is_internal = $comment->visibility !== 'employee';
        });
        static::updating(fn () => throw new \RuntimeException('Case comments are append-only.'));
        static::deleting(fn () => throw new \RuntimeException('Case comments are append-only.'));
    }

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'attachment_size' => 'integer'];
    }

    public function auditLabel(): string
    {
        return 'Ticket #'.$this->ticket_id.' comment #'.$this->getKey().($this->attachment_name ? ' ('.$this->attachment_name.')' : '');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isEmployeeVisible(): bool
    {
        return $this->visibility === 'employee';
    }
}
