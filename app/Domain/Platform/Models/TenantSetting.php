<?php

namespace App\Domain\Platform\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'key', 'value'])]
class TenantSetting extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function auditModule(): string
    {
        return 'platform';
    }

    public function auditLabel(): string
    {
        return $this->key;
    }
}
