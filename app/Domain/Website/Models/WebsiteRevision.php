<?php

namespace App\Domain\Website\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Website\Enums\RevisionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One whole-site copy of the public website's content (platform configuration; App\Domain\Website\Services\Website).
 * Content is the full site as one document (site, announcement, header menus, pages, footer, sign-in, search), so a
 * publish switches everything visitors see at once and a restore brings back a consistent site.
 */
#[Fillable(['status', 'content', 'note', 'created_by', 'updated_by', 'published_by', 'published_at'])]
class WebsiteRevision extends Model
{
    protected function casts(): array
    {
        return ['status' => RevisionStatus::class, 'content' => 'array', 'published_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
