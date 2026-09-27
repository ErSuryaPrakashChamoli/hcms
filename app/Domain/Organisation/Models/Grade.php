<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\GradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(GradeFactory::class)]
#[Fillable(['tenant_id', 'name', 'code', 'level_id', 'rank', 'description', 'status', 'effective_from', 'effective_to', 'metadata'])]
class Grade extends Model
{
    /** @use HasFactory<GradeFactory> */
    use Auditable, BelongsToTenant, HasEffectiveDates, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'metadata' => 'array',
            'rank' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'level_id');
    }
}
