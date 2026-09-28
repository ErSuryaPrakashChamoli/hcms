<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A competency (§35) with optional behavioural indicators. */
#[Fillable(['tenant_id', 'name', 'code', 'category', 'level', 'weight', 'description', 'indicators', 'status', 'effective_from', 'effective_to'])]
class Competency extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $c) {
            $c->code = strtoupper(trim((string) $c->code));
            if ($c->weight !== null && (float) $c->weight < 0) {
                throw new \RuntimeException('A competency weight cannot be negative.');
            }
            if ($c->effective_from && $c->effective_to && $c->effective_to->lt($c->effective_from)) {
                throw new \RuntimeException('A competency cannot stop being effective before it starts.');
            }
        });
    }

    protected function casts(): array
    {
        return ['indicators' => 'array', 'status' => ActiveStatus::class, 'weight' => 'decimal:2', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
