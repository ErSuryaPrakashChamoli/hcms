<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SkillFactory::class)]
#[Fillable(['tenant_id', 'name', 'code', 'category', 'status'])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
