<?php

namespace App\Domain\Communication\Models;

use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read and acknowledgement of one announcement by one employee (unique per pair). Phase 13: acknowledgement is locked and recorded once. */
#[Fillable(['tenant_id', 'announcement_id', 'employee_id', 'read_at', 'acknowledged_at', 'source'])]
class AnnouncementRead extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
