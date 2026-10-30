<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** HROne setup parity CA-3 (Employee → Qualification): the qualifications an employee's education entries pick from. */
#[Fillable(['tenant_id', 'name', 'level', 'status', 'sort_order'])]
class Qualification extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'sort_order' => 0];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'people';
    }

    public function auditLabel(): string
    {
        return 'Qualification '.$this->name;
    }

    public function specialisations(): HasMany
    {
        return $this->hasMany(QualificationSpecialisation::class);
    }
}
