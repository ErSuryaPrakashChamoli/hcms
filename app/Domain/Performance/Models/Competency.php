<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A competency (§35) with optional behavioural indicators. */
#[Fillable(['tenant_id', 'name', 'code', 'category', 'description', 'indicators', 'status'])]
class Competency extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->code = strtoupper(trim((string) $c->code)));
    }

    protected function casts(): array
    {
        return ['indicators' => 'array', 'status' => ActiveStatus::class];
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
