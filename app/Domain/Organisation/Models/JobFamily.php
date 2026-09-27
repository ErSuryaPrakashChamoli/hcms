<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\JobFamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(JobFamilyFactory::class)]
#[Fillable(['tenant_id', 'name', 'code', 'description', 'status', 'metadata'])]
class JobFamily extends Model
{
    /** @use HasFactory<JobFamilyFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'metadata' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
