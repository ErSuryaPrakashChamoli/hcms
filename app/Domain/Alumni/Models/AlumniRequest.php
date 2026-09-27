<?php

namespace App\Domain\Alumni\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Authenticate → Request → Verify → Approval → Generate → Deliver → Audit (§62). */
#[Fillable(['tenant_id', 'number', 'alumni_profile_id', 'type', 'details', 'status', 'handled_by', 'letter_id', 'response', 'delivered_at'])]
class AlumniRequest extends Model
{
    use Auditable, BelongsToTenant;

    public const OPEN = ['submitted', 'verified', 'approved', 'generated'];

    protected $attributes = ['status' => 'submitted'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'alumni';
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AlumniProfile::class, 'alumni_profile_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
