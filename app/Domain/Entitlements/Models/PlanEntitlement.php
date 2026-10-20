<?php

namespace App\Domain\Entitlements\Models;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Enums\Capability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.4: what one plan version says about one capability of the code-owned catalogue. The capability's identity,
 * type, module and protection stay in the Capability enum; only the plan's value is stored here:
 * - a module or feature: value_bool true (included) or false (explicitly excluded; never for a protected one);
 * - a limit: value_int (null = unlimited).
 * A capability without a row is not in the plan. Rows change only while the version is a draft.
 */
#[Fillable(['plan_version_id', 'capability', 'value_bool', 'value_int'])]
class PlanEntitlement extends Model
{
    protected static function booted(): void
    {
        $guard = function (self $row): void {
            $status = PlanVersion::query()->whereKey($row->plan_version_id)->value('status'); // cast to VersionStatus
            if ($status !== VersionStatus::Draft) {
                throw new RuntimeException('A published plan version is immutable: create a new draft version.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['capability' => Capability::class, 'value_bool' => 'boolean', 'value_int' => 'integer'];
    }

    /** @return BelongsTo<PlanVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }

    /** The value as the services take it: bool for modules and features, int or null (unlimited) for limits. */
    public function value(): bool|int|null
    {
        return $this->value_bool ?? $this->value_int;
    }
}
