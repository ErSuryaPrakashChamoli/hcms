<?php

namespace App\Domain\Website\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Website\Enums\EnquiryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "talk to us" submission from the public website (Markedge's prospect record, never a tenant's data). The
 * visitor's address is kept only as a salted hash, for spotting repeated abuse.
 */
#[Fillable(['name', 'email', 'company', 'phone', 'phone_verified_at', 'team_size', 'interest', 'message', 'source_path', 'status', 'ip_hash', 'handled_by', 'handled_at'])]
class WebsiteEnquiry extends Model
{
    protected function casts(): array
    {
        return ['status' => EnquiryStatus::class, 'handled_at' => 'datetime', 'phone_verified_at' => 'datetime'];
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
