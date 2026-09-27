<?php

namespace App\Domain\Grievance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Grievance type (§49): confidentiality, anonymity, which roles handle it (e.g. the Internal Committee for PoSH), SLA. */
#[Fillable(['tenant_id', 'name', 'code', 'description', 'is_confidential', 'allow_anonymous', 'handler_role_ids', 'sla_days', 'status'])]
class GrievanceCategory extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->code = strtoupper(trim((string) $c->code)));
    }

    protected function casts(): array
    {
        return ['is_confidential' => 'boolean', 'allow_anonymous' => 'boolean', 'handler_role_ids' => 'array', 'sla_days' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'grievance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
